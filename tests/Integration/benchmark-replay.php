<?php
if (wp_get_environment_type() !== "local") {
    throw new RuntimeException("Local only");
}
use Viratel\SmartPricing\Infrastructure\{Settings, ProductState};
use Viratel\SmartPricing\ImportExport\CsvService;
global $wpdb;
$old = get_option("vsp_benchmark_metrics");
$first = (int) $old["product_first"];
$last = (int) $old["product_last"];
$s = Settings::get();
$s["mode"] = "monitor";
$s["paused"] = false;
$s["currency"] = "IRT";
$s["source_configured"] = false;
$s["own_domains"] = ["viratel.example"];
update_option("vsp_settings", $s, false);
$baseline = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT SUM(data_length+index_length) FROM information_schema.tables WHERE table_schema=%s AND table_name LIKE %s",
        DB_NAME,
        $wpdb->esc_like($wpdb->prefix . "vsp_") . "%",
    ),
);
$f = fopen("php://temp", "r+");
fputcsv($f, CsvService::HEADERS, ",", '"', "");
for ($id = $first; $id <= $last; $id++) {
    $p = wc_get_product($id);
    $cells = [
        "product_id" => $id,
        "sku" => $p->get_sku(),
        "provider" => "torob",
        "source_url" =>
            "https://torob.com/p/1189b812-9de1-4016-b72d-fa7d3292ff9b/battery/",
        "quality" => "OEM",
        "model" => "Mi10T",
        "match_status" => "confirmed",
        "mode" => "monitor",
        "floor_toman" => "450000",
        "ceiling_toman" => "600000",
        "rule_type" => "fixed_below",
        "delta_value" => "1000",
        "delta_unit" => "toman",
        "schedule_group" => "normal",
    ];
    $row = [];
    foreach (CsvService::HEADERS as $h) {
        $row[] = $cells[$h] ?? "";
    }
    fputcsv($f, $row, ",", '"', "");
}
rewind($f);
$csv = new CsvService();
$import = $csv->stage(stream_get_contents($f));
fclose($f);
$start = microtime(true);
$validChunks = 0;
do {
    $j = $csv->validateChunk($import);
    $validChunks++;
} while ($j["status"] === "validating");
if ($j["status"] !== "validated") {
    throw new RuntimeException("CSV failed");
}
$validSeconds = microtime(true) - $start;
$start = microtime(true);
$applyChunks = 0;
do {
    $j = $csv->applyChunk($import);
    $applyChunks++;
} while ($j["status"] === "applying");
$applySeconds = microtime(true) - $start;
$run = wp_generate_uuid4();
$ids = [];
for ($id = $first; $id <= $last; $id++) {
    for ($round = 0; $round < 4; $round++) {
        $ids[] = as_enqueue_async_action(
            "vsp_benchmark_poll",
            [$id, intdiv($id - $first, 10), $round, $run],
            "vsp-benchmark",
        );
    }
}
$sentinel = as_enqueue_async_action(
    "vsp_benchmark_woo_sentinel",
    [],
    "woocommerce-sentinel",
);
update_option("vsp_benchmark_sentinel", $sentinel, false);
$m = [
    "run" => $run,
    "products" => $last - $first + 1,
    "jobs" => count($ids),
    "first_id" => min($ids),
    "last_id" => max($ids),
    "product_first" => $first,
    "product_last" => $last,
    "validation_chunks" => $validChunks,
    "apply_chunks" => $applyChunks,
    "csv_validation_seconds" => $validSeconds,
    "csv_apply_seconds" => $applySeconds,
    "completed" => 0,
    "fetch_simulations" => 0,
    "latencies_ms" => [],
    "is_test" => true,
    "network_requests" => 0,
    "writes" => 0,
    "peak_bytes" => memory_get_peak_usage(true),
    "discarded_setup_attempts" => 8000,
    "logical_plugin_bytes_before" => $baseline,
    "runner_start" => microtime(true),
];
update_option("vsp_benchmark_metrics", $m, false);
echo wp_json_encode($m, JSON_PRETTY_PRINT) . PHP_EOL;
