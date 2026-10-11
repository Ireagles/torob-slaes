#!/usr/bin/env python3
"""Build install/source archives without runtime data, secrets or test fixtures in install ZIP."""
from pathlib import Path
import hashlib
import re
import zipfile

ROOT = Path(__file__).resolve().parent.parent
DIST = ROOT / "dist"
VERSION = re.search(r"Version:\s*([0-9.]+)", (ROOT / "viratel-smart-pricing/viratel-smart-pricing.php").read_text()).group(1)
STAMP = (2026, 10, 11, 0, 0, 0)


def archive(path, entries):
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for name, file in sorted(entries):
            info = zipfile.ZipInfo(name, STAMP)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            z.writestr(info, file.read_bytes())
    with zipfile.ZipFile(path) as z:
        assert z.testzip() is None
        assert all(not n.startswith("/") and ".." not in Path(n).parts for n in z.namelist())


DIST.mkdir(exist_ok=True)
install = DIST / f"viratel-smart-pricing-{VERSION}.zip"
source = DIST / f"viratel-smart-pricing-source-{VERSION}.zip"
plugin_files = [p for p in (ROOT / "viratel-smart-pricing").rglob("*") if p.is_file()]
docs = [p for p in (ROOT / "docs").rglob("*") if p.is_file()]
archive(install, [(p.relative_to(ROOT).as_posix(), p) for p in plugin_files] + [("viratel-smart-pricing/docs/" + p.relative_to(ROOT / "docs").as_posix(), p) for p in docs])
entries = []
for folder in ("viratel-smart-pricing", "docs", "tests", "tools", "previews"):
    for p in (ROOT / folder).rglob("*"):
        if p.is_file() and "results" not in p.relative_to(ROOT).parts and "__pycache__" not in p.parts:
            entries.append(("viratel-smart-pricing-source/" + p.relative_to(ROOT).as_posix(), p))
for name in ("README.md", ".gitignore", "LICENSE"):
    entries.append(("viratel-smart-pricing-source/" + name, ROOT / name))
archive(source, entries)
with zipfile.ZipFile(install) as z:
    assert all(n.startswith("viratel-smart-pricing/") for n in z.namelist())
    assert not any("/tests/" in n or "/tools/" in n or "credentials" in n for n in z.namelist())
    assert "viratel-smart-pricing/viratel-smart-pricing.php" in z.namelist()
sums = "".join(hashlib.sha256(p.read_bytes()).hexdigest() + "  " + p.name + "\n" for p in (install, source))
(DIST / "SHA256SUMS").write_text(sums)
print(sums, end="")
