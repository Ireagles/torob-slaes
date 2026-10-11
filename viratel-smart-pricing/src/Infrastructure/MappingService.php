<?php
namespace Viratel\SmartPricing\Infrastructure;
use Viratel\SmartPricing\Domain\Money;
use Viratel\SmartPricing\Security\SourceUrl;

final class MappingService
{
    private Repository $repo;
    public function __construct()
    {
        $this->repo = new Repository();
    }
    public function save(
        int $productId,
        array $mapping,
        array $rule,
        ?string $expectedVersion = null,
        ?string $expectedHash = null,
        ?callable $afterSave = null,
    ): string {
        $p = wc_get_product($productId);
        if (!$p || !in_array($p->get_type(), ["simple", "variation"], true)) {
            throw new \InvalidArgumentException("simple_or_variation_required");
        }
        $canonical = SourceUrl::validate($mapping["source_url"] ?? "");
        foreach (
            [
                "mode" => ["monitor", "suggest", "auto"],
                "match_status" => ["pending", "confirmed"],
            ]
            as $field => $allowed
        ) {
            if (!in_array($mapping[$field] ?? "", $allowed, true)) {
                throw new \InvalidArgumentException(
                    "mapping_invalid_" . $field,
                );
            }
        }
        $attributes = $mapping["attributes"] ?? [];
        if (!is_array($attributes) || count($attributes) > 15) {
            throw new \InvalidArgumentException("attributes_invalid");
        }
        $clean = [];
        foreach ($attributes as $k => $v) {
            if (!is_string($v) || strlen($v) > 200) {
                throw new \InvalidArgumentException("attribute_invalid");
            }
            $clean[sanitize_key($k)] = sanitize_text_field($v);
        }
        if (
            $mapping["match_status"] === "confirmed" &&
            (empty($clean["quality"]) || empty($clean["model"]))
        ) {
            throw new \InvalidArgumentException("quality_and_model_required");
        }
        $currency = $rule["currency"] ?? "IRT";
        $floor = Money::parse($rule["floor"] ?? "", $currency);
        $ceiling = Money::parse($rule["ceiling"] ?? "", $currency);
        if ($floor <= 0 || $floor > $ceiling) {
            throw new \InvalidArgumentException("invalid_bounds");
        }
        if (
            !in_array(
                $rule["type"] ?? "",
                [
                    "match",
                    "fixed_below",
                    "fixed_above",
                    "percent_below",
                    "percent_above",
                    "cost_plus",
                ],
                true,
            )
        ) {
            throw new \InvalidArgumentException("invalid_rule_type");
        }
        if (!in_array($rule["basis"] ?? "min", ["min", "max", "mean"], true)) {
            throw new \InvalidArgumentException("invalid_basis");
        }
        Money::parse($rule["delta"] ?? "0", $currency);
        Money::parse($rule["round_step"] ?? "1", $currency);
        foreach (
            [
                "delta_bps",
                "max_change_bps",
                "outlier_bps",
                "margin_bps",
                "fee_bps",
            ]
            as $field
        ) {
            if (
                isset($rule[$field]) &&
                (!is_int($rule[$field]) ||
                    $rule[$field] < 0 ||
                    $rule[$field] > 10000)
            ) {
                throw new \InvalidArgumentException("invalid_" . $field);
            }
        }
        $group = sanitize_key($mapping["schedule_group"] ?? "normal");
        if (!$this->repo->get("groups", $group)) {
            throw new \InvalidArgumentException("group_missing");
        }
        $key = "product:" . $productId;
        $lock = new Lock();
        if (!$lock->acquire($key)) {
            throw new \RuntimeException("product_busy");
        }
        $transaction = false;
        try {
            // Persist the lease before starting the config transaction, avoiding lock-table gap deadlocks.
            if ($afterSave !== null) {
                global $wpdb;
                if ($wpdb->query("START TRANSACTION") === false) {
                    throw new \RuntimeException("transaction_failed");
                }
                $transaction = true;
            }
            $old = $this->repo->get("mappings", $productId);
            if (
                $expectedHash !== null &&
                ($old ? hash("sha256", $old["config"]) : "") !== $expectedHash
            ) {
                throw new \RuntimeException("mapping_conflict");
            }
            if (
                $expectedVersion !== null &&
                ($old["rule_version"] ?? "") !== $expectedVersion
            ) {
                throw new \RuntimeException("mapping_conflict");
            }
            $mapping = [
                "source_url" => $mapping["source_url"],
                "provider" => "torob",
                "canonical_product_id" => $canonical,
                "attributes" => $clean,
                "match_status" => $mapping["match_status"],
                "mode" => $mapping["mode"],
                "enabled" => (bool) ($mapping["enabled"] ?? true),
                "sku" => $p->get_sku(),
                "title" => $p->get_name(),
                "schedule_group" => $group,
                "variation_mode" => "independent",
                "manual_lock_until" => $old["data"]["manual_lock_until"] ?? 0,
                "import_id" => sanitize_text_field($mapping["import_id"] ?? ""),
                "match_version" => wp_generate_uuid4(),
            ];
            $version = hash("sha256", wp_json_encode($rule));
            $this->repo->insert(
                "rules",
                ["id" => $version, "data" => $rule, "created_at" => time()],
                true,
            );
            $row = [
                "source_key" => hash("sha256", "torob:" . $canonical),
                "group_key" => $group,
                "rule_version" => $version,
                "data" => $mapping,
                "status" => $mapping["enabled"]
                    ? "ready_monitoring"
                    : "disabled",
                "next_due" => time(),
                "updated_at" => time(),
            ];
            if ($old) {
                $this->repo->update("mappings", $productId, $row);
            } else {
                $this->repo->insert(
                    "mappings",
                    array_merge(["product_id" => $productId], $row),
                );
            }
            if ($afterSave !== null) {
                $afterSave($version);
            }
            if ($transaction) {
                if ($wpdb->query("COMMIT") === false) {
                    throw new \RuntimeException("transaction_failed");
                }
                $transaction = false;
            }
            return $version;
        } catch (\Throwable $e) {
            if ($transaction) {
                $wpdb->query("ROLLBACK");
            }
            throw $e;
        } finally {
            $lock->release($key);
        }
    }
    public function unlock(int $productId): void
    {
        $lock = new Lock();
        $key = "product:" . $productId;
        if (!$lock->acquire($key)) {
            throw new \RuntimeException("product_busy");
        }
        try {
            $row = $this->repo->get("mappings", $productId);
            if (!$row) {
                throw new \RuntimeException("mapping_missing");
            }
            $d = $row["data"];
            $d["manual_lock_until"] = 0;
            $this->repo->update("mappings", $productId, [
                "data" => $d,
                "status" => "ready_monitoring",
            ]);
        } finally {
            $lock->release($key);
        }
    }
}
