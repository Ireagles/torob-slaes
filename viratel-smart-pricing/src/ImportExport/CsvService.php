<?php
namespace Viratel\SmartPricing\ImportExport;
use Viratel\SmartPricing\Infrastructure\{Repository, MappingService, Lock};
use Viratel\SmartPricing\Domain\Money;
use Viratel\SmartPricing\Security\SourceUrl;
final class CsvService
{
    const HEADERS = [
        "product_id",
        "variation_id",
        "sku",
        "provider",
        "source_url",
        "quality",
        "model",
        "condition",
        "color",
        "frame",
        "technical_code",
        "match_status",
        "mode",
        "floor_toman",
        "ceiling_toman",
        "cost_toman",
        "cost_observed_at",
        "rule_type",
        "delta_value",
        "delta_unit",
        "schedule_group",
    ];
    public function stage(string $text): string
    {
        if (strlen($text) > 2097152) {
            throw new \InvalidArgumentException("csv_too_large");
        }
        $stream = fopen("php://temp", "r+");
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', "", $text));
        rewind($stream);
        $headers = fgetcsv($stream, 0, ",", '"', "");
        if (!$headers || count(array_unique($headers)) !== count($headers)) {
            throw new \InvalidArgumentException("csv_headers_invalid");
        }
        foreach (
            ["source_url", "quality", "floor_toman", "ceiling_toman"]
            as $h
        ) {
            if (!in_array($h, $headers, true)) {
                throw new \InvalidArgumentException("csv_missing_" . $h);
            }
        }
        if (!array_intersect(["product_id", "variation_id", "sku"], $headers)) {
            throw new \InvalidArgumentException("csv_identity_required");
        }
        $rows = [];
        $line = 1;
        while (($values = fgetcsv($stream, 0, ",", '"', "")) !== false) {
            $line++;
            if ($values === [null]) {
                continue;
            }
            if (count($rows) >= 10000) {
                throw new \InvalidArgumentException("csv_row_limit");
            }
            if (count($values) !== count($headers)) {
                $rows[] = ["line" => $line, "error" => "column_count"];
            } else {
                $rows[] = [
                    "line" => $line,
                    "input" => array_combine($headers, $values),
                ];
            }
        }
        fclose($stream);
        if (!$rows) {
            throw new \InvalidArgumentException("csv_empty");
        }
        $id = wp_generate_uuid4();
        (new Repository())->insert("imports", [
            "id" => $id,
            "data" => [
                "rows" => $rows,
                "cursor" => 0,
                "seen" => [],
                "errors" => [],
                "actor" => get_current_user_id(),
                "created_at" => time(),
                "backups" => [],
            ],
            "status" => "validating",
            "created_at" => time(),
        ]);
        return $id;
    }
    public function validateChunk(string $id): array
    {
        $repo = new Repository();
        $key = "import:" . $id;
        $lock = new Lock();
        if (!$lock->acquire($key, 120)) {
            throw new \RuntimeException("import_busy");
        }
        try {
            $job = $repo->get("imports", $id);
            if (!$job) {
                throw new \RuntimeException("import_missing");
            }
            $d = $job["data"];
            if ($job["status"] !== "validating") {
                return $job;
            }
            $end = min(count($d["rows"]), $d["cursor"] + 100);
            for ($i = $d["cursor"]; $i < $end; $i++) {
                try {
                    if (isset($d["rows"][$i]["error"])) {
                        throw new \InvalidArgumentException(
                            $d["rows"][$i]["error"],
                        );
                    }
                    $candidate = $this->candidate($d["rows"][$i]["input"]);
                    $pid = $candidate["id"];
                    if (isset($d["seen"][$pid])) {
                        throw new \InvalidArgumentException(
                            "duplicate_product",
                        );
                    }
                    $d["seen"][$pid] = true;
                    $d["rows"][$i]["candidate"] = $candidate;
                } catch (\Throwable $e) {
                    $d["rows"][$i]["error"] = $e->getMessage();
                    $d["errors"][] = [
                        "line" => $d["rows"][$i]["line"],
                        "reason" => $e->getMessage(),
                    ];
                }
            }
            $d["cursor"] = $end;
            $status =
                $end === count($d["rows"])
                    ? (count($d["errors"])
                        ? "invalid"
                        : "validated")
                    : "validating";
            $repo->update("imports", $id, ["data" => $d, "status" => $status]);
            return $repo->get("imports", $id);
        } finally {
            $lock->release($key);
        }
    }
    private function candidate(array $r): array
    {
        $parseId = function ($v) {
            if ($v === "") {
                return 0;
            }
            $v = Money::normalize($v);
            if (!ctype_digit($v) || strlen($v) > 10) {
                throw new \InvalidArgumentException("invalid_id");
            }
            return (int) $v;
        };
        $parent = $parseId($r["product_id"] ?? "");
        $variation = $parseId($r["variation_id"] ?? "");
        $id = $variation ?: $parent;
        $sku = $r["sku"] ?? "";
        if ($sku !== "") {
            $fromSku = (int) wc_get_product_id_by_sku($sku);
            if (!$fromSku || ($id && $id !== $fromSku)) {
                throw new \InvalidArgumentException("sku_id_conflict");
            }
            $id = $fromSku;
        }
        $p = wc_get_product($id);
        if (!$p || !in_array($p->get_type(), ["simple", "variation"], true)) {
            throw new \InvalidArgumentException(
                "product_missing_or_variable_parent",
            );
        }
        if (
            $variation &&
            (!$p->is_type("variation") ||
                ($parent && $p->get_parent_id() !== $parent))
        ) {
            throw new \InvalidArgumentException("variation_parent_conflict");
        }
        if (($r["provider"] ?? "torob") !== "torob") {
            throw new \InvalidArgumentException("provider_unconfigured");
        }
        SourceUrl::validate($r["source_url"]);
        $repo = new Repository();
        $old = $repo->get("mappings", $id);
        $attributes = $old["data"]["attributes"] ?? [];
        foreach (
            [
                "quality",
                "model",
                "condition",
                "color",
                "frame",
                "technical_code",
            ]
            as $field
        ) {
            if (($r[$field] ?? "") !== "") {
                $attributes[$field] = sanitize_text_field($r[$field]);
            }
        }
        $mapping = [
            "source_url" => $r["source_url"],
            "attributes" => $attributes,
            "match_status" => $r["match_status"] ?? "pending",
            "mode" => $r["mode"] ?? "monitor",
            "enabled" => true,
            "schedule_group" => $r["schedule_group"] ?? "" ?: "normal",
        ];
        if (
            !in_array(
                $mapping["match_status"],
                ["pending", "confirmed"],
                true,
            ) ||
            !in_array($mapping["mode"], ["monitor", "suggest", "auto"], true)
        ) {
            throw new \InvalidArgumentException("invalid_mode_or_match");
        }
        if (
            $mapping["match_status"] === "confirmed" &&
            (empty($attributes["quality"]) || empty($attributes["model"]))
        ) {
            throw new \InvalidArgumentException("quality_model_required");
        }
        if (!$repo->get("groups", $mapping["schedule_group"])) {
            throw new \InvalidArgumentException("group_missing");
        }
        $floor = Money::parse($r["floor_toman"], "IRT");
        $ceiling = Money::parse($r["ceiling_toman"], "IRT");
        if ($floor <= 0 || $floor > $ceiling) {
            throw new \InvalidArgumentException("invalid_bounds");
        }
        $type = $r["rule_type"] ?? "" ?: "fixed_below";
        if (
            !in_array(
                $type,
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
        $rule = [
            "currency" => "IRT",
            "floor" => Money::format($floor, "IRT"),
            "ceiling" => Money::format($ceiling, "IRT"),
            "type" => $type,
            "basis" => "min",
            "round_step" => "1",
            "max_change_bps" => 1000,
            "max_daily_change" => "50000",
            "cooldown" => 3600,
            "min_competitors" => 2,
        ];
        if (strpos($type, "percent_") === 0) {
            if (($r["delta_unit"] ?? "") !== "percent") {
                throw new \InvalidArgumentException("percent_unit_required");
            }
            $bps = Money::parse($r["delta_value"] ?? "" ?: "0", "IRR");
            if ($bps > 10000) {
                throw new \InvalidArgumentException("invalid_percent");
            }
            $rule["delta_bps"] = $bps;
        } else {
            if (($r["delta_unit"] ?? "toman") !== "toman") {
                throw new \InvalidArgumentException("toman_unit_required");
            }
            $rule["delta"] = Money::format(
                Money::parse($r["delta_value"] ?? "" ?: "1000", "IRT"),
                "IRT",
            );
        }
        if (($r["cost_toman"] ?? "") !== "") {
            $rule["cost"] = Money::format(
                Money::parse($r["cost_toman"], "IRT"),
                "IRT",
            );
            $costTime = $r["cost_observed_at"] ?? "";
            if (
                $costTime !== "" &&
                !preg_match('/^[0-9]{1,10}$/D', $costTime)
            ) {
                throw new \InvalidArgumentException("invalid_cost_observed_at");
            }
            $rule["cost_observed_at"] = $costTime === "" ? 0 : (int) $costTime;
            $rule["margin_bps"] = 0;
        }
        return [
            "id" => $id,
            "mapping" => $mapping,
            "rule" => $rule,
            "expected_version" => $old["rule_version"] ?? "",
            "expected_mapping_hash" => $old
                ? hash("sha256", $old["config"])
                : "",
            "previous" => $old
                ? array_intersect_key(
                    $old,
                    array_flip([
                        "source_key",
                        "group_key",
                        "rule_version",
                        "data",
                        "status",
                    ]),
                )
                : null,
        ];
    }
    public function applyChunk(string $id): array
    {
        $repo = new Repository();
        $key = "import:" . $id;
        $lock = new Lock();
        if (!$lock->acquire($key, 120)) {
            throw new \RuntimeException("import_busy");
        }
        try {
            $job = $repo->get("imports", $id);
            if (
                !$job ||
                !in_array($job["status"], ["validated", "applying"], true)
            ) {
                throw new \RuntimeException("dry_run_required");
            }
            $d = $job["data"];
            if ($job["status"] === "validated") {
                $d["cursor"] = 0;
                $d["applied"] = [];
            }
            $end = min(count($d["rows"]), $d["cursor"] + 50);
            for ($i = $d["cursor"]; $i < $end; $i++) {
                $c = $d["rows"][$i]["candidate"];
                $current = $repo->get("mappings", $c["id"]);
                if (isset($d["applied"][$c["id"]])) {
                    continue;
                }
                // Recovery of a crash after a row write is keyed by this import marker.
                if (($current["data"]["import_id"] ?? "") === $id) {
                    $d["applied"][$c["id"]] = $current["rule_version"];

                    $d["cursor"] = $i + 1;
                    continue;
                }
                if (
                    ($current ? hash("sha256", $current["config"]) : "") !==
                    $c["expected_mapping_hash"]
                ) {
                    throw new \RuntimeException("import_mapping_conflict");
                }
                $c["mapping"]["import_id"] = $id;
                for ($retry = 0; $retry < 3; $retry++) {
                    $beforeAttempt = $d;
                    try {
                        (new MappingService())->save(
                            $c["id"],
                            $c["mapping"],
                            $c["rule"],
                            $c["expected_version"],
                            $c["expected_mapping_hash"],
                            function ($version) use (&$d, $c, $i, $repo, $id) {
                                do_action("vsp_test_import_after_mapping", $id);
                                $d["applied"][$c["id"]] = $version;
                                $d["cursor"] = $i + 1;
                                $repo->update("imports", $id, [
                                    "data" => $d,
                                    "status" => "applying",
                                ]);
                            },
                        );
                        break;
                    } catch (\RuntimeException $e) {
                        $d = $beforeAttempt;
                        if (
                            $e->getMessage() !== "database_retryable" ||
                            $retry === 2
                        ) {
                            throw $e;
                        }
                        usleep(random_int(10000, 50000));
                    }
                }
            }
            $d["cursor"] = $end;
            $repo->update("imports", $id, [
                "data" => $d,
                "status" => $end === count($d["rows"]) ? "applied" : "applying",
            ]);
            return $repo->get("imports", $id);
        } finally {
            $lock->release($key);
        }
    }
    /** Restore mapping settings only; this is deliberately separate from price rollback. */
    public function rollbackChunk(string $id): array
    {
        $repo = new Repository();
        $key = "import:" . $id;
        $lock = new Lock();
        if (!$lock->acquire($key, 120)) {
            throw new \RuntimeException("import_busy");
        }
        try {
            $job = $repo->get("imports", $id);
            if (
                !$job ||
                !in_array(
                    $job["status"],
                    ["applied", "rolling_back", "rolled_back"],
                    true,
                )
            ) {
                throw new \RuntimeException("import_not_applied");
            }
            if ($job["status"] === "rolled_back") {
                return $job;
            }
            $d = $job["data"];
            $keys = array_keys($d["applied"]);
            $cursor = (int) ($d["rollback_cursor"] ?? 0);
            $end = min(count($keys), $cursor + 50);
            for ($i = $cursor; $i < $end; $i++) {
                $pid = (int) $keys[$i];
                $productKey = "product:" . $pid;
                $lease = new Lock();
                if (!$lease->acquire($productKey)) {
                    throw new \RuntimeException("product_busy");
                }
                try {
                    $current = $repo->get("mappings", $pid);
                    if (
                        ($current["data"]["import_id"] ?? "") !== $id ||
                        ($current["rule_version"] ?? "") !== $d["applied"][$pid]
                    ) {
                        throw new \RuntimeException("import_rollback_conflict");
                    }
                    $backup = $d["backups"][$pid] ?? null;
                    if (!$backup) {
                        foreach ($d["rows"] as $entry) {
                            if (($entry["candidate"]["id"] ?? 0) === $pid) {
                                $backup = $entry["candidate"]["previous"];
                                break;
                            }
                        }
                    }
                    global $wpdb;
                    $wpdb->query("START TRANSACTION");
                    try {
                        if (!$backup) {
                            global $wpdb;
                            $wpdb->delete($repo->table("mappings"), [
                                "product_id" => $pid,
                            ]);
                        } else {
                            $data = $backup["data"];
                            $data["manual_lock_until"] =
                                $current["data"]["manual_lock_until"] ?? 0;
                            $repo->update("mappings", $pid, [
                                "source_key" => $backup["source_key"],
                                "group_key" => $backup["group_key"],
                                "rule_version" => $backup["rule_version"],
                                "data" => $data,
                                "status" => $backup["status"],
                                "next_due" => time(),
                                "updated_at" => time(),
                            ]);
                        }
                        $d["rollback_cursor"] = $i + 1;
                        $repo->update("imports", $id, [
                            "data" => $d,
                            "status" => "rolling_back",
                        ]);
                        $wpdb->query("COMMIT");
                    } catch (\Throwable $e) {
                        $wpdb->query("ROLLBACK");
                        throw $e;
                    }
                } finally {
                    $lease->release($productKey);
                }
            }
            $repo->update("imports", $id, [
                "data" => $d,
                "status" =>
                    $end === count($keys) ? "rolled_back" : "rolling_back",
            ]);
            return $repo->get("imports", $id);
        } finally {
            $lock->release($key);
        }
    }
    public static function escapeCell(string $value): string
    {
        return preg_match("/^[\s]*[=+\-@]/u", $value) ? "'" . $value : $value;
    }
    public function export(): void
    {
        $f = fopen("php://output", "w");
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, self::HEADERS, ",", '"', "");
        $repo = new Repository();
        $page = 1;
        do {
            $rows = $repo->page("mappings", $page++, 100);
            foreach ($rows as $row) {
                $m = $row["data"];
                $rule = $repo->get("rules", $row["rule_version"])["data"];
                $p = wc_get_product($row["product_id"]);
                $percent = strpos($rule["type"], "percent_") === 0;
                $r = [
                    "product_id" =>
                        $p && $p->is_type("variation")
                            ? $p->get_parent_id()
                            : $row["product_id"],
                    "variation_id" =>
                        $p && $p->is_type("variation")
                            ? $row["product_id"]
                            : "",
                    "sku" => $m["sku"],
                    "provider" => "torob",
                    "source_url" => $m["source_url"],
                    "match_status" => $m["match_status"],
                    "mode" => $m["mode"],
                    "floor_toman" => Money::format(
                        Money::parse($rule["floor"], $rule["currency"]),
                        "IRT",
                    ),
                    "ceiling_toman" => Money::format(
                        Money::parse($rule["ceiling"], $rule["currency"]),
                        "IRT",
                    ),
                    "cost_toman" => isset($rule["cost"])
                        ? Money::format(
                            Money::parse($rule["cost"], $rule["currency"]),
                            "IRT",
                        )
                        : "",
                    "cost_observed_at" => $rule["cost_observed_at"] ?? "",
                    "rule_type" => $rule["type"],
                    "delta_value" => $percent
                        ? Money::format($rule["delta_bps"], "IRR")
                        : Money::format(
                            Money::parse(
                                $rule["delta"] ?? "0",
                                $rule["currency"],
                            ),
                            "IRT",
                        ),
                    "delta_unit" => $percent ? "percent" : "toman",
                    "schedule_group" => $row["group_key"],
                ];
                $r = array_merge($r, $m["attributes"]);
                $cells = [];
                foreach (self::HEADERS as $h) {
                    $cells[] = self::escapeCell((string) ($r[$h] ?? ""));
                }
                fputcsv($f, $cells, ",", '"', "");
            }
        } while (count($rows) === 100);
        fclose($f);
    }
}
