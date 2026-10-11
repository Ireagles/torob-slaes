<?php
if (wp_get_environment_type() !== "local") {
    throw new RuntimeException("Local only");
}
global $wpdb;
$m = get_option("vsp_benchmark_metrics");
$counts = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT status,COUNT(*) n FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id BETWEEN %d AND %d AND hook='vsp_benchmark_poll' GROUP BY status",
        $m["first_id"],
        $m["last_id"],
    ),
    ARRAY_A,
);
$statuses = array_column($counts, "n", "status");
if (
    (int) ($statuses["complete"] ?? 0) !== 8000 ||
    count($statuses) !== 1 ||
    $m["completed"] !== 8000 ||
    $m["fetch_simulations"] !== 800
) {
    throw new RuntimeException("Incomplete benchmark");
}
$changed = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id BETWEEN %d AND %d AND meta_key IN ('_price','_regular_price') AND meta_value <> '500000'",
        $m["product_first"],
        $m["product_last"],
    ),
);
if ($changed) {
    throw new RuntimeException("Benchmark changed prices");
}
$sentinel = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT status FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id=%d",
        get_option("vsp_benchmark_sentinel"),
    ),
);
if ($sentinel !== "pending") {
    throw new RuntimeException("Other queue consumed");
}
$times = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT MIN(log_date_gmt) started,MAX(log_date_gmt) finished FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id BETWEEN %d AND %d AND message NOT LIKE 'action created%%'",
        $m["first_id"],
        $m["last_id"],
    ),
    ARRAY_A,
);
sort($m["latencies_ms"]);
$m["latency_p50_ms"] = $m["latencies_ms"][3999];
$m["latency_p95_ms"] = $m["latencies_ms"][7599];
unset($m["latencies_ms"]);
$m["action_status_counts"] = $statuses;
$m["other_queue_sentinel"] = $sentinel;
$m["verified_price_changes"] = $changed;
$m["log_times_utc"] = $times;
$m["worker_wall_seconds"] =
    strtotime($times["finished"]) - strtotime($times["started"]);
$m["logical_plugin_bytes_after"] = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT SUM(data_length+index_length) FROM information_schema.tables WHERE table_schema=%s AND table_name LIKE %s",
        DB_NAME,
        $wpdb->esc_like($wpdb->prefix . "vsp_") . "%",
    ),
);
file_put_contents(
    "/workspace/torob-slaes/docs/benchmark-results.json",
    wp_json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
);
echo wp_json_encode($m, JSON_PRETTY_PRINT) . PHP_EOL;
