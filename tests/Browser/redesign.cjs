// LOCAL ONLY: modifies isolated fixture products and restores the pricing template.
const { chromium } = require("playwright");
const fs = require("fs");
const { execFileSync } = require("child_process");
const wp = "/workspace/vsp-runtime/bin/wp";
function readWP(code) {
  const raw = execFileSync(wp, ["eval", code], { encoding: "utf8" });
  return JSON.parse(raw.slice(raw.indexOf("{")));
}
(async () => {
  const credentials = JSON.parse(
    fs.readFileSync("/workspace/vsp-runtime/ui-credentials.json", "utf8"),
  );
  readWP(
    'if(wp_get_environment_type()!=="local"){throw new RuntimeException("Local environment required");}echo wp_json_encode(["local"=>true]);',
  );
  const fixture = readWP(
    '$p=new WC_Product_Simple();$p->set_name("VSP LOCAL UI REDESIGN");$p->set_regular_price("500000");$p->set_stock_status("instock");$p->save();echo wp_json_encode(["id"=>$p->get_id()]);',
  );
  const browser = await chromium.launch({
    executablePath: "/usr/bin/chromium",
    args: ["--no-sandbox"],
  });
  const page = await browser.newPage({
    viewport: { width: 1440, height: 1100 },
  });
  const issues = [],
    checks = [],
    failedRequests = [];
  page.on("pageerror", (e) =>
    issues.push({ type: "runtime", message: e.message }),
  );
  page.on("console", (m) => {
    if (m.type() === "error")
      issues.push({
        type: "console",
        message: m.text(),
        url: m.location().url,
      });
  });
  page.on("requestfailed", (r) =>
    failedRequests.push({ url: r.url(), error: r.failure()?.errorText }),
  );
  const ok = (v, name) => {
    if (!v) throw Error(name);
    checks.push({ name, passed: true });
    console.log("PASS " + name);
  };
  await page.goto("http://127.0.0.1:8090/wp-login.php");
  await page.locator("#user_login").fill(credentials.username);
  await page.locator("#user_pass").fill(credentials.password);
  await page.locator("#wp-submit").click();
  await page.waitForURL("**/wp-admin/**");
  const base =
    "http://127.0.0.1:8090/wp-admin/admin.php?page=viratel-smart-pricing";
  await page.goto(base + "&tab=settings");
  ok((await page.title()).includes("ویراتل"), "settings identity");
  ok((await page.locator(".vsp-card").count()) === 4, "settings grouped cards");
  const sf = page
    .locator("form")
    .filter({ has: page.locator("input[name=operation][value=settings]") });
  await sf.locator("input[name=panel_title]").fill("قیمت‌گذاری هوشمند ویراتل");
  await sf.locator("input[name=accent_color]").fill("#7357e8");
  await sf
    .getByRole("button", { name: "ذخیره تنظیمات عمومی", exact: true })
    .click();
  await page.waitForURL((u) => u.searchParams.get("result") === "ok");
  ok(
    new URL(page.url()).searchParams.get("tab") === "settings",
    "settings saves and stays on same tab",
  );
  await page
    .locator(".vsp-wrap")
    .screenshot({ path: "/workspace/scratch/vsp-general-desktop.png" });
  await page.goto(base + "&tab=rules");
  const rf = page
    .locator("form")
    .filter({ has: page.locator("input[name=operation][value=defaults]") });
  const restore = await rf.evaluate((el) =>
    Object.fromEntries(new FormData(el)),
  );
  await rf.locator("input[name=delta]").fill("1200");
  await rf.locator("select[name=basis]").selectOption("mean");
  await rf
    .getByRole("button", { name: "ذخیره قالب عمومی", exact: true })
    .click();
  await page.waitForURL((u) => u.searchParams.get("result") === "ok");
  ok(
    (await page.locator("input[name=delta]").inputValue()) === "1200",
    "pricing template saved",
  );
  ok(
    (await page.locator("select[name=basis]").inputValue()) === "mean",
    "pricing reference saved",
  );
  await page
    .locator(".vsp-wrap")
    .screenshot({ path: "/workspace/scratch/vsp-pricing-desktop.png" });
  const bad = await page.request.post(
    "http://127.0.0.1:8090/wp-admin/admin-post.php",
    { form: { ...restore, floor: "600000", ceiling: "450000" } },
  );
  ok(
    new URL(bad.url()).searchParams.get("result") === "invalid_bounds",
    "invalid pricing defaults rejected",
  );
  const restored = await page.request.post(
    "http://127.0.0.1:8090/wp-admin/admin-post.php",
    { form: restore },
  );
  ok(
    new URL(restored.url()).searchParams.get("result") === "ok",
    "original defaults restored",
  );
  await page.goto(
    "http://127.0.0.1:8090/wp-admin/post.php?post=" +
      fixture.id +
      "&action=edit",
  );
  await page.locator('a[href="#vsp_product_data"]').click();
  const panel = page.locator("#vsp_product_data");
  ok(await panel.isVisible(), "native Woo product tab opens");
  ok(
    (await panel.locator('input[name="vsp[delta]"]').inputValue()) ===
      restore.delta,
    "new product starts from template",
  );
  await panel.locator('select[name="vsp[enabled]"]').selectOption("1");
  await panel
    .locator('input[name="vsp[source_url]"]')
    .fill("https://torob.com/p/1189b812-9de1-4016-b72d-fa7d3292ff9b/battery/");
  await panel.locator('input[name="vsp[model]"]').fill("Mi10T");
  await panel.locator('input[name="vsp[quality]"]').fill("OEM");
  await panel
    .locator('select[name="vsp[match_status]"]')
    .selectOption("confirmed");
  await panel.locator('input[name="vsp[floor]"]').fill("450000");
  await panel.locator('input[name="vsp[ceiling]"]').fill("600000");
  await panel.locator('select[name="vsp[mode]"]').selectOption("monitor");
  await Promise.all([
    page.waitForNavigation(),
    page.locator("#publish").click(),
  ]);
  await page.locator('a[href="#vsp_product_data"]').click();
  ok(
    (await panel.locator('input[name="vsp[floor]"]').inputValue()) === "450000",
    "native product form persists floor",
  );
  const state = readWP(
    "$id=" +
      fixture.id +
      ';$r=new Viratel\\SmartPricing\\Infrastructure\\Repository();echo wp_json_encode(["price"=>wc_get_product($id)->get_price(),"mapping"=>$r->get("mappings",$id)["data"]]);',
  );
  ok(
    state.price === "500000" && state.mapping.mode === "monitor",
    "native product save leaves price unchanged",
  );
  await page
    .locator(".vsp-product-panel")
    .screenshot({ path: "/workspace/scratch/vsp-product-desktop.png" });
  for (const tab of ["settings", "rules"]) {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(base + "&tab=" + tab);
    ok(
      await page
        .locator(".vsp-wrap")
        .evaluate((e) => e.scrollWidth <= e.clientWidth + 2),
      tab + " mobile no horizontal overflow",
    );
    await page
      .locator(".vsp-wrap")
      .screenshot({ path: "/workspace/scratch/vsp-" + tab + "-mobile.png" });
  }
  await page.goto(
    "http://127.0.0.1:8090/wp-admin/post.php?post=" +
      fixture.id +
      "&action=edit",
  );
  await page.locator('a[href="#vsp_product_data"]').click();
  ok(
    await page
      .locator(".vsp-product-panel")
      .evaluate((e) => e.scrollWidth <= e.clientWidth + 2),
    "product panel mobile no overflow",
  );
  await page
    .locator(".vsp-product-panel")
    .screenshot({ path: "/workspace/scratch/vsp-product-mobile.png" });
  console.log("NETWORK FAILURES", JSON.stringify(failedRequests));
  const localFailures = failedRequests.filter(
    (r) => new URL(r.url).hostname === "127.0.0.1",
  );
  ok(localFailures.length === 0, "no local resource failures");
  ok(
    issues.every(
      (i) =>
        i.type === "console" &&
        i.message.includes("ERR_TUNNEL_CONNECTION_FAILED"),
    ) && failedRequests.every((r) => new URL(r.url).hostname !== "127.0.0.1"),
    "no JavaScript runtime or local console errors",
  );
  await page.goto("http://127.0.0.1:8090/");
  ok(
    (await page.locator('link[href*="viratel-smart-pricing"]').count()) === 0,
    "admin CSS absent from storefront",
  );
  const out = {
    browser: "Playwright Chromium",
    fallback: "Browser plugin not available",
    viewports: ["1440x1100", "390x844"],
    product_id: fixture.id,
    checks,
    issues,
    failedRequests,
  };
  fs.writeFileSync(
    "/workspace/torob-slaes/tests/results/redesign-ui.json",
    JSON.stringify(out, null, 2),
  );
  await browser.close();
  console.log("PASS checks: " + checks.length);
})().catch((e) => {
  console.error(e.stack);
  process.exit(1);
});
