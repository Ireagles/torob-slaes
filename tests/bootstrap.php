<?php
spl_autoload_register(function ($class) {
    $p = "Viratel\\SmartPricing\\";
    if (strpos($class, $p) === 0) {
        $f =
            dirname(__DIR__) .
            "/viratel-smart-pricing/src/" .
            str_replace("\\", "/", substr($class, strlen($p))) .
            ".php";
        if (is_file($f)) {
            require $f;
        }
    }
});
if (!function_exists("sanitize_text_field")) {
    function sanitize_text_field($v)
    {
        return trim(strip_tags($v));
    }
}
$GLOBALS["vsp_assertions"] = 0;
function check($condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException("FAIL: " . $name);
    }
    $GLOBALS["vsp_assertions"]++;
    echo "PASS " . $name . "\n";
}
function raises(callable $fn, string $reason, string $name): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        check(
            strpos($e->getMessage(), $reason) !== false,
            $name . " (" . $e->getMessage() . ")",
        );
        return;
    }
    throw new RuntimeException("FAIL expected exception: " . $name);
}
