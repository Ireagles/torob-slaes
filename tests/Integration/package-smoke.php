<?php
if (wp_get_environment_type() !== "local" || DB_NAME !== "vsp_package") {
    throw new RuntimeException("Isolated package DB required");
}
use Viratel\SmartPricing\Infrastructure\{
    Repository,
    Settings,
    MappingService,
    ProductState,
};
$repo = new Repository();
$s = Settings::get();
if (
    $s["mode"] !== "monitor" ||
    $s["source_configured"] ||
    $repo->count("usage")
) {
    throw new RuntimeException("Unsafe default");
}
if (is_link(dirname(VSP_FILE)) || strpos(VSP_FILE, "package-site") === false) {
    throw new RuntimeException("ZIP not installed independently");
}
global $wpdb;
$tables = $wpdb->get_col(
    $wpdb->prepare(
        "SHOW TABLES LIKE %s",
        $wpdb->esc_like($wpdb->prefix . "vsp_") . "%",
    ),
);
if (count($tables) !== 9) {
    throw new RuntimeException("Schema incomplete");
}
$id = (int) get_option("vsp_package_product", 0);
if (!$id) {
    $p = new WC_Product_Simple();
    $p->set_name("LOCAL ZIP TEST");
    $p->set_regular_price("500000");
    $p->set_stock_status("instock");
    $p->save();
    $id = $p->get_id();
    update_option("vsp_package_product", $id, false);
}
(new MappingService())->save(
    $id,
    [
        "source_url" =>
            "https://torob.com/p/1189b812-9de1-4016-b72d-fa7d3292ff9b/battery/",
        "attributes" => ["model" => "Mi10T", "quality" => "OEM"],
        "match_status" => "pending",
        "mode" => "monitor",
        "schedule_group" => "normal",
    ],
    [
        "currency" => "IRT",
        "floor" => "450000",
        "ceiling" => "600000",
        "type" => "fixed_below",
        "delta" => "1000",
        "round_step" => "1",
    ],
);
update_option("vsp_package_expected", $repo->get("mappings", $id), false);
if (ProductState::read($id)["effective"] !== "500000") {
    throw new RuntimeException("Price changed");
}
echo "PASS fresh installed ZIP: passive monitoring, 9 tables, no HTTP, no price mutation\n";
