<?php
namespace Viratel\SmartPricing\Domain;

final class PricingEngine
{
    public function calculate(
        array $snapshot,
        array $mapping,
        array $rule,
        array $context,
        int $now,
    ): array {
        $r = [
            "decision" => "hold",
            "amount" => null,
            "reasons" => [],
            "selected" => [],
            "exclusions" => [],
            "reference" => null,
            "limits" => [],
        ];
        $hold = function ($reason) use (&$r) {
            $r["reasons"][] = $reason;
            return $r;
        };
        try {
            if (!empty($context["paused"])) {
                return $hold("paused");
            }
            if (($mapping["manual_lock_until"] ?? 0) > $now) {
                return $hold("manual_lock");
            }
            if (
                ($mapping["match_status"] ?? "") !== "confirmed" ||
                empty($mapping["attributes"]["quality"]) ||
                empty($mapping["attributes"]["model"])
            ) {
                return $hold("match_unconfirmed");
            }
            if (($snapshot["status"] ?? "") !== "ok") {
                return $hold("source_error");
            }
            $observed = self::observationTime(
                $snapshot["source_observed_at"] ?? "",
            );
            if (
                ($snapshot["freshness"] ?? "") !== "known" ||
                !$observed ||
                $observed > $now + 60 ||
                $now - $observed > (int) ($rule["max_source_age"] ?? 21600)
            ) {
                return $hold("stale_or_unknown");
            }
            if (
                ($snapshot["coverage"] ?? "") !== "complete" &&
                empty($rule["allow_partial"])
            ) {
                return $hold("coverage_insufficient");
            }
            if (
                empty($context["own_domains"]) &&
                empty($context["own_store_ids"])
            ) {
                return $hold("own_identity_missing");
            }
            $currency = $rule["currency"] ?? "IRT";
            $floor = Money::parse($rule["floor"] ?? "", $currency);
            $ceiling = Money::parse($rule["ceiling"] ?? "", $currency);
            $floor = max($floor, ProfitFloor::calculate($rule, $now));
            if ($floor <= 0 || $ceiling < $floor) {
                return $hold("invalid_bounds");
            }
            $r["limits"] = ["floor" => $floor, "ceiling" => $ceiling];
            $ownDomains = array_map(
                [self::class, "host"],
                $context["own_domains"] ?? [],
            );
            $ownIds = array_map("strval", $context["own_store_ids"] ?? []);
            $unknownIdentity = false;
            foreach ($snapshot["offers"] ?? [] as $offer) {
                $reason = null;
                $id = (string) ($offer["offer_id"] ?? "");
                $store = (string) ($offer["store_id"] ?? "");
                $host = self::host($offer["seller_url"] ?? "");
                if (
                    ($host === "" || !$ownDomains) &&
                    ($store === "" || !$ownIds)
                ) {
                    $reason = "identity_unknown";
                    $unknownIdentity = true;
                } elseif (
                    ($host !== "" && in_array($host, $ownDomains, true)) ||
                    ($store !== "" && in_array($store, $ownIds, true))
                ) {
                    $reason = "self";
                } elseif (
                    in_array(
                        $store,
                        array_map("strval", $rule["blacklist"] ?? []),
                        true,
                    )
                ) {
                    $reason = "blacklisted";
                } elseif (
                    !empty($rule["whitelist"]) &&
                    !in_array(
                        $store,
                        array_map("strval", $rule["whitelist"]),
                        true,
                    )
                ) {
                    $reason = "not_whitelisted";
                } elseif (($offer["in_stock"] ?? null) !== true) {
                    $reason = "stock_unknown_or_unavailable";
                } elseif (
                    !empty($rule["require_guarantee"]) &&
                    ($offer["guaranteed"] ?? null) !== true
                ) {
                    $reason = "guarantee_unknown";
                } else {
                    foreach ($mapping["attributes"] as $key => $value) {
                        if (
                            $value !== "" &&
                            ($offer["attributes"][$key] ?? null) !== $value
                        ) {
                            $reason = "attribute_mismatch_" . $key;
                            break;
                        }
                    }
                    $offerTime = self::observationTime(
                        $offer["observed_at"] ?? "",
                    );
                    if (
                        !$reason &&
                        (!$offerTime ||
                            $offerTime > $now + 60 ||
                            $now - $offerTime >
                                (int) ($rule["max_source_age"] ?? 21600))
                    ) {
                        $reason = "offer_time_unknown_or_stale";
                    }
                }
                $price = null;
                if (!$reason) {
                    try {
                        $price = Money::parse(
                            $offer["price"] ?? "",
                            $offer["currency"] ?? "",
                        );
                        if ($price <= 0) {
                            $reason = "invalid_price";
                        }
                    } catch (\InvalidArgumentException $e) {
                        $reason = $e->getMessage();
                    }
                }
                if ($reason) {
                    $r["exclusions"][] = [
                        "offer_id" => $id,
                        "reason" => $reason,
                    ];
                } else {
                    $r["selected"][] = [
                        "offer_id" => $id,
                        "store_id" => $store,
                        "seller_name" => (string) ($offer["seller_name"] ?? ""),
                        "price" => $price,
                    ];
                }
            }
            if ($unknownIdentity) {
                return $hold("identity_ambiguous");
            }
            if (!$r["selected"]) {
                $r["decision"] = "no-op";
                return $hold("no_competitor");
            }
            $prices = array_column($r["selected"], "price");
            sort($prices, SORT_NUMERIC);
            $basis = $rule["basis"] ?? "min";
            if (!in_array($basis, ["min", "max", "mean"], true)) {
                return $hold("basis_unknown");
            }
            $b =
                $basis === "max"
                    ? max($prices)
                    : ($basis === "mean"
                        ? intdiv(array_sum($prices), count($prices))
                        : min($prices));
            $r["reference"] = $b;
            $type = $rule["type"] ?? "fixed_below";
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
                return $hold("rule_unknown");
            }
            $delta =
                strpos($type, "percent_") === 0
                    ? (int) ($rule["delta_bps"] ?? 0)
                    : Money::parse($rule["delta"] ?? "0", $currency);
            if (
                $delta < 0 ||
                (strpos($type, "percent_") === 0 && $delta > 10000)
            ) {
                return $hold("invalid_delta");
            }
            switch ($type) {
                case "fixed_below":
                    $target = max(0, $b - $delta);
                    break;
                case "fixed_above":
                    $target = $b + $delta;
                    break;
                case "percent_below":
                    $target = Money::ratio($b, 10000 - $delta, 10000);
                    break;
                case "percent_above":
                    $target = Money::ratio($b, 10000 + $delta, 10000, true);
                    break;
                case "cost_plus":
                    if (!isset($dynamic)) {
                        return $hold("cost_missing");
                    }
                    $target = $dynamic;
                    break;
                default:
                    $target = $b;
            }
            if ($target < $floor) {
                $r["reasons"][] = "floor_limited";
            }
            if ($target > $ceiling) {
                $r["reasons"][] = "ceiling_limited";
            }
            $target = max($floor, min($ceiling, $target));
            $step = Money::parse($rule["round_step"] ?? "1", $currency);
            if ($step <= 0) {
                return $hold("invalid_round_step");
            }
            $lo = (intdiv($floor, $step) + ($floor % $step ? 1 : 0)) * $step;
            $hi = intdiv($ceiling, $step) * $step;
            if ($lo > $hi) {
                return $hold("no_rounded_amount");
            }
            $target =
                strpos($type, "above") !== false
                    ? (intdiv($target, $step) + ($target % $step ? 1 : 0)) *
                        $step
                    : intdiv($target, $step) * $step;
            $target = max($lo, min($hi, $target));
            $r["amount"] = $target;
            $current = Money::parse(
                $context["current"] ?? "0",
                $context["currency"] ?? $currency,
            );
            $change = abs($target - $current);
            if (
                $change < Money::parse($rule["min_change"] ?? "1", $currency) ||
                $change === 0
            ) {
                $r["decision"] = "no-op";
                return $hold("negligible_change");
            }
            if (
                ($context["last_applied_at"] ?? 0) +
                    (int) ($rule["cooldown"] ?? 3600) >
                $now
            ) {
                return $hold("cooldown");
            }
            if (!empty($context["sale_conflict"])) {
                return $hold("sale_conflict");
            }
            if (
                $current > 0 &&
                $change >
                    Money::ratio(
                        $current,
                        (int) ($rule["max_change_bps"] ?? 1000),
                        10000,
                    )
            ) {
                return $hold("change_limit");
            }
            if (
                isset($rule["max_daily_change"]) &&
                (int) ($context["daily_change"] ?? 0) + $change >
                    Money::parse($rule["max_daily_change"], $currency)
            ) {
                return $hold("daily_change_limit");
            }
            if (count($prices) < (int) ($rule["min_competitors"] ?? 2)) {
                return $hold("single_competitor");
            }
            $median = $prices[intdiv(count($prices), 2)];
            if (
                min($prices) <
                Money::ratio(
                    $median,
                    10000 - (int) ($rule["outlier_bps"] ?? 3000),
                    10000,
                )
            ) {
                return $hold("outlier_review");
            }
            $r["decision"] = "apply";
            $r["reasons"][] = "valid_proposal";
            return $r;
        } catch (\InvalidArgumentException $e) {
            return $hold($e->getMessage());
        }
    }
    private static function observationTime($value)
    {
        if (
            !is_string($value) ||
            !preg_match(
                '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(?:Z|\+00:00)$/D',
                $value,
                $m,
            )
        ) {
            return false;
        }
        if (
            !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ||
            (int) $m[4] > 23 ||
            (int) $m[5] > 59 ||
            (int) $m[6] > 59
        ) {
            return false;
        }
        return strtotime($value);
    }
    public static function host(string $value): string
    {
        if ($value === "") {
            return "";
        }
        $url = strpos($value, "://") !== false ? $value : "https://" . $value;
        $p = parse_url($url);
        if (!$p || !empty($p["user"]) || !empty($p["pass"])) {
            return "";
        }
        $host = strtolower(rtrim($p["host"] ?? "", "."));
        return preg_match(
            '/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/D',
            $host,
        )
            ? $host
            : "";
    }
}
