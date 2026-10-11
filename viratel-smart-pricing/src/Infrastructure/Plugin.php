<?php
namespace Viratel\SmartPricing\Infrastructure;
final class Plugin
{
    public function boot(): void
    {
        (new \Viratel\SmartPricing\Queue\QueueService())->register();
        $applier = new PriceApplier();
        add_action(
            "woocommerce_before_product_object_save",
            [$applier, "manualEdit"],
            10,
            1,
        );
        add_action(
            "woocommerce_before_product_variation_object_save",
            [$applier, "manualEdit"],
            10,
            1,
        );
        (new \Viratel\SmartPricing\Admin\AdminUI())->register();
        if (defined("WP_CLI") && WP_CLI) {
            \WP_CLI::add_command(
                "vsp",
                new \Viratel\SmartPricing\CLI\Commands(),
            );
        }
    }
}
