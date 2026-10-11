<?php
require dirname(__DIR__) . "/bootstrap.php";
use Viratel\SmartPricing\Domain\{Money, PricingEngine, RuleResolver};
use Viratel\SmartPricing\Security\SourceUrl;
use Viratel\SmartPricing\ImportExport\CsvService;
use Viratel\SmartPricing\Providers\TorobProvider;
$now = strtotime("2026-10-10 12:00:00 UTC");
$offer = function ($price, $id = "a", $currency = "IRT") use ($now) {
    return [
        "offer_id" => $id,
        "store_id" => $id,
        "seller_url" => "https://seller-" . $id . ".example",
        "price" => (string) $price,
        "currency" => $currency,
        "in_stock" => true,
        "observed_at" => gmdate("c", $now),
        "attributes" => ["model" => "Mi10T", "quality" => "OEM"],
    ];
};
$snapshot = [
    "status" => "ok",
    "freshness" => "known",
    "source_observed_at" => gmdate("c", $now),
    "coverage" => "complete",
    "offers" => [$offer("500000")],
];
$mapping = [
    "match_status" => "confirmed",
    "attributes" => ["model" => "Mi10T", "quality" => "OEM"],
];
$rule = [
    "currency" => "IRT",
    "floor" => "450000",
    "ceiling" => "600000",
    "type" => "fixed_below",
    "delta" => "1000",
    "round_step" => "1",
    "min_competitors" => 1,
    "max_change_bps" => 10000,
    "cooldown" => 0,
];
$ctx = [
    "own_domains" => ["viratel.example"],
    "current" => "499000",
    "currency" => "IRT",
];
$e = new PricingEngine();
$calc = function ($s = null, $m = null, $r = null, $c = null) use (
    $e,
    $snapshot,
    $mapping,
    $rule,
    $ctx,
    $now,
) {
    return $e->calculate(
        $s ?? $snapshot,
        $m ?? $mapping,
        $r ?? $rule,
        $c ?? $ctx,
        $now,
    );
};
check(Money::parse("۵۰۰٬۰۰۰", "IRT") === 500000000, "Persian thousands");
check(Money::parse("٥٠٠٫١", "IRT") === 500100, "Arabic decimals");
check(
    Money::parse("500000", "IRT") === Money::parse("5000000", "IRR"),
    "exact IRT to IRR",
);
check(
    Money::format(Money::parse("500.125", "IRT"), "IRT") === "500.125",
    "exact decimal roundtrip",
);
check(Money::format(0, "IRT") === "0", "zero format");
foreach (["5,00", "1e6", "-1", "1 000", "1,000.0001"] as $bad) {
    raises(fn() => Money::parse($bad, "IRT"), "", "reject " . $bad);
}
raises(fn() => Money::parse(0.1, "IRT"), "float", "float rejected");
raises(fn() => Money::parse("1", "USD"), "currency", "unknown currency");
raises(
    fn() => Money::parse("999999999999999999", "IRT"),
    "overflow",
    "overflow",
);
foreach (
    [["500000", "499000"], ["440000", "450000"], ["620000", "600000"]]
    as [$price, $expected]
) {
    $s = $snapshot;
    $s["offers"] = [$offer($price)];
    $c = $ctx;
    $c["current"] = "480000";
    $d = $calc($s, null, null, $c);
    check(
        Money::format($d["amount"], "IRT") === $expected,
        "brief price " . $price,
    );
}
$s = $snapshot;
$s["offers"] = [$offer("540000")];
check(
    Money::format($calc($s)["amount"], "IRT") === "539000",
    "price rises with market",
);
$s = $snapshot;
$s["offers"] = [];
check($calc($s)["decision"] === "no-op", "no competitors retains price");
$s = $snapshot;
$s["offers"] = [$offer("400000", "own"), $offer("540000")];
$s["offers"][0]["seller_url"] = "https://viratel.example/p";
$d = $calc($s);
check(
    count($d["selected"]) === 1 && $d["exclusions"][0]["reason"] === "self",
    "own exact domain exclusion",
);
$s = $snapshot;
$s["offers"][0]["seller_url"] = "https://notviratel.example";
check(count($calc($s)["selected"]) === 1, "domain substring does not exclude");
$s = $snapshot;
$s["offers"][0]["seller_url"] = "";
$s["offers"][0]["store_id"] = "own";
$c = $ctx;
$c["own_store_ids"] = ["own"];
check($calc($s, null, null, $c)["decision"] === "no-op", "self by ID");
$s = $snapshot;
$s["offers"][0]["seller_url"] = "";
$s["offers"][0]["store_id"] = "";
check(
    in_array("identity_ambiguous", $calc($s)["reasons"]),
    "unknown identity blocks",
);
$s = $snapshot;
$s["offers"][0]["attributes"]["quality"] = "copy";
check(
    $calc($s)["exclusions"][0]["reason"] === "attribute_mismatch_quality",
    "quality mismatch",
);
$s = $snapshot;
$s["offers"][0]["attributes"] = [];
check($calc($s)["decision"] === "no-op", "unknown quality excluded");
$s = $snapshot;
$s["offers"][0]["in_stock"] = null;
check($calc($s)["decision"] === "no-op", "stock unknown excluded");
$s = $snapshot;
$s["offers"][0]["observed_at"] = null;
check(
    $calc($s)["exclusions"][0]["reason"] === "offer_time_unknown_or_stale",
    "offer age unknown",
);
foreach (
    [
        ["freshness", "unknown"],
        ["source_observed_at", gmdate("c", $now - 30000)],
        ["source_observed_at", gmdate("c", $now + 1000)],
        ["coverage", "partial"],
        ["status", "http_403"],
    ]
    as [$key, $value]
) {
    $s = $snapshot;
    $s[$key] = $value;
    check(
        $calc($s)["decision"] === "hold",
        "invalid snapshot " . $key . " " . $value,
    );
}
$s = $snapshot;
$s["offers"][0]["price"] = "0";
check($calc($s)["decision"] === "no-op", "zero offer");
$s["offers"][0]["currency"] = "USD";
check($calc($s)["decision"] === "no-op", "unknown offer unit");
$m = $mapping;
$m["match_status"] = "pending";
check($calc(null, $m)["decision"] === "hold", "unconfirmed mapping");
$r = $rule;
$r["floor"] = "610000";
check(
    in_array("invalid_bounds", $calc(null, null, $r)["reasons"]),
    "floor above ceiling",
);
$r = $rule;
$r["cost"] = "400000";
$r["cost_observed_at"] = $now;
$r["margin_bps"] = 2000;
$r["ceiling"] = "800000";
check(
    $calc(null, null, $r)["limits"]["floor"] === 500000000,
    "margin floor C/(1-m)",
);
$r["margin_bps"] = 0;
$r["markup_bps"] = 2000;
check(
    $calc(null, null, $r)["limits"]["floor"] === 480000000,
    "markup floor C*(1+r)",
);
$r["fee_bps"] = 1000;
check(
    $calc(null, null, $r)["limits"]["floor"] === 533333334,
    "fee charged on sale is included with cost markup",
);
$r["cost_observed_at"] = $now + 3600;
check(
    in_array("cost_stale", $calc(null, null, $r)["reasons"]),
    "future cost time blocks",
);
$r["cost_observed_at"] = $now - 100000;
check(
    in_array("cost_stale", $calc(null, null, $r)["reasons"]),
    "stale cost blocks",
);
$r = $rule;
$r["floor"] = "450001";
$r["ceiling"] = "450999";
$r["round_step"] = "1000";
check(
    in_array("no_rounded_amount", $calc(null, null, $r)["reasons"]),
    "rounding impossible",
);
$r = $rule;
$r["type"] = "percent_below";
$r["delta_bps"] = 100;
check(
    Money::format($calc(null, null, $r)["amount"], "IRT") === "495000",
    "percentage exact",
);
$r = $rule;
$r["type"] = "fixed_above";
$r["delta"] = "1250";
$r["round_step"] = "1000";
check(
    Money::format($calc(null, null, $r)["amount"], "IRT") === "502000",
    "above rounds upward",
);
$r = $rule;
$r["max_change_bps"] = 10;
$s = $snapshot;
$s["offers"] = [$offer("540000")];
check(in_array("change_limit", $calc($s, null, $r)["reasons"]), "jump review");
$r = $rule;
$r["max_daily_change"] = "2000";
$c = $ctx;
$c["daily_change"] = 2000000;
$c["current"] = "490000";
check(
    in_array("daily_change_limit", $calc(null, null, $r, $c)["reasons"]),
    "daily cumulative prevents split bypass",
);
$m = $mapping;
$m["manual_lock_until"] = $now + 100;
check(in_array("manual_lock", $calc(null, $m)["reasons"]), "manual lock");
$c = $ctx;
$c["paused"] = true;
check(in_array("paused", $calc(null, null, null, $c)["reasons"]), "paused");
$c = $ctx;
$c["sale_conflict"] = true;
$c["current"] = "480000";
check(
    in_array("sale_conflict", $calc(null, null, null, $c)["reasons"]),
    "sale blocked",
);
$c = $ctx;
$c["current"] = "480000";
$c["last_applied_at"] = $now - 10;
$r = $rule;
$r["cooldown"] = 60;
check(in_array("cooldown", $calc(null, null, $r, $c)["reasons"]), "cooldown");
$r = $rule;
$r["min_competitors"] = 2;
$c = $ctx;
$c["current"] = "480000";
check(
    in_array("single_competitor", $calc(null, null, $r, $c)["reasons"]),
    "single competitor review",
);
$s = $snapshot;
$s["offers"] = [
    $offer("100000", "a"),
    $offer("500000", "b"),
    $offer("510000", "c"),
];
check(
    in_array("outlier_review", $calc($s, null, null, $c)["reasons"]),
    "outlier flagged",
);
$r = $rule;
$r["whitelist"] = ["b"];
check(
    $calc(null, null, $r)["exclusions"][0]["reason"] === "not_whitelisted",
    "whitelist",
);
$r = $rule;
$r["blacklist"] = ["a"];
check(
    $calc(null, null, $r)["exclusions"][0]["reason"] === "blacklisted",
    "blacklist",
);
$r = $rule;
$r["require_guarantee"] = true;
check(
    $calc(null, null, $r)["exclusions"][0]["reason"] === "guarantee_unknown",
    "guarantee unknown",
);
$rr = new RuleResolver();
check(
    $rr->resolve([
        ["scope" => "default", "version" => "1"],
        ["scope" => "product", "version" => "2"],
    ])["rule"]["version"] === "2",
    "resolver precedence",
);
check(
    $rr->resolve([
        ["scope" => "category", "priority" => 2, "version" => "a"],
        ["scope" => "category", "priority" => 2, "version" => "b"],
    ])["reason"] === "rule_conflict",
    "resolver tie conflict",
);
$url = "https://torob.com/p/1189b812-9de1-4016-b72d-fa7d3292ff9b/battery/";
check(
    SourceUrl::validate($url) === "1189b812-9de1-4016-b72d-fa7d3292ff9b",
    "canonical product",
);
foreach (
    [
        "http://torob.com/p/a",
        "https://torob.com.evil.example/p/a",
        "https://127.0.0.1/p/a",
        "https://169.254.169.254/latest",
        "https://user@torob.com/p/a",
        "https://torob.com:444/p/a",
        "https://torob.com/search/",
        "https://torob.com/p/a?redirect=http://localhost",
        "https://[::1]/p/a",
    ]
    as $bad
) {
    raises(fn() => SourceUrl::validate($bad), "", "SSRF reject " . $bad);
}
foreach (["=1+1", " +SUM(A1)", "@x", "-2"] as $cell) {
    check(
        CsvService::escapeCell($cell)[0] === "'",
        "CSV formula " . trim($cell),
    );
}
check(CsvService::escapeCell("sku-1") === "sku-1", "safe CSV unchanged");
$base = [
    "canonical_product_id" => "1189b812-9de1-4016-b72d-fa7d3292ff9b",
    "offers" => [],
    "status" => "ok",
];
$parser = new TorobProvider();
$html = file_get_contents(
    dirname(__DIR__) . "/Fixtures/torob-public-jsonld.html",
);
$d = $parser->parse($html, $base);
check(
    $d["status"] === "ok" && count($d["offers"]) === 6,
    "recorded live HTML parser",
);
check(
    $d["offers"][0]["observed_at"] === null &&
        $d["offers"][0]["attributes"] === [],
    "parser never invents freshness or quality",
);
check(strpos(json_encode($d), "session_id") === false, "tracking omitted");
check(
    $parser->parse("<html>login</html>", $base)["status"] ===
        "parser_schema_error",
    "HTTP200 invalid schema",
);
check(
    $parser->parse("<title>Captcha</title>", $base)["status"] ===
        "challenge_or_login",
    "challenge detected",
);
$s = $snapshot;
$s["source_observed_at"] = "now";
check(
    in_array("stale_or_unknown", $calc($s)["reasons"]),
    "relative observation timestamp rejected",
);
$s = $snapshot;
$s["source_observed_at"] = "2026-02-31T12:00:00Z";
check(
    in_array("stale_or_unknown", $calc($s)["reasons"]),
    "invalid calendar date rejected",
);
$s = $snapshot;
$s["offers"][0]["seller_url"] = "";
check(
    in_array("identity_ambiguous", $calc($s)["reasons"]),
    "store ID cannot identify self with only own domain configured",
);
$r = $rule;
$r["basis"] = "mean";
$s = $snapshot;
$s["offers"] = [$offer("500000", "a"), $offer("520000", "b")];
check(
    Money::format($calc($s, null, $r)["amount"], "IRT") === "509000",
    "mean reference",
);
$r["basis"] = "max";
check(
    Money::format($calc($s, null, $r)["amount"], "IRT") === "519000",
    "maximum reference",
);
$r = $rule;
$r["cost"] = "400000";
$r["cost_observed_at"] = $now;
$r["margin_bps"] = 1000;
$r["fee_bps"] = 1000;
check(
    $calc(null, null, $r)["limits"]["floor"] === 500000000,
    "fee and margin floor",
);
$r["margin_bps"] = 9900;
$r["fee_bps"] = 200;
check(
    in_array("invalid_profit", $calc(null, null, $r)["reasons"]),
    "nonpositive profit denominator blocked",
);
// A deterministic 2,000-case property check exercises bounds and rounding across changing markets.
for ($i = 0; $i < 2000; $i++) {
    $s = $snapshot;
    $s["offers"] = [$offer((string) (300000 + $i * 300))];
    $c = $ctx;
    $c["current"] = "480000";
    $d = $calc($s, null, null, $c);
    if (
        $d["amount"] !== null &&
        ($d["amount"] < 450000000 || $d["amount"] > 600000000)
    ) {
        throw new RuntimeException("bound property failed");
    }
}
check(true, "2000 changing markets preserve bounds");
echo "Assertions: " . $GLOBALS["vsp_assertions"] . "\n";
