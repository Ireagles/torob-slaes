<?php
namespace Viratel\SmartPricing\Admin;
use Viratel\SmartPricing\Infrastructure\{Repository, MappingService};

/** Classic WooCommerce product-data panel. Stores configuration only, never product prices. */
final class ProductPanel
{
    public function register(): void
    {
        add_filter("woocommerce_product_data_tabs", function ($tabs) {
            $tabs["viratel_pricing"] = [
                "label" => "قیمت‌گذاری ویراتل",
                "target" => "vsp_product_data",
                "class" => ["show_if_simple"],
                "priority" => 75,
            ];
            return $tabs;
        });
        add_action("woocommerce_product_data_panels", [$this, "render"]);
        add_action("woocommerce_process_product_meta", [$this, "save"], 30, 1);
        add_action("admin_enqueue_scripts", function () {
            $screen = get_current_screen();
            if (
                $screen &&
                $screen->post_type === "product" &&
                $screen->base === "post"
            ) {
                AdminUI::enqueue();
            }
        });
    }
    public function render(): void
    {
        if (!current_user_can("vsp_manage")) {
            return;
        }
        global $post;
        $id = (int) ($post->ID ?? 0);
        $repo = new Repository();
        $old = $repo->get("mappings", $id);
        $m = $old["data"] ?? [];
        $r = $old
            ? $repo->get("rules", $old["rule_version"])["data"]
            : RuleForm::defaults();
        echo '<div id="vsp_product_data" class="panel woocommerce_options_panel hidden" dir="rtl"><div class="vsp-product-panel">';
        echo '<header class="vsp-product-heading"><span class="dashicons dashicons-chart-line" aria-hidden="true"></span><div><h2>کشف قیمت، با قواعد خودتان</h2><p>لینک منبع، مدل و کیفیت را مشخص کنید و محدوده امن محصول را بسازید.</p></div><span class="vsp-badge">ذخیره تنظیمات</span></header>';
        wp_nonce_field("vsp_product_" . $id, "vsp_product_nonce");
        foreach (
            [
                "expected_version" => $old["rule_version"] ?? "",
                "expected_mapping_hash" => $old
                    ? hash("sha256", $old["config"])
                    : "",
            ]
            as $key => $value
        ) {
            echo '<input type="hidden" name="vsp[' .
                esc_attr($key) .
                ']" value="' .
                esc_attr($value) .
                '">';
        }
        echo '<input type="hidden" name="vsp_present" value="1">';
        echo '<div class="vsp-alert"><span class="dashicons dashicons-shield" aria-hidden="true"></span><div><strong>ثبت این فرم، قیمت محصول را تغییر نمی‌دهد.</strong><p>پایش، پیشنهاد و اعمال قیمت از مسیر مستقل افزونه انجام می‌شود. داده فعلی ترب برای اعمال خودکار احراز نشده است.</p></div></div>';
        self::mappingFields($m, "vsp");
        RuleForm::rules($r, "vsp");
        echo '<div class="vsp-save-note">با دکمه «به‌روزرسانی» محصول ذخیره کنید. برای ویرایش variation مستقل، از فهرست محصولات ویراتل استفاده کنید.</div></div></div>';
    }
    public static function mappingFields(array $m, string $prefix = ""): void
    {
        RuleForm::card(
            "۱",
            "منبع و تطبیق دقیق",
            "مدل و کیفیت مشابه کافی نیست؛ مشخصات هر فروشنده باید با قطعه شما برابر باشد.",
        );
        RuleForm::field(
            "لینک دقیق محصول در ترب",
            "source_url",
            $m["source_url"] ?? "",
            "url",
            "فقط لینک عمومی محصول torob.com/p/…؛ جست‌وجو پذیرفته نیست.",
            $prefix,
        );
        RuleForm::select(
            "شرکت در بررسی قیمت",
            "enabled",
            [
                "0" => "غیرفعال؛ اطلاعات حفظ می‌شود",
                "1" => "فعال در گروه زمان‌بندی",
            ],
            !empty($m["enabled"]) ? "1" : "0",
            "",
            $prefix,
        );
        foreach (
            [
                "model" => "مدل دقیق دستگاه",
                "quality" => "کیفیت قطعه",
                "condition" => "نو / کارکرده",
                "color" => "رنگ",
                "frame" => "با فریم / بدون فریم",
                "technical_code" => "کد فنی",
            ]
            as $key => $label
        ) {
            RuleForm::field(
                $label,
                $key,
                $m["attributes"][$key] ?? "",
                "text",
                $key === "quality"
                    ? "مثلاً OEM؛ تأیید لینک، کیفیت فروشندگان را ثابت نمی‌کند."
                    : "",
                $prefix,
            );
        }
        RuleForm::select(
            "وضعیت تطبیق",
            "match_status",
            [
                "pending" => "نیازمند بررسی",
                "confirmed" => "مدل و کیفیت را تأیید کرده‌ام",
            ],
            $m["match_status"] ?? "pending",
            "",
            $prefix,
        );
        RuleForm::select(
            "حالت این محصول",
            "mode",
            [
                "monitor" => "پایش؛ بدون تغییر قیمت",
                "suggest" => "پیشنهاد برای بررسی",
                "auto" => "خودکار برای داده معتبر",
            ],
            $m["mode"] ?? "monitor",
            "حالت سراسری و کنترل‌های ایمنی نیز باید اجازه دهند.",
            $prefix,
        );
        $groups = (new Repository())->page("groups", 1, 100);
        $choices = [];
        foreach ($groups as $g) {
            $choices[$g["group_key"]] =
                ($g["group_key"] === "normal"
                    ? "عادی"
                    : ($g["group_key"] === "sensitive"
                        ? "حساس"
                        : $g["group_key"])) .
                " — هر " .
                round((int) ($g["data"]["interval"] ?? 21600) / 3600, 2) .
                " ساعت";
        }
        RuleForm::select(
            "گروه زمان‌بندی",
            "schedule_group",
            $choices,
            $m["schedule_group"] ?? "normal",
            "فاصله هر گروه در صفحه قیمت‌گذاری تنظیم می‌شود.",
            $prefix,
        );
        RuleForm::closeCard();
    }
    public static function mapping(array $post, array $old = []): array
    {
        $m = $old;
        $attrs = $old["attributes"] ?? [];
        foreach (
            [
                "model",
                "quality",
                "condition",
                "color",
                "frame",
                "technical_code",
            ]
            as $key
        ) {
            unset($attrs[$key]);
            if (($post[$key] ?? "") !== "") {
                $attrs[$key] = sanitize_text_field($post[$key]);
            }
        }
        $m["attributes"] = $attrs;
        foreach (
            ["source_url", "match_status", "mode", "schedule_group"]
            as $key
        ) {
            $m[$key] =
                $post[$key] ??
                ($key === "mode"
                    ? "monitor"
                    : ($key === "match_status"
                        ? "pending"
                        : ($key === "schedule_group"
                            ? "normal"
                            : "")));
        }
        $m["enabled"] = ($post["enabled"] ?? "0") === "1";
        return $m;
    }
    public function save(int $id): void
    {
        if (
            empty($_POST["vsp_present"]) ||
            !current_user_can("vsp_manage") ||
            !current_user_can("edit_post", $id) ||
            (defined("DOING_AUTOSAVE") && DOING_AUTOSAVE) ||
            wp_is_post_revision($id)
        ) {
            return;
        }
        if (
            !wp_verify_nonce(
                sanitize_text_field(
                    wp_unslash($_POST["vsp_product_nonce"] ?? ""),
                ),
                "vsp_product_" . $id,
            )
        ) {
            \WC_Admin_Meta_Boxes::add_error(
                "ویراتل: اعتبار فرم منقضی شده است؛ صفحه را دوباره باز کنید.",
            );
            return;
        }
        try {
            if (!is_array($_POST["vsp"] ?? null)) {
                throw new \InvalidArgumentException("form_invalid");
            }
            $post = wp_unslash($_POST["vsp"]);
            $repo = new Repository();
            $old = $repo->get("mappings", $id);
            if (!$old && ($post["enabled"] ?? "0") !== "1") {
                return;
            }
            $rule = $old
                ? $repo->get("rules", $old["rule_version"])["data"]
                : RuleForm::defaults();
            (new MappingService())->save(
                $id,
                self::mapping($post, $old["data"] ?? []),
                RuleForm::parse($post, $rule),
                $post["expected_version"] ?? "",
                $post["expected_mapping_hash"] ?? "",
            );
        } catch (\Throwable $e) {
            $code = sanitize_key($e->getMessage());
            $label =
                [
                    "invalid_bounds" => "کف و سقف باید مثبت و مرتب باشند.",
                    "mapping_conflict" =>
                        "تنظیمات در صفحه دیگری تغییر کرده‌اند؛ این صفحه را دوباره باز کنید.",
                    "quality_and_model_required" =>
                        "برای تأیید تطبیق، مدل و کیفیت لازم است.",
                    "source_product_required" =>
                        "لینک دقیق محصول ترب لازم است.",
                    "source_url_forbidden" => "لینک منبع مجاز نیست.",
                ][$code] ??
                "ورودی معتبر نیست. کد: " . $code;
            \WC_Admin_Meta_Boxes::add_error(
                "ویراتل: تنظیمات ذخیره نشد. " . $label,
            );
        }
    }
}
