<?php
namespace Viratel\SmartPricing\Domain;
final class RuleResolver
{
    public function resolve(
        array $candidates,
        bool $paused = false,
        bool $locked = false,
    ): array {
        if ($paused || $locked) {
            return [
                "status" => "hold",
                "reason" => $paused ? "paused" : "manual_lock",
            ];
        }
        $levels = [
            "product" => 0,
            "group" => 1,
            "category" => 2,
            "brand" => 3,
            "default" => 4,
        ];
        usort($candidates, function ($a, $b) use ($levels) {
            return [
                $levels[$a["scope"]] ?? 99,
                -(int) ($a["priority"] ?? 0),
            ] <=> [$levels[$b["scope"]] ?? 99, -(int) ($b["priority"] ?? 0)];
        });
        if (!$candidates) {
            return ["status" => "hold", "reason" => "rule_missing"];
        }
        $first = $candidates[0];
        foreach (array_slice($candidates, 1) as $c) {
            if (
                $c["scope"] === $first["scope"] &&
                ($c["priority"] ?? 0) === ($first["priority"] ?? 0) &&
                $c["version"] !== $first["version"]
            ) {
                return ["status" => "hold", "reason" => "rule_conflict"];
            }
        }
        return [
            "status" => "ok",
            "rule" => $first,
            "reason" => "resolved_" . $first["scope"],
        ];
    }
}
