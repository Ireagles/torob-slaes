<?php
namespace Viratel\SmartPricing\Security;
final class SourceUrl
{
    public static function validate(string $url): string
    {
        $p = parse_url($url);
        if (
            !$p ||
            ($p["scheme"] ?? "") !== "https" ||
            !in_array(
                strtolower($p["host"] ?? ""),
                ["torob.com", "www.torob.com"],
                true,
            ) ||
            isset($p["user"]) ||
            isset($p["pass"]) ||
            (isset($p["port"]) && $p["port"] !== 443) ||
            isset($p["query"]) ||
            isset($p["fragment"]) ||
            preg_match('/[\r\n\\\\]/', $url)
        ) {
            throw new \InvalidArgumentException("source_url_forbidden");
        }
        if (
            !preg_match(
                '~^/p/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})(/[^?#]*)?/?$~iD',
                $p["path"] ?? "",
                $m,
            )
        ) {
            throw new \InvalidArgumentException("source_product_required");
        }
        return strtolower($m[1]);
    }
}
