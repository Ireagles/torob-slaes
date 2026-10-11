<?php
if (!defined("ABSPATH") || wp_get_environment_type() !== "local") {
    throw new RuntimeException("Local only");
}
require dirname(__DIR__) . "/bootstrap.php";
use Viratel\SmartPricing\Admin\{RuleForm, ProductPanel};
use Viratel\SmartPricing\Infrastructure\{Repository, ProductState};
if (!class_exists("WC_Admin_Meta_Boxes")) {
    require_once WC_ABSPATH . "includes/admin/class-wc-admin-meta-boxes.php";
}
$repo = new Repository();
$priorDefaults = get_option("vsp_pricing_defaults", null);
$user = get_users(["role" => "administrator", "number" => 1])[0];
wp_set_current_user($user->ID);
$p = new WC_Product_Simple();
$p->set_name("LOCAL ADMIN FORM TEST");
$p->set_regular_price("500000");
$p->set_stock_status("instock");
$p->save();
$id = $p->get_id();
$before = ProductState::read($id);
$usage = $repo->count("usage");
$post = [
    "source_url" =>
        "https://torob.com/p/1189b812-9de1-4016-b72d-fa7d3292ff9b/battery/",
    "enabled" => "1",
    "model" => "Mi10T",
    "quality" => "OEM",
    "match_status" => "confirmed",
    "mode" => "monitor",
    "schedule_group" => "normal",
    "rule_currency" => "IRT",
    "floor" => "450000",
    "ceiling" => "600000",
    "rule_type" => "fixed_below",
    "basis" => "min",
    "delta" => "1000",
    "delta_percent" => "1.5",
    "round_step" => "1000",
    "max_change_percent" => "10",
    "max_daily_change" => "50000",
    "min_competitors" => "2",
    "cooldown" => "3600",
    "expected_version" => "",
    "expected_mapping_hash" => "",
];
$r = RuleForm::parse($post);
check(
    $r["delta_bps"] === 150 && $r["floor"] === "450000",
    "form percentage and money normalized exactly",
);
raises(
    fn() => RuleForm::parse(array_merge($post, ["floor" => "700000"])),
    "invalid_bounds",
    "form rejects inverted bounds",
);
raises(
    fn() => RuleForm::parse(array_merge($post, ["round_step" => "0"])),
    "invalid_round_step",
    "form rejects zero rounding",
);
raises(
    fn() => RuleForm::parse(
        array_merge($post, ["max_change_percent" => "101"]),
    ),
    "invalid_max_change_bps",
    "form rejects jump limit over 100 percent",
);
raises(
    fn() => RuleForm::parse(array_merge($post, ["rule_currency" => "USD"])),
    "currency_unknown",
    "form rejects unknown units",
);
$template = RuleForm::parse(
    array_merge($post, ["floor" => "", "ceiling" => ""]),
    [],
    true,
);
check(
    $template["floor"] === "" && $template["ceiling"] === "",
    "template may leave bounds for each product",
);
$panel = new ProductPanel();
$_POST = ["vsp_present" => "1", "vsp_product_nonce" => "bad", "vsp" => $post];
$panel->save($id);
check(
    !$repo->get("mappings", $id),
    "native product panel rejects invalid nonce",
);
$_POST["vsp_product_nonce"] = wp_create_nonce("vsp_product_" . $id);
$panel->save($id);
$m = $repo->get("mappings", $id);
check(
    $m && $m["data"]["mode"] === "monitor",
    "native product panel saves actual mapping",
);
check(
    ProductState::read($id) === $before && $repo->count("usage") === $usage,
    "native product save changes no price and performs no network request",
);
$rule = $repo->get("rules", $m["rule_version"])["data"];
check($rule["delta_bps"] === 150, "native panel uses common parser");
$_POST["vsp"]["delta"] = "9999";
$panel->save($id);
check(
    $repo->get("mappings", $id) === $m,
    "stale native editor cannot overwrite a mapping",
);
update_option(
    "vsp_pricing_defaults",
    array_merge($template, ["delta" => "1234"]),
    false,
);
check(
    RuleForm::defaults()["delta"] === "1234" &&
        $repo->get("rules", $m["rule_version"])["data"]["delta"] === "1000",
    "changing template never mutates existing product rule",
);
$old = [
    "attributes" => [
        "model" => "Mi10T",
        "quality" => "OEM",
        "warranty" => "verified",
    ],
    "mode" => "monitor",
];
check(
    ProductPanel::mapping($post, $old)["attributes"]["warranty"] === "verified",
    "UI preserves advanced configured attributes",
);
$subscriber = wp_create_user(
    "vsp-panel-subscriber-" . wp_generate_uuid4(),
    wp_generate_password(32),
);
wp_set_current_user($subscriber);
$_POST["vsp"]["expected_version"] = $m["rule_version"];
$_POST["vsp"]["expected_mapping_hash"] = hash("sha256", $m["config"]);
$panel->save($id);
check(
    $repo->get("mappings", $id) === $m,
    "native panel checks manage and edit capabilities",
);
wp_set_current_user($user->ID);
$_POST["vsp"]["enabled"] = "0";
$panel->save($id);
check(
    !$repo->get("mappings", $id)["data"]["enabled"] &&
        ProductState::read($id) === $before,
    "disabling a product preserves config and price",
);
if ($priorDefaults === null) {
    delete_option("vsp_pricing_defaults");
} else {
    update_option("vsp_pricing_defaults", $priorDefaults, false);
}
$_POST = [];
echo "Assertions: " . $GLOBALS["vsp_assertions"] . PHP_EOL;
