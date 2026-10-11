<?php
namespace Viratel\SmartPricing\Admin;
use Viratel\SmartPricing\Domain\Money;

/** Common presentation/validation for the standalone editor and native Woo product tab. */
final class RuleForm
{
    public const TYPES = [
        "fixed_below" => "مبلغ کمتر از رقیب",
        "fixed_above" => "مبلغ بیشتر از رقیب",
        "percent_below" => "درصد کمتر از رقیب",
        "percent_above" => "درصد بیشتر از رقیب",
        "match" => "برابر با قیمت مبنا",
    ];
    public const BASES = [
        "min" => "کمترین قیمت معتبر",
        "mean" => "میانگین قیمت‌های معتبر",
        "max" => "بیشترین قیمت معتبر",
    ];
    public static function defaults(): array
    {
        return array_merge(
            [
                "currency" => "IRT",
                "floor" => "",
                "ceiling" => "",
                "type" => "fixed_below",
                "basis" => "min",
                "delta" => "1000",
                "delta_bps" => 100,
                "round_step" => "1000",
                "max_change_bps" => 1000,
                "max_daily_change" => "50000",
                "min_competitors" => 2,
                "cooldown" => 3600,
            ],
            (array) get_option("vsp_pricing_defaults", []),
        );
    }
    public static function parse(
        array $post,
        array $old = [],
        bool $template = false,
    ): array {
        $currency = $post["rule_currency"] ?? ($old["currency"] ?? "IRT");
        if (!in_array($currency, ["IRT", "IRR"], true)) {
            throw new \InvalidArgumentException("currency_unknown");
        }
        $type = $post["rule_type"] ?? ($old["type"] ?? "fixed_below");
        if (
            !isset(self::TYPES[$type]) &&
            !($type === "cost_plus" && isset($old["cost"]))
        ) {
            throw new \InvalidArgumentException("invalid_rule_type");
        }
        $basis = $post["basis"] ?? ($old["basis"] ?? "min");
        if (!isset(self::BASES[$basis])) {
            throw new \InvalidArgumentException("invalid_basis");
        }
        $r = array_merge($old, [
            "currency" => $currency,
            "type" => $type,
            "basis" => $basis,
        ]);
        foreach (
            ["floor", "ceiling", "delta", "round_step", "max_daily_change"]
            as $key
        ) {
            $raw =
                $post[$key] ??
                ($old[$key] ??
                    ($key === "delta"
                        ? "1000"
                        : ($key === "round_step"
                            ? "1"
                            : ($key === "max_daily_change"
                                ? "50000"
                                : ""))));
            if (
                $template &&
                in_array($key, ["floor", "ceiling"], true) &&
                $raw === ""
            ) {
                $r[$key] = "";
                continue;
            }
            $r[$key] = Money::format(Money::parse($raw, $currency), $currency);
        }
        if (
            ($r["floor"] === "") !== ($r["ceiling"] === "") ||
            ($r["floor"] !== "" &&
                (Money::parse($r["floor"], $currency) <= 0 ||
                    Money::parse($r["floor"], $currency) >
                        Money::parse($r["ceiling"], $currency)))
        ) {
            throw new \InvalidArgumentException("invalid_bounds");
        }
        if (Money::parse($r["round_step"], $currency) <= 0) {
            throw new \InvalidArgumentException("invalid_round_step");
        }
        foreach (
            [
                "delta_percent" => "delta_bps",
                "max_change_percent" => "max_change_bps",
            ]
            as $field => $key
        ) {
            $r[$key] = isset($post[$field])
                ? Money::parse($post[$field], "IRR")
                : (int) ($old[$key] ?? ($key === "delta_bps" ? 100 : 1000));
            if ($r[$key] > 10000) {
                throw new \InvalidArgumentException("invalid_" . $key);
            }
        }
        foreach (
            ["min_competitors" => [1, 100], "cooldown" => [0, 604800]]
            as $key => $bounds
        ) {
            $value =
                $post[$key] ??
                ($old[$key] ?? ($key === "min_competitors" ? 2 : 3600));
            if (
                !preg_match('/^[0-9]+$/D', (string) $value) ||
                (int) $value < $bounds[0] ||
                (int) $value > $bounds[1]
            ) {
                throw new \InvalidArgumentException("invalid_" . $key);
            }
            $r[$key] = (int) $value;
        }
        return $r;
    }
    public static function card(
        string $step,
        string $title,
        string $description = "",
    ): void {
        echo '<section class="vsp-card"><header class="vsp-card-heading"><span class="vsp-step">' .
            esc_html($step) .
            "</span><div><h2>" .
            esc_html($title) .
            "</h2>";
        if ($description !== "") {
            echo "<p>" . esc_html($description) . "</p>";
        }
        echo '</div></header><div class="vsp-field-grid">';
    }
    public static function closeCard(): void
    {
        echo "</div></section>";
    }
    public static function field(
        string $label,
        string $name,
        $value = "",
        string $type = "text",
        string $help = "",
        string $prefix = "",
    ): void {
        $id = "vsp-field-" . sanitize_key($prefix . $name);
        $field = $prefix ? $prefix . "[" . $name . "]" : $name;
        $helpId = $id . "-help";
        echo '<div class="vsp-field"><label for="' .
            esc_attr($id) .
            '">' .
            esc_html($label) .
            '</label><input id="' .
            esc_attr($id) .
            '" type="' .
            esc_attr($type) .
            '" name="' .
            esc_attr($field) .
            '" value="' .
            esc_attr((string) $value) .
            '"' .
            (in_array($type, ["number", "url"], true) ? ' dir="ltr"' : "") .
            ($type === "number" ? ' min="0"' : "") .
            ($type === "text" ? ' autocomplete="off"' : "") .
            ($help !== ""
                ? ' aria-describedby="' . esc_attr($helpId) . '"'
                : "") .
            ">";
        if ($help !== "") {
            echo '<small id="' .
                esc_attr($helpId) .
                '">' .
                esc_html($help) .
                "</small>";
        }
        echo "</div>";
    }
    public static function select(
        string $label,
        string $name,
        array $options,
        string $selected,
        string $help = "",
        string $prefix = "",
    ): void {
        $id = "vsp-field-" . sanitize_key($prefix . $name);
        $field = $prefix ? $prefix . "[" . $name . "]" : $name;
        echo '<div class="vsp-field"><label for="' .
            esc_attr($id) .
            '">' .
            esc_html($label) .
            '</label><select id="' .
            esc_attr($id) .
            '" name="' .
            esc_attr($field) .
            '">';
        foreach ($options as $key => $text) {
            echo '<option value="' .
                esc_attr((string) $key) .
                '"' .
                selected($key, $selected, false) .
                ">" .
                esc_html($text) .
                "</option>";
        }
        echo "</select>";
        if ($help !== "") {
            echo "<small>" . esc_html($help) . "</small>";
        }
        echo "</div>";
    }
    public static function rules(
        array $r,
        string $prefix = "",
        bool $template = false,
    ): void {
        $currency = $r["currency"] ?? "IRT";
        $unit = $currency === "IRT" ? "تومان" : "ریال";
        echo '<input type="hidden" name="' .
            esc_attr($prefix ? $prefix . "[rule_currency]" : "rule_currency") .
            '" value="' .
            esc_attr($currency) .
            '">';
        self::card(
            "۲",
            "مبنای قیمت‌گذاری",
            "قیمت فقط از فروشندگان دارای مدل و کیفیت یکسان محاسبه می‌شود.",
        );
        self::select(
            "مبنای تغییر قیمت",
            "basis",
            self::BASES,
            $r["basis"] ?? "min",
            "",
            $prefix,
        );
        $types = self::TYPES;
        if (($r["type"] ?? "") === "cost_plus") {
            $types["cost_plus"] =
                "کف هزینه‌ای اختصاصی (تنظیمات فعلی حفظ می‌شود)";
        }
        self::select(
            "روش قیمت‌گذاری",
            "rule_type",
            $types,
            $r["type"] ?? "fixed_below",
            "",
            $prefix,
        );
        self::field(
            "اختلاف مبلغی (" . $unit . ")",
            "delta",
            $r["delta"] ?? "1000",
            "text",
            "برای روش مبلغی؛ کمتر یا بیشتر از مبنا.",
            $prefix,
        );
        self::field(
            "اختلاف درصدی (%)",
            "delta_percent",
            Money::format((int) ($r["delta_bps"] ?? 100), "IRR"),
            "text",
            "مثلاً ۱٫۵ یعنی یک‌ونیم درصد.",
            $prefix,
        );
        self::closeCard();
        self::card(
            "۳",
            "محدوده امن قیمت",
            "کف و سقف محصول اولویت دارند؛ هیچ پیشنهاد مجازی از این محدوده عبور نمی‌کند.",
        );
        self::field(
            "کف قیمت (" . $unit . ")",
            "floor",
            $r["floor"] ?? "",
            "text",
            $template
                ? "اختیاری در قالب؛ برای ثبت هر محصول کف و سقف لازم است."
                : "مبلغ مثبت و صریح؛ شامل هزینه‌های موردنظر شما.",
            $prefix,
        );
        self::field(
            "سقف قیمت (" . $unit . ")",
            "ceiling",
            $r["ceiling"] ?? "",
            "text",
            "باید برابر یا بیشتر از کف باشد.",
            $prefix,
        );
        self::field(
            "گام گردکردن (" . $unit . ")",
            "round_step",
            $r["round_step"] ?? "1000",
            "text",
            "۱۰۰۰ → قیمت مضرب هزار؛ حدود همچنان رعایت می‌شوند.",
            $prefix,
        );
        self::field(
            "حداقل فروشندگان معتبر",
            "min_competitors",
            $r["min_competitors"] ?? 2,
            "number",
            "پیشنهاد پیشفرض: دو فروشنده مستقل.",
            $prefix,
        );
        self::closeCard();
        self::card(
            "۴",
            "کنترل نوسان و تغییر دستی",
            "ویرایش دستی قیمت قفل محافظتی ایجاد می‌کند؛ موجودی محصول با داده رقبا تغییر نمی‌کند.",
        );
        self::field(
            "حداکثر تغییر در هر بار (%)",
            "max_change_percent",
            Money::format((int) ($r["max_change_bps"] ?? 1000), "IRR"),
            "text",
            "جهش بیشتر برای بررسی نگه داشته می‌شود.",
            $prefix,
        );
        self::field(
            "سقف تغییر تجمعی روزانه (" . $unit . ")",
            "max_daily_change",
            $r["max_daily_change"] ?? "50000",
            "text",
            "جمع قدرمطلق تغییرها، حتی در چند اجرای کوچک.",
            $prefix,
        );
        self::field(
            "فاصله بین تغییر قیمت (ثانیه)",
            "cooldown",
            $r["cooldown"] ?? 3600,
            "number",
            "۳۶۰۰ یعنی حداقل یک ساعت.",
            $prefix,
        );
        self::closeCard();
    }
}
