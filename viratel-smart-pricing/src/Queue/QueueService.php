<?php
namespace Viratel\SmartPricing\Queue;
use Viratel\SmartPricing\Infrastructure\{
    Repository,
    Lock,
    Settings,
    ProposalService,
    PriceApplier,
};
use Viratel\SmartPricing\Providers\ProviderRegistry;
final class QueueService
{
    const GROUP = "viratel-smart-pricing";
    public function register(): void
    {
        add_action("action_scheduler_init", [$this, "start"]);
        add_action("vsp_dispatch", [$this, "dispatch"], 10, 1);
        add_action("vsp_poll", [$this, "poll"], 10, 2);
        add_action("vsp_retention", [new Repository(), "prune"]);
    }
    public function start(): void
    {
        if (!get_option("vsp_active", false)) {
            return;
        }
        if (!as_has_scheduled_action("vsp_dispatch", null, self::GROUP)) {
            as_schedule_recurring_action(
                time() + 60,
                300,
                "vsp_dispatch",
                [0],
                self::GROUP,
                true,
            );
        }
        if (!as_has_scheduled_action("vsp_retention", null, self::GROUP)) {
            as_schedule_recurring_action(
                time() + 3600,
                86400,
                "vsp_retention",
                [],
                self::GROUP,
                true,
            );
        }
    }
    public function dispatch(int $after = 0): void
    {
        update_option("vsp_queue_heartbeat", gmdate("c"), false);
        $s = Settings::get();
        if (
            !get_option("vsp_active", false) ||
            empty($s["source_configured"]) ||
            (!empty($s["paused"]) && !empty($s["pause_monitor"]))
        ) {
            return;
        }
        $repo = new Repository();
        $rows = $repo->due($after, time());
        foreach ($rows as $row) {
            $id = (int) $row["product_id"];
            $args = [$id, 0];
            if (!as_has_scheduled_action("vsp_poll", $args, self::GROUP)) {
                as_schedule_single_action(
                    time() + ($id % 120),
                    "vsp_poll",
                    $args,
                    self::GROUP,
                    true,
                );
            }
            $group = $repo->get("groups", $row["group_key"])["data"];
            $repo->update("mappings", $id, [
                "next_due" =>
                    time() +
                    max(900, (int) ($group["interval"] ?? 21600)) +
                    ($id % 60),
            ]);
        }
        if (count($rows) === 50) {
            as_enqueue_async_action(
                "vsp_dispatch",
                [(int) end($rows)["product_id"]],
                self::GROUP,
                true,
            );
        }
    }
    public function poll(int $id, int $attempt = 0): void
    {
        $s = Settings::get();
        if (
            !get_option("vsp_active", false) ||
            empty($s["source_configured"]) ||
            (!empty($s["paused"]) && !empty($s["pause_monitor"]))
        ) {
            return;
        }
        $repo = new Repository();
        $m = $repo->get("mappings", $id);
        if (!$m || empty($m["data"]["enabled"])) {
            return;
        }
        $key = "fetch:" . $m["source_key"];
        $lock = new Lock();
        if (!$lock->acquire($key, 120)) {
            $this->retry($id, $attempt, 30);
            return;
        }
        try {
            $window = intdiv(time(), 300);
            $snapshot = $repo->sourceSnapshot($m["source_key"], $window);
            if (!$snapshot) {
                $data = (new ProviderRegistry())
                    ->get($m["data"]["provider"])
                    ->fetch($m["data"]["source_url"]);
                if (
                    in_array(
                        $data["status"],
                        ["provider_rate_limited", "budget_busy"],
                        true,
                    )
                ) {
                    $this->retry($id, $attempt, 30);
                    return;
                }
                if ($data["status"] === "redirect_pending") {
                    $productLease = new Lock();
                    $productKey = "product:" . $id;
                    if (!$productLease->acquire($productKey)) {
                        $this->retry($id, $attempt, 30);
                        return;
                    }
                    try {
                        $current = $repo->get("mappings", $id);
                        if (
                            $current &&
                            $current["data"]["source_url"] ===
                                $m["data"]["source_url"]
                        ) {
                            $updated = $current["data"];
                            $updated["source_url"] = $data["redirect_url"];
                            $repo->update("mappings", $id, [
                                "data" => $updated,
                                "status" => "redirect_pending",
                            ]);
                        }
                    } finally {
                        $productLease->release($productKey);
                    }
                    $this->retry($id, $attempt, 10);
                    return;
                }
                (new BudgetService())->result(
                    $data["status"],
                    (int) ($data["retry_after"] ?? 60),
                );
                if ($data["status"] !== "ok") {
                    $repo->update("mappings", $id, [
                        "status" => $data["status"],
                    ]);
                    if (
                        in_array(
                            $data["status"],
                            [
                                "http_429",
                                "network_error",
                                "http_500",
                                "http_502",
                                "http_503",
                            ],
                            true,
                        )
                    ) {
                        $this->retry(
                            $id,
                            $attempt,
                            (int) ($data["retry_after"] ?? 60),
                        );
                    }
                    return;
                }
                $sid = hash("sha256", $m["source_key"] . "|" . $window);
                $repo->insert("snapshots", [
                    "id" => $sid,
                    "source_key" => $m["source_key"],
                    "window_id" => $window,
                    "data" => $data,
                    "created_at" => time(),
                ]);
                $snapshot = $repo->get("snapshots", $sid);
            }
            $repo->update("mappings", $id, [
                "status" =>
                    $snapshot["data"]["freshness"] === "unknown"
                        ? "source_time_unknown"
                        : "monitoring",
            ]);
        } finally {
            $lock->release($key);
        }
        // Installation mode is monitoring: no proposal execution or inventory writes.
        if (
            ($s["mode"] ?? "monitor") === "monitor" ||
            ($m["data"]["mode"] ?? "monitor") === "monitor"
        ) {
            return;
        }
        $proposal = (new ProposalService())->create($id, $snapshot["id"]);
        if (
            ($s["mode"] ?? "") === "auto" &&
            $m["data"]["mode"] === "auto" &&
            Settings::writesAllowed()
        ) {
            $p = $repo->get("proposals", $proposal);
            if ($p["status"] === "ready") {
                (new PriceApplier())->apply($proposal, false);
            }
        }
    }
    private function retry(int $id, int $attempt, int $delay): void
    {
        if ($attempt >= 3) {
            return;
        }
        $delay = max($delay, min(3600, 30 * 2 ** $attempt));
        as_schedule_single_action(
            time() + $delay,
            "vsp_poll",
            [$id, $attempt + 1],
            self::GROUP,
            true,
        );
    }
}
