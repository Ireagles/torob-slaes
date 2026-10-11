<?php
namespace Viratel\SmartPricing\Infrastructure;
final class Settings
{
    public static function get(bool $fresh = false): array
    {
        if (!$fresh) {
            return (array) get_option("vsp_settings", []);
        }
        global $wpdb;
        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",
                "vsp_settings",
            ),
        );
        return $value ? (array) maybe_unserialize($value) : [];
    }
    public static function currency(): string
    {
        $settings = self::get(true);
        $configured = $settings["currency"] ?? "";
        global $wpdb;
        $actual = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",
                "woocommerce_currency",
            ),
        );
        if (
            !in_array($configured, ["IRT", "IRR"], true) ||
            $configured !== $actual
        ) {
            throw new \RuntimeException("store_currency_mismatch");
        }
        return $configured;
    }
    public static function writesAllowed(bool $manual = false): bool
    {
        $s = self::get(true);
        global $wpdb;
        $active = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",
                "vsp_active",
            ),
        );
        return (bool) $active &&
            empty($s["paused"]) &&
            ($s["mode"] ?? "monitor") !== "monitor" &&
            ($manual || ($s["mode"] ?? "") === "auto");
    }
}
