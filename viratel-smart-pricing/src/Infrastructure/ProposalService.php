<?php
namespace Viratel\SmartPricing\Infrastructure;
use Viratel\SmartPricing\Domain\PricingEngine;
final class ProposalService
{
    private Repository $repo;
    public function __construct()
    {
        $this->repo = new Repository();
    }
    public function calculate(int $productId, array $snapshot): array
    {
        $m = $this->repo->get("mappings", $productId);
        if (!$m) {
            throw new \RuntimeException("mapping_missing");
        }
        $rule = $this->repo->get("rules", $m["rule_version"])["data"];
        $group = $this->repo->get("groups", $m["group_key"])["data"];
        $rule["max_source_age"] = min(
            (int) ($rule["max_source_age"] ?? 21600),
            (int) ($group["max_source_age"] ?? 21600),
        );
        $state = ProductState::read($productId);
        $s = Settings::get(true);
        $context = [
            "paused" => !empty($s["paused"]),
            "own_domains" => $s["own_domains"] ?? [],
            "own_store_ids" => $s["own_store_ids"] ?? [],
            "current" => $state["effective"],
            "currency" => Settings::currency(),
            "sale_conflict" => ProductState::saleConflict($state, $rule),
            "last_applied_at" => $this->repo->latestApplied($productId),
            "daily_change" => $this->repo->dailyChange(
                $productId,
                strtotime("today UTC"),
            ),
        ];
        $result = (new PricingEngine())->calculate(
            $snapshot,
            $m["data"],
            $rule,
            $context,
            time(),
        );
        if (
            empty($m["data"]["enabled"]) ||
            $state["stock_status"] !== "instock"
        ) {
            $result["decision"] = "hold";
            $result["reasons"][] = "product_disabled_or_unavailable";
        }
        return [
            "result" => $result,
            "is_test" => !empty($snapshot["is_test"]),
            "before" => $state,
            "before_hash" => ProductState::hash($state),
            "mapping" => $m["data"],
            "rule" => $rule,
            "rule_version" => $m["rule_version"],
            "group_version" => hash("sha256", wp_json_encode($group)),
            "currency" => $context["currency"],
        ];
    }
    public function create(int $productId, string $snapshotId): string
    {
        $snapshot = $this->repo->get("snapshots", $snapshotId);
        if (!$snapshot) {
            throw new \RuntimeException("snapshot_missing");
        }
        $d = $this->calculate($productId, $snapshot["data"]);
        $id = hash(
            "sha256",
            $productId .
                "|" .
                $d["rule_version"] .
                "|" .
                $d["mapping"]["match_version"] .
                "|" .
                $snapshotId .
                "|" .
                $d["before_hash"] .
                "|" .
                $d["group_version"] .
                "|" .
                hash("sha256", wp_json_encode($d["result"])),
        );
        $existing = $this->repo->get("proposals", $id);
        if (
            $existing &&
            in_array($existing["status"], ["cancelled", "conflict"], true)
        ) {
            $id = hash("sha256", $id . "|recovery|" . wp_generate_uuid4());
        }
        $this->repo->insert(
            "proposals",
            [
                "id" => $id,
                "product_id" => $productId,
                "rule_version" => $d["rule_version"],
                "snapshot_id" => $snapshotId,
                "data" => $d,
                "status" =>
                    $d["result"]["decision"] === "apply"
                        ? "ready"
                        : $d["result"]["decision"],
                "created_at" => time(),
            ],
            true,
        );
        $this->repo->update("mappings", $productId, [
            "status" =>
                $d["result"]["decision"] === "apply"
                    ? "proposal_ready"
                    : implode(",", array_slice($d["result"]["reasons"], -1)),
        ]);
        return $id;
    }
}
