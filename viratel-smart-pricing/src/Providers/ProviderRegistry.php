<?php
namespace Viratel\SmartPricing\Providers;
final class ProviderRegistry
{
    public function get(string $id): Provider
    {
        if ($id === "torob") {
            return new TorobProvider();
        }
        throw new \InvalidArgumentException("provider_unconfigured");
    }
    public function statuses(): array
    {
        return [
            "torob" => (new TorobProvider())->status(),
            "emalls" => ["status" => "unconfigured"],
            "supplier" => ["status" => "unconfigured"],
            "currency" => ["status" => "unconfigured"],
            "basalam" => ["status" => "unconfigured"],
            "tapsishop" => ["status" => "unconfigured"],
            "worker" => ["status" => "unconfigured"],
        ];
    }
}
