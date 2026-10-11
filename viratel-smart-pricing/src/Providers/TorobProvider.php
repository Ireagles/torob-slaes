<?php
namespace Viratel\SmartPricing\Providers;
use Viratel\SmartPricing\Security\SourceUrl;
use Viratel\SmartPricing\Domain\Money;

/** Public HTML JSON-LD only. It supplies real prices, NOT verified freshness or quality. */
final class TorobProvider implements Provider
{
    public function id(): string
    {
        return "torob";
    }
    public function status(): array
    {
        return [
            "monitoring" => "public_jsonld",
            "automatic" => "blocked",
            "reason" => "observation_time_quality_and_identity_unverified",
        ];
    }
    public function fetch(string $url): array
    {
        $id = SourceUrl::validate($url);
        $r = (new FetchClient())->get($url);
        $base = [
            "provider" => "torob",
            "canonical_product_id" => $id,
            "source_url" => $r["source_url"] ?? $url,
            "fetched_at" => gmdate("c"),
            "source_observed_at" => null,
            "cache_age_seconds" => $r["cache_age_seconds"] ?? null,
            "freshness" => "unknown",
            "coverage" => "partial",
            "currency" => "IRR",
            "offers" => [],
            "status" => $r["status"],
            "billable_units" => null,
            "parser_version" => "public-jsonld-1",
            "is_test" => false,
            "http_status" => $r["http_status"],
        ];
        if ($r["status"] !== "ok") {
            if (isset($r["redirect_url"])) {
                $base["redirect_url"] = $r["redirect_url"];
            }
            if (isset($r["retry_after"])) {
                $base["retry_after"] = $r["retry_after"];
            }
            return $base;
        }
        $parsed = $this->parse($r["body"], $base);
        if (isset($r["usage_id"])) {
            (new \Viratel\SmartPricing\Infrastructure\Repository())->update(
                "usage",
                $r["usage_id"],
                ["status" => $parsed["status"]],
            );
        }
        return $parsed;
    }
    public function parse(string $html, array $base): array
    {
        if (
            preg_match(
                "/<title[^>]*>[^<]*(captcha|access denied|ورود)/iu",
                $html,
            )
        ) {
            $base["status"] = "challenge_or_login";
            return $base;
        }
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $product = null;
        foreach ($doc->getElementsByTagName("script") as $script) {
            if (
                strtolower($script->getAttribute("type")) ===
                "application/ld+json"
            ) {
                $d = json_decode($script->textContent, true);
                if (is_array($d) && ($d["@type"] ?? "") === "Product") {
                    $product = $d;
                    break;
                }
            }
        }
        if (
            !$product ||
            ($product["sku"] ?? "") !== $base["canonical_product_id"]
        ) {
            $base["status"] = "parser_schema_error";
            return $base;
        }
        $offers = $product["offers"]["offers"] ?? [];
        if (!is_array($offers) || count($offers) > 500) {
            $base["status"] = "parser_schema_error";
            return $base;
        }
        foreach ($offers as $i => $o) {
            if (!is_array($o)) {
                $base["status"] = "parser_schema_error";
                $base["offers"] = [];
                return $base;
            }
            try {
                if (
                    Money::parse(
                        $o["price"] ?? "",
                        $o["priceCurrency"] ?? "",
                    ) <= 0
                ) {
                    throw new \InvalidArgumentException("invalid_price");
                }
            } catch (\InvalidArgumentException $e) {
                $base["status"] = $e->getMessage();
                $base["offers"] = [];
                return $base;
            }
            // Redirect tracking URLs and session identifiers are deliberately not stored or followed.
            $base["offers"][] = [
                "offer_id" => hash(
                    "sha256",
                    $base["canonical_product_id"] .
                        "|" .
                        $i .
                        "|" .
                        ($o["name"] ?? ""),
                ),
                "store_id" => null,
                "seller_name" => sanitize_text_field($o["name"] ?? ""),
                "seller_url" => "",
                "price" => (string) $o["price"],
                "currency" => $o["priceCurrency"],
                "in_stock" =>
                    ($o["availability"] ?? "") === "http://schema.org/InStock"
                        ? true
                        : null,
                "observed_at" => null,
                "guaranteed" => null,
                "attributes" => [],
                "shipping" => null,
                "confidence" => [
                    "level" => "unverified",
                    "reasons" => [
                        "seller_identity_unknown",
                        "quality_unknown",
                        "source_time_unknown",
                    ],
                ],
            ];
        }
        $base["status"] = count($base["offers"]) ? "ok" : "no_offers";
        $base["source_title"] = sanitize_text_field($product["name"] ?? "");
        $base["coverage_notes"] = [
            "public_jsonld_subset",
            "not_all_sellers",
            "no_pagination_claim",
        ];
        return $base;
    }
}
