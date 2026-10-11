<?php
if (!defined("WP_UNINSTALL_PLUGIN")) {
    exit();
}
$s = get_option("vsp_settings",
        "vsp_pricing_defaults", []);
if (empty($s["delete_on_uninstall"])) {
    return;
}
global $wpdb;
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
    $wpdb->query("DROP TABLE IF EXISTS " . $wpdb->prefix . "vsp_" . $table);
}
foreach (
    [
        "vsp_settings",
        "vsp_pricing_defaults",
        "vsp_schema",
        "vsp_active",
        "vsp_circuit_until",
        "vsp_provider_failures",
        "vsp_last_request",
    ]
    as $option
) {
    delete_option($option);
}
foreach (["administrator", "shop_manager"] as $name) {
    $role = get_role($name);
    if ($role) {
        foreach (["vsp_view", "vsp_manage", "vsp_apply"] as $cap) {
            $role->remove_cap($cap);
        }
    }
}
