<?php
/**
 * Plugin Name: Viratel Smart Pricing
 * Description: پایش و پیشنهاد قیمت رقابتی قطعات موبایل با محدودیت‌های ایمن.
 * Version: 0.1.0
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Author: Viratel
 * License: GPL-3.0-or-later
 * Text Domain: viratel-smart-pricing
 */
if (!defined("ABSPATH")) {
    exit();
}
define("VSP_VERSION", "0.1.0");
define("VSP_FILE", __FILE__);
spl_autoload_register(function ($class) {
    $prefix = "Viratel\\SmartPricing\\";
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file =
        __DIR__ .
        "/src/" .
        str_replace("\\", "/", substr($class, strlen($prefix))) .
        ".php";
    if (is_file($file)) {
        require $file;
    }
});
register_activation_hook(__FILE__, [
    "Viratel\\SmartPricing\\Infrastructure\\Installer",
    "activate",
]);
register_deactivation_hook(__FILE__, [
    "Viratel\\SmartPricing\\Infrastructure\\Installer",
    "deactivate",
]);
add_action(
    "plugins_loaded",
    function () {
        if (!class_exists("WooCommerce")) {
            add_action("admin_notices", function () {
                echo '<div class="notice notice-error"><p>ویراتل: برای اجرا، WooCommerce را نصب و فعال کنید.</p></div>';
            });
            return;
        }
        Viratel\SmartPricing\Infrastructure\Installer::migrate();
        (new Viratel\SmartPricing\Infrastructure\Plugin())->boot();
    },
    30,
);
