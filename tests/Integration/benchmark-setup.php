<?php
if (wp_get_environment_type() !== "local") {
    throw new RuntimeException("Local environment required");
}
use Viratel\SmartPricing\Infrastructure\{MappingService, Settings, Repository};
use Viratel\SmartPricing\ImportExport\CsvService;
$s = Settings::get();
$s["mode"] = "monitor";
$s["currency"] = "IRT";
$s["own_domains"] = ["viratel.example"];
$s["source_configured"] = false;
update_option("vsp_settings", $s, false);
$ids = [];
$start = microtime(true);
$f = fopen("php://temp", "r+");
fputcsv($f, CsvService::HEADERS, ",", '"', "");
for ($i = 0; $i < 2000; $i++) {
    $p = new WC_Product_Simple();
    $p->set_name("VSP BENCH TEST " . $i);
    $p->set_sku("vsp-bench-" . wp_generate_uuid4());
    $p->set_regular_price("500000");
    $p->set_stock_status("instock");
    $p->save();
    $id = $p->get_id();
    $ids[] = $id;
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
    if (($i + 1) % 500 === 0) {
        echo "Created " . ($i + 1) . " local products\n";
    }
}
rewind($f);
$csv = new CsvService();
$import = $csv->stage(stream_get_contents($f));
fclose($f);
$validateStart = microtime(true);
$validationChunks = 0;
do {
    $j = $csv->validateChunk($import);
    $validationChunks++;
} while ($j["status"] === "validating");
if ($j["status"] !== "validated") {
    throw new RuntimeException("benchmark CSV validation failed");
}
$validatedTime = microtime(true) - $validateStart;
$applyStart = microtime(true);
$applyChunks = 0;
do {
    $j = $csv->applyChunk($import);
    $applyChunks++;
} while ($j["status"] === "applying");
$appliedTime = microtime(true) - $applyStart;
$createdTime = microtime(true) - $start;
$run = wp_generate_uuid4();
$jobIds = [];
$enqueueStart = microtime(true);
foreach ($ids as $index => $id) {
    for ($round = 0; $round < 4; $round++) {
        $jobIds[] = as_enqueue_async_action(
            "vsp_benchmark_poll",
            [$id, intdiv($index, 10), $round, $run],
            "vsp-benchmark",
        );
    }
}
$metrics = [
    "run" => $run,
    "products" => count($ids),
    "jobs" => count($jobIds),
    "first_id" => min($jobIds),
    "last_id" => max($jobIds),
    "product_first" => min($ids),
    "product_last" => max($ids),
    "validation_chunks" => $validationChunks,
    "apply_chunks" => $applyChunks,
    "csv_validation_seconds" => $validatedTime,
    "csv_apply_seconds" => $appliedTime,
    "creation_and_csv_seconds" => $createdTime,
    "enqueue_seconds" => microtime(true) - $enqueueStart,
    "completed" => 0,
    "fetch_simulations" => 0,
    "latencies_ms" => [],
    "is_test" => true,
    "network_requests" => 0,
    "writes" => 0,
    "peak_bytes" => memory_get_peak_usage(true),
];
update_option("vsp_benchmark_metrics", $metrics, false);
echo wp_json_encode($metrics, JSON_PRETTY_PRINT) . "\n";
