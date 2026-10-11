<?php
namespace Viratel\SmartPricing\Infrastructure;
final class Installer
{
    const SCHEMA = 1;
    public static function activate(): void
    {
        if (PHP_INT_SIZE !== 8) {
            wp_die("ویراتل نیازمند PHP با اعداد صحیح ۶۴ بیتی است.");
        }
        if (!class_exists("WooCommerce")) {
            wp_die("Viratel Smart Pricing نیازمند WooCommerce فعال است.");
        }
        self::migrate();
        foreach (["administrator", "shop_manager"] as $name) {
            $role = get_role($name);
            if ($role) {
                foreach (["vsp_view", "vsp_manage", "vsp_apply"] as $cap) {
                    $role->add_cap($cap);
                }
            }
        }
        add_option(
            "vsp_settings",
            [
                "mode" => "monitor",
                "paused" => false,
                "pause_monitor" => false,
                "source_configured" => false,
                "currency" => "",
                "own_domains" => [],
                "own_store_ids" => [],
                "daily_budget" => 100,
                "monthly_budget" => 3000,
                "quiet_start" => -1,
                "quiet_end" => -1,
                "delete_on_uninstall" => false,
            ],
            "",
            false,
        );
        update_option("vsp_active", true, false);
    }
    public static function migrate(): void
    {
        if ((int) get_option("vsp_schema", 0) >= self::SCHEMA) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . "wp-admin/includes/upgrade.php";
        $p = $wpdb->prefix . "vsp_";
        $c = $wpdb->get_charset_collate();
        $schemas = [
            "CREATE TABLE {$p}mappings (
product_id bigint unsigned NOT NULL,
source_key varchar(64) NOT NULL DEFAULT '',
group_key varchar(64) NOT NULL DEFAULT 'normal',
rule_version varchar(64) NOT NULL,
config longtext NOT NULL,
status varchar(64) NOT NULL DEFAULT 'disabled',
next_due bigint unsigned NOT NULL DEFAULT 0,
updated_at bigint unsigned NOT NULL,
PRIMARY KEY  (product_id),
KEY due (next_due,product_id),
KEY source (source_key),
KEY group_status (group_key,status)
) ENGINE=InnoDB $c;",
            "CREATE TABLE {$p}rules (
id varchar(64) NOT NULL,
config longtext NOT NULL,
created_at bigint unsigned NOT NULL,
PRIMARY KEY  (id)
) ENGINE=InnoDB $c;",
            "CREATE TABLE {$p}groups (
group_key varchar(64) NOT NULL,
config longtext NOT NULL,
PRIMARY KEY  (group_key)
) ENGINE=InnoDB $c;",
            "CREATE TABLE {$p}snapshots (
id varchar(64) NOT NULL,
source_key varchar(64) NOT NULL,
window_id bigint unsigned NOT NULL,
config longtext NOT NULL,
created_at bigint unsigned NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY canonical_window (source_key,window_id),
KEY retention (created_at)
) ENGINE=InnoDB $c;",
            "CREATE TABLE {$p}proposals (
id varchar(64) NOT NULL,
product_id bigint unsigned NOT NULL,
rule_version varchar(64) NOT NULL,
snapshot_id varchar(64) NOT NULL,
config longtext NOT NULL,
status varchar(32) NOT NULL,
created_at bigint unsigned NOT NULL,
PRIMARY KEY  (id),
KEY product_status (product_id,status),
KEY retention (created_at)
) ENGINE=InnoDB $c;",
            "CREATE TABLE {$p}changes (
id varchar(64) NOT NULL,
product_id bigint unsigned NOT NULL,
proposal_id varchar(64) NOT NULL,
config longtext NOT NULL,
status varchar(32) NOT NULL,
created_at bigint unsigned NOT NULL,
PRIMARY KEY  (id),
KEY product_time (product_id,created_at),
KEY status (status)
) ENGINE=InnoDB $c;",
            "CREATE TABLE {$p}usage (
id bigint unsigned NOT NULL AUTO_INCREMENT,
provider varchar(32) NOT NULL,
status varchar(64) NOT NULL,
latency_ms int unsigned NOT NULL DEFAULT 0,
billable_units int unsigned DEFAULT NULL,
created_at bigint unsigned NOT NULL,
PRIMARY KEY  (id),
KEY provider_time (provider,created_at)
) ENGINE=InnoDB $c;",
            "CREATE TABLE {$p}locks (
lock_key varchar(128) NOT NULL,
owner varchar(64) NOT NULL,
expires_at bigint unsigned NOT NULL,
PRIMARY KEY  (lock_key),
KEY expiry (expires_at)
) ENGINE=InnoDB $c;",
            "CREATE TABLE {$p}imports (
id varchar(64) NOT NULL,
config longtext NOT NULL,
status varchar(32) NOT NULL,
created_at bigint unsigned NOT NULL,
PRIMARY KEY  (id),
KEY retention (created_at)
) ENGINE=InnoDB $c;",
        ];
        foreach ($schemas as $sql) {
            dbDelta($sql);
        }
        foreach (
            [
                "mappings",
                "rules",
                "groups",
                "snapshots",
                "proposals",
                "changes",
                "usage",
                "locks",
                "imports",
            ]
            as $table
        ) {
            if (
                $wpdb->get_var(
                    $wpdb->prepare(
                        "SHOW TABLES LIKE %s",
                        $wpdb->esc_like($p . $table),
                    ),
                ) !==
                $p . $table
            ) {
                throw new \RuntimeException("schema_failed");
            }
        }
        update_option("vsp_schema", self::SCHEMA, false);
        foreach (["normal" => 21600, "sensitive" => 3600] as $key => $age) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$p}groups (group_key,config) VALUES (%s,%s)",
                    $key,
                    wp_json_encode([
                        "interval" => 21600,
                        "max_source_age" => $age,
                    ]),
                ),
            );
        }
    }
    public static function deactivate(): void
    {
        update_option("vsp_active", false, false);
        if (function_exists("as_unschedule_all_actions")) {
            foreach (
                [
                    "vsp_dispatch",
                    "vsp_poll",
                    "vsp_retention",
                    "vsp_import_validate",
                    "vsp_import_apply",
                    "vsp_import_rollback",
                ]
                as $hook
            ) {
                as_unschedule_all_actions($hook, null, "viratel-smart-pricing");
            }
        }
    }
}
