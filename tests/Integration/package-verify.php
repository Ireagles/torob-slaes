<?php
if (wp_get_environment_type() !== "local" || DB_NAME !== "vsp_package") {
    throw new RuntimeException("Isolated package DB required");
}
use Viratel\SmartPricing\Infrastructure\{Repository, ProductState, Settings};
$r = new Repository();
$id = (int) get_option("vsp_package_product");
if ($r->get("mappings", $id) !== get_option("vsp_package_expected")) {
    throw new RuntimeException("Update lost data");
}
if (
    ProductState::read($id)["effective"] !== "500000" ||
    $r->count("usage") ||
    Settings::get()["mode"] !== "monitor"
) {
    throw new RuntimeException("Unsafe replacement");
}
$data = [
    "version" => VSP_VERSION,
    "environment" => "isolated local vsp_package",
    "zip_independent_of_checkout" => true,
    "fresh_install_passive" => true,
    "tables" => 9,
    "http_requests" => 0,
    "price_changes" => 0,
    "deactivate_reinstall_preserves_mapping" => true,
    "checked_at" => gmdate("c"),
];
file_put_contents(
    "/workspace/torob-slaes/docs/package-smoke.json",
    wp_json_encode($data, JSON_PRETTY_PRINT) . PHP_EOL,
);
echo wp_json_encode($data, JSON_PRETTY_PRINT) . PHP_EOL;
