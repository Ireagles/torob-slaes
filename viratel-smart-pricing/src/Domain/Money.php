<?php
namespace Viratel\SmartPricing\Domain;

/** Exact integer amounts, unit = 0.01 IRR. No floating point money. */
final class Money
{
    const MAX = 1000000000000;
    public static function normalize(string $text): string
    {
        $text = strtr(
            trim($text),
            array_combine(
                preg_split(
                    "//u",
                    "۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩",
                    -1,
                    PREG_SPLIT_NO_EMPTY,
                ),
                str_split("01234567890123456789"),
            ),
        );
        $text = str_replace(["٫", "٬"], [".", ","], $text);
        if (strpos($text, ",") !== false) {
            if (!preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/D', $text)) {
                throw new \InvalidArgumentException("ambiguous_number");
            }
            $text = str_replace(",", "", $text);
        }
        if (!preg_match('/^\d+(\.\d+)?$/D', $text)) {
            throw new \InvalidArgumentException("invalid_amount");
        }
        return $text;
    }
    public static function parse($amount, string $currency): int
    {
        if (!is_string($amount) && !is_int($amount)) {
            throw new \InvalidArgumentException("float_or_invalid_amount");
        }
        $scale = ["IRR" => 2, "IRT" => 3][$currency] ?? null;
        if ($scale === null) {
            throw new \InvalidArgumentException("currency_unknown");
        }
        $parts = explode(".", self::normalize((string) $amount));
        $fraction = rtrim($parts[1] ?? "", "0");
        if (strlen($fraction) > $scale || strlen(ltrim($parts[0], "0")) > 12) {
            throw new \InvalidArgumentException("amount_precision_or_overflow");
        }
        $minor = (int) ($parts[0] . str_pad($fraction, $scale, "0"));
        if ($minor > self::MAX) {
            throw new \InvalidArgumentException("amount_overflow");
        }
        return $minor;
    }
    public static function format(int $minor, string $currency): string
    {
        $scale = ["IRR" => 2, "IRT" => 3][$currency] ?? null;
        if ($minor < 0 || $minor > self::MAX || $scale === null) {
            throw new \InvalidArgumentException("invalid_amount");
        }
        $s = str_pad((string) $minor, $scale + 1, "0", STR_PAD_LEFT);
        return rtrim(
            rtrim(substr($s, 0, -$scale) . "." . substr($s, -$scale), "0"),
            ".",
        );
    }
    public static function ratio(
        int $amount,
        int $numerator,
        int $denominator,
        bool $ceil = false,
    ): int {
        if (
            $denominator <= 0 ||
            $amount < 0 ||
            $numerator < 0 ||
            $numerator > 1000000 ||
            $amount > self::MAX
        ) {
            throw new \InvalidArgumentException("invalid_ratio");
        }
        $product = $amount * $numerator;
        $result =
            intdiv($product, $denominator) +
            ($ceil && $product % $denominator ? 1 : 0);
        if ($result > self::MAX) {
            throw new \InvalidArgumentException("amount_overflow");
        }
        return $result;
    }
}
