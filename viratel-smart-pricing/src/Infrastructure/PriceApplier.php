<?php
namespace Viratel\SmartPricing\Infrastructure;
use Viratel\SmartPricing\Domain\{Money, ProfitFloor};

final class PriceApplier
{
    private Repository $repo;
    public static bool $writing = false;
    public function __construct()
    {
        $this->repo = new Repository();
    }
    public function apply(string $proposalId, bool $manual = false): string
    {
        if (!Settings::writesAllowed($manual)) {
            throw new \RuntimeException("writes_disabled");
        }
        $p = $this->repo->get("proposals", $proposalId);
        if (!$p) {
            throw new \RuntimeException("proposal_missing");
        }
        $key = "product:" . $p["product_id"];
        $lock = new Lock();
        if (!$lock->acquire($key, 120)) {
            throw new \RuntimeException("product_busy");
        }
        try {
            $id = hash("sha256", "apply:" . $proposalId);
            $existing = $this->repo->get("changes", $id);
            if ($existing) {
                return $this->recover($existing, $lock, $key);
            }
            if ($p["status"] !== "ready") {
                throw new \RuntimeException("proposal_not_ready");
            }
            $m = $this->repo->get("mappings", $p["product_id"]);
            if (
                !$m ||
                $m["rule_version"] !== $p["rule_version"] ||
                $m["data"]["match_version"] !==
                    $p["data"]["mapping"]["match_version"]
            ) {
                throw new \RuntimeException("version_conflict");
            }
            if (
                ($m["data"]["mode"] ?? "monitor") === "monitor" ||
                (!$manual && $m["data"]["mode"] !== "auto")
            ) {
                throw new \RuntimeException("product_mode_blocked");
            }
            $snapshot = $this->repo->get("snapshots", $p["snapshot_id"]);
            if (!$snapshot) {
                throw new \RuntimeException("snapshot_missing");
            }
            $test = !empty($snapshot["data"]["is_test"]);
            if (
                $test &&
                !(
                    defined("VSP_TEST_HARNESS") &&
                    VSP_TEST_HARNESS &&
                    wp_get_environment_type() === "local"
                )
            ) {
                throw new \RuntimeException("test_data_write_blocked");
            }
            $fresh = (new ProposalService())->calculate(
                (int) $p["product_id"],
                $snapshot["data"],
            );
            if ($fresh["before_hash"] !== $p["data"]["before_hash"]) {
                throw new \RuntimeException("price_conflict");
            }
            if (
                $fresh["result"]["decision"] !== "apply" ||
                $fresh["result"]["amount"] !== $p["data"]["result"]["amount"] ||
                $fresh["group_version"] !==
                    ($p["data"]["group_version"] ?? null)
            ) {
                throw new \RuntimeException(
                    "proposal_invalid_" .
                        implode("_", $fresh["result"]["reasons"]),
                );
            }
            $target = Money::format(
                $fresh["result"]["amount"],
                $fresh["currency"],
            );
            $before = $fresh["before"];
            $after = $before;
            if (($fresh["rule"]["write_mode"] ?? "regular") === "sale") {
                if (
                    $before["sale"] === "" ||
                    Money::parse($target, $fresh["currency"]) >=
                        Money::parse($before["regular"], $fresh["currency"])
                ) {
                    throw new \RuntimeException("sale_policy_conflict");
                }
                $after["sale"] = $target;
            } else {
                $after["regular"] = $target;
            }
            $after["effective"] = $target;
            $d = [
                "before" => $before,
                "after" => $after,
                "before_hash" => ProductState::hash($before),
                "after_hash" => ProductState::hash($after),
                "snapshot_id" => $p["snapshot_id"],
                "rule_version" => $p["rule_version"],
                "result" => $fresh["result"],
                "source_observed_at" => $snapshot["data"]["source_observed_at"],
                "fetched_at" => $snapshot["data"]["fetched_at"],
                "mode" => $manual ? "manual" : "auto",
                "actor" => get_current_user_id(),
                "correlation_id" => wp_generate_uuid4(),
                "delta_minor" => abs(
                    $fresh["result"]["amount"] -
                        Money::parse($before["effective"], $fresh["currency"]),
                ),
                "currency" => $fresh["currency"],
                "is_test" => $test,
                "mapping_match_version" => $m["data"]["match_version"],
                "group_version" => $fresh["group_version"],
            ];
            $this->repo->insert("changes", [
                "id" => $id,
                "product_id" => $p["product_id"],
                "proposal_id" => $proposalId,
                "data" => $d,
                "status" => "intent",
                "created_at" => time(),
            ]);
            do_action("vsp_test_after_intent", $id);
            $this->write((int) $p["product_id"], $d, $lock, $key, false);
            do_action("vsp_test_after_save", $id);
            $this->repo->update("changes", $id, ["status" => "applied"]);
            $this->repo->update("proposals", $proposalId, [
                "status" => "applied",
            ]);
            $this->repo->update("mappings", $p["product_id"], [
                "status" => "applied",
            ]);
            return $id;
        } finally {
            $lock->release($key);
        }
    }
    /** Reconcile an existing intent only. Never calls WooCommerce save or creates an intent. */
    public function reconcile(string $proposalId): string
    {
        $change = $this->repo->get(
            "changes",
            hash("sha256", "apply:" . $proposalId),
        );
        if (!$change) {
            throw new \RuntimeException("intent_missing");
        }
        $key = "product:" . $change["product_id"];
        $lock = new Lock();
        if (!$lock->acquire($key, 120)) {
            throw new \RuntimeException("product_busy");
        }
        try {
            return $this->recover($change, $lock, $key);
        } finally {
            $lock->release($key);
        }
    }
    private function recover(array $change, Lock $lock, string $key): string
    {
        $d = $change["data"];
        $hash = ProductState::hash(
            ProductState::read((int) $change["product_id"]),
        );
        if ($change["status"] === "applied") {
            return $change["id"];
        }
        if ($change["status"] !== "intent") {
            throw new \RuntimeException("change_conflict");
        }
        if ($hash === $d["after_hash"]) {
            $this->repo->update("changes", $change["id"], [
                "status" => "applied",
            ]);
            if ($change["proposal_id"] !== "") {
                $this->repo->update("proposals", $change["proposal_id"], [
                    "status" => "applied",
                ]);
            }
            return $change["id"];
        }
        if ($hash !== $d["before_hash"]) {
            $this->repo->update("changes", $change["id"], [
                "status" => "conflict",
            ]);
            throw new \RuntimeException("recovery_conflict");
        }
        // Crash before save: require a new proposal; an old intent is never blindly replayed.
        $this->repo->update("changes", $change["id"], [
            "status" => "cancelled",
        ]);
        if ($change["proposal_id"] !== "") {
            $this->repo->update("proposals", $change["proposal_id"], [
                "status" => "cancelled",
            ]);
        }
        throw new \RuntimeException("recovery_recalculate_required");
    }
    private function write(
        int $productId,
        array $d,
        Lock $lock,
        string $key,
        bool $rollback,
    ): void {
        if (!$lock->held($key) || !Settings::writesAllowed(true)) {
            throw new \RuntimeException("paused_or_lease_expired");
        }
        if (
            ProductState::hash(ProductState::read($productId)) !==
            $d["before_hash"]
        ) {
            throw new \RuntimeException("price_conflict");
        }
        $m = $this->repo->get("mappings", $productId);
        if (
            !$m ||
            ($m["data"]["manual_lock_until"] ?? 0) > time() ||
            empty($m["data"]["enabled"])
        ) {
            throw new \RuntimeException("manual_lock_or_disabled");
        }
        if (
            !$rollback &&
            ($m["rule_version"] !== $d["rule_version"] ||
                $m["data"]["match_version"] !== $d["mapping_match_version"])
        ) {
            throw new \RuntimeException("version_conflict");
        }
        $p = wc_get_product($productId);
        $p->set_regular_price($d["after"]["regular"]);
        $p->set_sale_price($d["after"]["sale"]);
        $p->set_date_on_sale_from($d["after"]["sale_from"]);
        $p->set_date_on_sale_to($d["after"]["sale_to"]);
        $p->set_price($d["after"]["effective"]);
        $guard = function ($object) use (
            $productId,
            $d,
            $lock,
            $key,
            $rollback,
        ) {
            if ($object->get_id() !== $productId) {
                return;
            }
            if (
                !Settings::writesAllowed(true) ||
                !$lock->held($key) ||
                ProductState::hash(ProductState::read($productId)) !==
                    $d["before_hash"]
            ) {
                throw new \RuntimeException("pre_save_conflict");
            }
            if (!$rollback) {
                $snapshot = $this->repo->get("snapshots", $d["snapshot_id"]);
                if (!$snapshot) {
                    throw new \RuntimeException("snapshot_missing");
                }
                $again = (new ProposalService())->calculate(
                    $productId,
                    $snapshot["data"],
                );
                $currentMapping = $this->repo->get("mappings", $productId);
                if (
                    $again["result"]["decision"] !== "apply" ||
                    $again["result"]["amount"] !== $d["result"]["amount"] ||
                    $again["group_version"] !== $d["group_version"] ||
                    $currentMapping["rule_version"] !== $d["rule_version"] ||
                    $currentMapping["data"]["match_version"] !==
                        $d["mapping_match_version"]
                ) {
                    throw new \RuntimeException("pre_save_policy_conflict");
                }
            } else {
                $currentMapping = $this->repo->get("mappings", $productId);
                if (
                    $currentMapping["rule_version"] !==
                    $d["current_rule_version"]
                ) {
                    throw new \RuntimeException("rollback_policy_conflict");
                }
                if (
                    Settings::currency() !== $d["currency"] ||
                    ($currentMapping["data"]["manual_lock_until"] ?? 0) >
                        time() ||
                    ($currentMapping["data"]["mode"] ?? "monitor") ===
                        "monitor" ||
                    empty($currentMapping["data"]["enabled"])
                ) {
                    throw new \RuntimeException("rollback_policy_conflict");
                }
                $rule = $this->repo->get(
                    "rules",
                    $currentMapping["rule_version"],
                )["data"];
                $floor = max(
                    Money::parse($rule["floor"], $rule["currency"]),
                    ProfitFloor::calculate($rule, time()),
                );
                $amount = Money::parse(
                    $d["after"]["effective"],
                    $d["currency"],
                );
                if (
                    $amount < $floor ||
                    $amount > Money::parse($rule["ceiling"], $rule["currency"])
                ) {
                    throw new \RuntimeException(
                        "rollback_current_bounds_conflict",
                    );
                }
            }
            if (
                $object->get_regular_price("edit") !== $d["after"]["regular"] ||
                $object->get_sale_price("edit") !== $d["after"]["sale"] ||
                $object->get_price("edit") !== $d["after"]["effective"]
            ) {
                throw new \RuntimeException("save_mutated_by_other_plugin");
            }
        };
        self::$writing = true;
        add_action(
            "woocommerce_before_product_object_save",
            $guard,
            PHP_INT_MAX,
            1,
        );
        add_action(
            "woocommerce_before_product_variation_object_save",
            $guard,
            PHP_INT_MAX,
            1,
        );
        try {
            $p->save();
        } finally {
            self::$writing = false;
            remove_action(
                "woocommerce_before_product_object_save",
                $guard,
                PHP_INT_MAX,
            );
            remove_action(
                "woocommerce_before_product_variation_object_save",
                $guard,
                PHP_INT_MAX,
            );
        }
        if ($p->is_type("variation")) {
            \WC_Product_Variable::sync($p->get_parent_id(), true);
        }
        wc_delete_product_transients($productId);
        if (
            ProductState::hash(ProductState::read($productId)) !==
            $d["after_hash"]
        ) {
            throw new \RuntimeException("post_save_conflict");
        }
    }
    public function rollback(string $changeId): string
    {
        if (!Settings::writesAllowed(true)) {
            throw new \RuntimeException("writes_disabled");
        }
        $original = $this->repo->get("changes", $changeId);
        if (!$original || $original["status"] !== "applied") {
            throw new \RuntimeException("change_not_applied");
        }
        if (!empty($original["data"]["rollback_of"])) {
            throw new \RuntimeException("nested_rollback_blocked");
        }
        $key = "product:" . $original["product_id"];
        $lock = new Lock();
        if (!$lock->acquire($key, 120)) {
            throw new \RuntimeException("product_busy");
        }
        try {
            $id = hash("sha256", "rollback:" . $changeId);
            $existing = $this->repo->get("changes", $id);
            if ($existing) {
                return $this->recover($existing, $lock, $key);
            }
            $d = $original["data"];
            $before = ProductState::read((int) $original["product_id"]);
            if (ProductState::hash($before) !== $d["after_hash"]) {
                throw new \RuntimeException("rollback_price_conflict");
            }
            $m = $this->repo->get("mappings", $original["product_id"]);
            $rule = $this->repo->get("rules", $m["rule_version"])["data"];
            $currency = Settings::currency();
            $restored = Money::parse($d["before"]["effective"], $currency);
            $floor = Money::parse($rule["floor"], $rule["currency"]);
            $ceiling = Money::parse($rule["ceiling"], $rule["currency"]);
            try {
                $floor = max($floor, ProfitFloor::calculate($rule, time()));
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException("rollback_" . $e->getMessage());
            }
            if ($restored < $floor || $restored > $ceiling) {
                throw new \RuntimeException("rollback_current_bounds_conflict");
            }
            $r = [
                "before" => $before,
                "after" => $d["before"],
                "before_hash" => ProductState::hash($before),
                "after_hash" => $d["before_hash"],
                "rollback_of" => $changeId,
                "current_rule_version" => $m["rule_version"],
                "actor" => get_current_user_id(),
                "currency" => $currency,
                "delta_minor" => abs(
                    Money::parse($before["effective"], $currency) - $restored,
                ),
                "correlation_id" => wp_generate_uuid4(),
                "is_test" => $d["is_test"],
            ];
            $this->repo->insert("changes", [
                "id" => $id,
                "product_id" => $original["product_id"],
                "proposal_id" => "",
                "data" => $r,
                "status" => "intent",
                "created_at" => time(),
            ]);
            $this->write((int) $original["product_id"], $r, $lock, $key, true);
            $this->repo->update("changes", $id, ["status" => "applied"]);
            return $id;
        } finally {
            $lock->release($key);
        }
    }
    public function manualEdit($product): void
    {
        if (self::$writing || !$product->get_id()) {
            return;
        }
        $fields = array_keys($product->get_changes());
        if (
            !array_intersect($fields, [
                "regular_price",
                "sale_price",
                "price",
                "date_on_sale_from",
                "date_on_sale_to",
            ])
        ) {
            return;
        }
        $row = $this->repo->get("mappings", $product->get_id());
        if (!$row) {
            return;
        }
        $d = $row["data"];
        $d["manual_lock_until"] = time() + 86400;
        $this->repo->update("mappings", $product->get_id(), [
            "data" => $d,
            "status" => "manual_lock",
        ]);
    }
}
