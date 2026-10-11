<?php
namespace Viratel\SmartPricing\Domain;

/** Shared by proposals and rollback: the same current cost/profit constraints apply. */
final class ProfitFloor
{
    public static function calculate(array $rule, int $now): int
    {
        if (!isset($rule["cost"]) || $rule["cost"] === "") {
            return 0;
        }
        $observed = (int) ($rule["cost_observed_at"] ?? 0);
        if (
            !$observed ||
            $observed > $now + 60 ||
            $now - $observed > (int) ($rule["max_cost_age"] ?? 86400)
        ) {
            throw new \InvalidArgumentException("cost_stale");
        }
        $cost = Money::parse($rule["cost"], $rule["currency"] ?? "IRT");
        $margin = (int) ($rule["margin_bps"] ?? 0);
        $fee = (int) ($rule["fee_bps"] ?? 0);
        $markup = (int) ($rule["markup_bps"] ?? 0);
        if (
            $margin < 0 ||
            $fee < 0 ||
            $margin + $fee >= 10000 ||
            $markup < 0 ||
            $markup > 100000 ||
            ($margin && $markup)
        ) {
            throw new \InvalidArgumentException("invalid_profit");
        }
        return $markup
            ? Money::ratio($cost, 10000 + $markup, 10000 - $fee, true)
            : Money::ratio($cost, 10000, 10000 - $margin - $fee, true);
    }
}
