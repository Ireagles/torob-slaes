# Viratel Smart Pricing

## دریافت افزونه

- [دانلود ZIP قابل‌نصب 0.2.0](https://github.com/Ireagles/torob-slaes/raw/refs/heads/main/downloads/viratel-smart-pricing-0.2.0.zip)
- [دانلود کد، آزمون‌ها و مستندات](https://github.com/Ireagles/torob-slaes/raw/refs/heads/main/downloads/viratel-smart-pricing-source-0.2.0.zip)
- [SHA256 فایل‌ها](downloads/SHA256SUMS)

برای نصب در WordPress، فایل **viratel-smart-pricing-0.2.0.zip** را در «افزونه‌ها ← افزودن ← بارگذاری افزونه» انتخاب کنید. ZIP کل مخزن و source ZIP، بسته نصب WordPress نیستند. ابتدا روی staging نصب کنید و [راهنمای فارسی](docs/guide-fa.md) را بخوانید.


افزونه آزمایشی WordPress/WooCommerce مطابق بریف فارسی. نصب اولیه: **پایش**؛ دریافت زمان‌بندی‌شده پیشفرض خاموش. هیچ سایت یا قیمت production تغییر نکرده است.

- [راهنمای سه صفحه جدید و تصاویر](docs/admin-ui-fa.md)
- [وضعیت اجرا و معیارهای پذیرش](docs/implementation-status.md)
- [راهنمای فارسی](docs/guide-fa.md)
- [دریافت واقعی ترب و موانع](docs/data-feasibility.md)
- [Architecture](docs/architecture.md) · [Schema](docs/schema.md)
- [گزارش آزمون](docs/test-report.md) · [Benchmark](docs/benchmark.md)
- [CSV نمونه](docs/sample-products.csv) · [Cron/CLI](docs/cron.md) · [Rollback](docs/rollback.md)

Source: `viratel-smart-pricing/`. Development tests and fixtures: `tests/` (excluded from install ZIP). Tests use marked fixtures only in an isolated local WordPress environment. Public Torob JSON-LD is real monitoring data but does not establish freshness, per-offer quality or self identity, so automatic application is blocked.

```sh
bash tools/setup-runtime.sh
bash tools/start-runtime.sh
/workspace/vsp-runtime/bin/php tests/Unit/run.php
/workspace/vsp-runtime/bin/wp eval-file tests/Integration/run.php
python3 tools/build.py
```

The cloud task already has an isolated checkout. Do not create a Git worktree unless explicitly requested. No commercial plugin code is copied; no private API, license service or CAPTCHA bypass is used. Compatibility is only evidenced for the versions in the test report. P1/P2 and a real multi-day pilot are separately pending.
