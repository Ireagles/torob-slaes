<?php
namespace Viratel\SmartPricing\Queue;
use Viratel\SmartPricing\Infrastructure\{Repository, Lock, Settings};
final class BudgetService
{
    public function reserve(): int
    {
        $lock = new Lock();
        if (!$lock->acquire("provider:budget", 10)) {
            throw new \RuntimeException("budget_busy");
        }
        try {
            $s = Settings::get();
            $repo = new Repository();
            $now = time();
            if ((int) get_option("vsp_circuit_until", 0) > $now) {
                throw new \RuntimeException("provider_circuit_open");
            }
            $hour = (int) wp_date("G");
            $start = (int) ($s["quiet_start"] ?? -1);
            $end = (int) ($s["quiet_end"] ?? -1);
            if (
                $start >= 0 &&
                $end >= 0 &&
                ($start < $end
                    ? $hour >= $start && $hour < $end
                    : $hour >= $start || $hour < $end)
            ) {
                throw new \RuntimeException("provider_quiet_hours");
            }
            if (
                $repo->usageSince(strtotime("today UTC")) >=
                    (int) ($s["daily_budget"] ?? 100) ||
                $repo->usageSince(strtotime(gmdate("Y-m-01") . " UTC")) >=
                    (int) ($s["monthly_budget"] ?? 3000)
            ) {
                throw new \RuntimeException("provider_budget_exhausted");
            }
            if ($now - (int) get_option("vsp_last_request", 0) < 10) {
                throw new \RuntimeException("provider_rate_limited");
            }
            global $wpdb;
            $repo->insert("usage", [
                "provider" => "torob",
                "status" => "attempt",
                "created_at" => $now,
            ]);
            $id = (int) $wpdb->insert_id;
            update_option("vsp_last_request", $now, false);
            return $id;
        } finally {
            $lock->release("provider:budget");
        }
    }
    public function finish(int $id, string $status, int $milliseconds): void
    {
        (new Repository())->update("usage", $id, [
            "status" => $status,
            "latency_ms" => max(0, $milliseconds),
        ]);
    }
    public function result(string $status, int $retryAfter = 60): void
    {
        if ($status === "ok" || $status === "no_offers") {
            update_option("vsp_provider_failures", 0, false);
            return;
        }
        $n = (int) get_option("vsp_provider_failures", 0) + 1;
        update_option("vsp_provider_failures", $n, false);
        if (
            $n >= 3 ||
            in_array(
                $status,
                ["http_403", "http_429", "challenge_or_login"],
                true,
            )
        ) {
            update_option(
                "vsp_circuit_until",
                time() + max(300, min(86400, $retryAfter)),
                false,
            );
        }
    }
}
