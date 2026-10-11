<?php
WP_CLI::add_hook("after_wp_load", function () {
    if (wp_get_environment_type() !== "local") {
        throw new RuntimeException("Local environment required");
    }
    add_action(
        "vsp_benchmark_poll",
        function ($id, $canonical, $round, $run) {
            $start = microtime(true);
            $repo = new Viratel\SmartPricing\Infrastructure\Repository();
            $key = "benchmark:" . $run . ":" . $canonical;
            $window = $round;
            $snapshot = $repo->sourceSnapshot($key, $window);
            if (!$snapshot) {
                $lock = new Viratel\SmartPricing\Infrastructure\Lock();
                if (!$lock->acquire($key, 60)) {
                    throw new RuntimeException("benchmark lease failed");
                }
                try {
                    $now = time();
                    $data = [
                        "provider" => "benchmark_fixture",
                        "canonical_product_id" => $key,
                        "source_url" => "https://example.invalid/fixture",
                        "status" => "ok",
                        "freshness" => "known",
                        "source_observed_at" => gmdate("c", $now),
                        "fetched_at" => gmdate("c", $now),
                        "coverage" => "complete",
                        "currency" => "IRT",
                        "offers" => [],
                        "is_test" => true,
                    ];
                    foreach (["a", "b"] as $seller) {
                        $data["offers"][] = [
                            "offer_id" => $seller,
                            "store_id" => $seller,
                            "seller_url" =>
                                "https://seller-" . $seller . ".example",
                            "price" => "500000",
                            "currency" => "IRT",
                            "in_stock" => true,
                            "observed_at" => gmdate("c", $now),
                            "attributes" => [
                                "quality" => "OEM",
                                "model" => "Mi10T",
                            ],
                        ];
                    }
                    $sid = hash("sha256", $key . "|" . $window);
                    $repo->insert("snapshots", [
                        "id" => $sid,
                        "source_key" => $key,
                        "window_id" => $window,
                        "data" => $data,
                        "created_at" => $now,
                    ]);
                    $snapshot = $repo->get("snapshots", $sid);
                    $metrics = get_option("vsp_benchmark_metrics");
                    $metrics["fetch_simulations"]++;
                    update_option("vsp_benchmark_metrics", $metrics, false);
                } finally {
                    $lock->release($key);
                }
            }
            $proposal = (new Viratel\SmartPricing\Infrastructure\ProposalService())->create(
                (int) $id,
                $snapshot["id"],
            );
            if ($repo->get("proposals", $proposal)["status"] !== "ready") {
                throw new RuntimeException("benchmark proposal invalid");
            }
            if (
                Viratel\SmartPricing\Infrastructure\ProductState::read(
                    (int) $id,
                )["effective"] !== "500000"
            ) {
                throw new RuntimeException("benchmark price mutated");
            }
            $m = get_option("vsp_benchmark_metrics");
            $m["completed"]++;
            $m["latencies_ms"][] = (int) ((microtime(true) - $start) * 1000);
            $m["peak_bytes"] = max(
                $m["peak_bytes"],
                memory_get_peak_usage(true),
            );
            update_option("vsp_benchmark_metrics", $m, false);
        },
        10,
        4,
    );
});
