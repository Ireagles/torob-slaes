<?php
namespace Viratel\SmartPricing\Providers;
use Viratel\SmartPricing\Security\SourceUrl;
final class FetchClient
{
    public function get(string $url): array
    {
        SourceUrl::validate($url);
        for ($i = 0; $i < 4; $i++) {
            $budget = new \Viratel\SmartPricing\Queue\BudgetService();
            try {
                $usageId = $budget->reserve();
            } catch (\RuntimeException $e) {
                return [
                    "status" => $e->getMessage(),
                    "http_status" => 0,
                    "retry_after" => 60,
                ];
            }
            $started = microtime(true);
            $response = wp_safe_remote_get($url, [
                "timeout" => 15,
                "redirection" => 0,
                "sslverify" => true,
                "limit_response_size" => 1048577,
                "user-agent" =>
                    "ViratelSmartPricing/" .
                    VSP_VERSION .
                    " public read-only monitoring",
            ]);
            $budget->finish(
                $usageId,
                is_wp_error($response)
                    ? "network_error"
                    : "http_" . wp_remote_retrieve_response_code($response),
                (int) ((microtime(true) - $started) * 1000),
            );
            if (is_wp_error($response)) {
                return [
                    "status" => "network_error",
                    "http_status" => 0,
                    "retry_after" => 60,
                ];
            }
            $code = wp_remote_retrieve_response_code($response);
            if (in_array($code, [301, 302, 303, 307, 308], true)) {
                $location = wp_remote_retrieve_header($response, "location");
                if (
                    strpos($location, "/") === 0 &&
                    strpos($location, "//") !== 0
                ) {
                    $location = "https://torob.com" . $location;
                }
                try {
                    SourceUrl::validate($location);
                } catch (\InvalidArgumentException $e) {
                    return [
                        "status" => "redirect_forbidden",
                        "http_status" => $code,
                    ];
                }
                if (
                    SourceUrl::validate($url) !== SourceUrl::validate($location)
                ) {
                    return [
                        "status" => "canonical_redirect_conflict",
                        "http_status" => $code,
                    ];
                }
                // A redirect is a separate short job so each actual request respects spacing.
                return [
                    "status" => "redirect_pending",
                    "http_status" => $code,
                    "redirect_url" => $location,
                    "retry_after" => 10,
                ];
            }
            $body = wp_remote_retrieve_body($response);
            $age = wp_remote_retrieve_header($response, "age");
            if (strlen($body) > 1048576) {
                return [
                    "status" => "response_too_large",
                    "http_status" => $code,
                ];
            }
            if ($code !== 200) {
                $retry = wp_remote_retrieve_header($response, "retry-after");
                $seconds = ctype_digit((string) $retry)
                    ? (int) $retry
                    : max(0, (strtotime($retry) ?: 0) - time());
                return [
                    "status" => "http_" . $code,
                    "http_status" => $code,
                    "retry_after" => max(60, min(86400, $seconds)),
                ];
            }
            return [
                "status" => "ok",
                "http_status" => 200,
                "body" => $body,
                "cache_age_seconds" => ctype_digit((string) $age)
                    ? (int) $age
                    : null,
                "source_url" => $url,
                "usage_id" => $usageId,
            ];
        }
        return ["status" => "redirect_limit", "http_status" => 0];
    }
}
