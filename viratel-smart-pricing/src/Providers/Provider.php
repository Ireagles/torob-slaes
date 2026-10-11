<?php
namespace Viratel\SmartPricing\Providers;
interface Provider
{
    public function id(): string;
    public function fetch(string $url): array;
    public function status(): array;
}
