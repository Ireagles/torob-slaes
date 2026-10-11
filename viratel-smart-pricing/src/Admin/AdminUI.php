<?php
namespace Viratel\SmartPricing\Admin;
use Viratel\SmartPricing\Infrastructure\{
    Repository,
    Settings,
    MappingService,
    ProposalService,
    PriceApplier,
};
use Viratel\SmartPricing\Providers\ProviderRegistry;
use Viratel\SmartPricing\ImportExport\CsvService;
use Viratel\SmartPricing\Queue\QueueService;

final class AdminUI
{
    const TABS = [
        "dashboard" => "داشبورد",
        "products" => "محصولات",
        "sources" => "منابع و تطبیق",
        "rules" => "قواعد و گروه‌ها",
        "proposals" => "پیشنهادها",
        "queue" => "صف",
        "history" => "تاریخچه",
        "csv" => "ورود و خروج CSV",
        "settings" => "تنظیمات",
    ];
    public function register(): void
    {
        add_action("admin_menu", function () {
            add_submenu_page(
                "woocommerce",
                "قیمت‌گذاری ویراتل",
                "قیمت‌گذاری ویراتل",
                "vsp_view",
                "viratel-smart-pricing",
                [$this, "render"],
            );
        });
        add_action("admin_enqueue_scripts", function () {
            if (($_GET["page"] ?? "") === "viratel-smart-pricing") {
                wp_enqueue_style(
                    "vsp-admin",
                    plugins_url("assets/admin.css", VSP_FILE),
                    [],
                    VSP_VERSION,
                );
            }
        });
        add_action("admin_post_vsp_action", [$this, "handle"]);
        add_action("rest_api_init", [$this, "rest"]);
        add_action("vsp_import_validate", [$this, "validateImport"], 10, 1);
        add_action("vsp_import_apply", [$this, "applyImport"], 10, 1);
        add_action("vsp_import_rollback", [$this, "rollbackImport"], 10, 1);
    }
    public function validateImport(string $id): void
    {
        $job = (new CsvService())->validateChunk($id);
        if ($job["status"] === "validating") {
            as_enqueue_async_action(
                "vsp_import_validate",
                [$id],
                QueueService::GROUP,
                false,
            );
        }
    }
    public function applyImport(string $id): void
    {
        $job = (new CsvService())->applyChunk($id);
        if ($job["status"] === "applying") {
            as_enqueue_async_action(
                "vsp_import_apply",
                [$id],
                QueueService::GROUP,
                false,
            );
        }
    }
    public function rollbackImport(string $id): void
    {
        $job = (new CsvService())->rollbackChunk($id);
        if ($job["status"] === "rolling_back") {
            as_enqueue_async_action(
                "vsp_import_rollback",
                [$id],
                QueueService::GROUP,
                false,
            );
        }
    }
    public function rest(): void
    {
        register_rest_route("vsp/v1", "/status", [
            "methods" => "GET",
            "permission_callback" => function () {
                return current_user_can("vsp_view");
            },
            "callback" => function () {
                return [
                    "settings" => array_intersect_key(
                        Settings::get(),
                        array_flip([
                            "mode",
                            "paused",
                            "source_configured",
                            "currency",
                        ]),
                    ),
                    "providers" => (new ProviderRegistry())->statuses(),
                    "products" => (new Repository())->count("mappings"),
                ];
            },
        ]);
        register_rest_route("vsp/v1", "/pause", [
            "methods" => "POST",
            "permission_callback" => function ($request) {
                return current_user_can("vsp_manage") &&
                    wp_verify_nonce(
                        $request->get_header("X-WP-Nonce"),
                        "wp_rest",
                    );
            },
            "callback" => function () {
                $s = Settings::get();
                $s["paused"] = true;
                update_option("vsp_settings", $s, false);
                return ["paused" => true];
            },
        ]);
    }
    private function begin(string $op, array $hidden = []): void
    {
        echo '<form method="post" action="' .
            esc_url(admin_url("admin-post.php")) .
            '" enctype="multipart/form-data" class="vsp-form">';
        wp_nonce_field("vsp_admin");
        echo '<input type="hidden" name="action" value="vsp_action"><input type="hidden" name="operation" value="' .
            esc_attr($op) .
            '">';
        foreach ($hidden as $k => $v) {
            echo '<input type="hidden" name="' .
                esc_attr($k) .
                '" value="' .
                esc_attr($v) .
                '">';
        }
    }
    private function end(string $label): void
    {
        echo '<button class="button button-primary">' .
            esc_html($label) .
            "</button></form>";
    }
    private function input(
        string $label,
        string $name,
        string $value = "",
        string $type = "text",
    ): void {
        echo "<label>" .
            esc_html($label) .
            '<input type="' .
            esc_attr($type) .
            '" name="' .
            esc_attr($name) .
            '" value="' .
            esc_attr($value) .
            '"' .
            ($type === "number" ? ' min="0"' : "") .
            "></label>";
    }
    private function select(
        string $label,
        string $name,
        array $options,
        string $selected,
    ): void {
        echo "<label>" .
            esc_html($label) .
            '<select name="' .
            esc_attr($name) .
            '">';
        foreach ($options as $v => $text) {
            echo '<option value="' .
                esc_attr($v) .
                '"' .
                selected($v, $selected, false) .
                ">" .
                esc_html($text) .
                "</option>";
        }
        echo "</select></label>";
    }
    public function render(): void
    {
        if (!current_user_can("vsp_view")) {
            wp_die("دسترسی مجاز نیست.");
        }
        $tab = sanitize_key($_GET["tab"] ?? "dashboard");
        if (!isset(self::TABS[$tab])) {
            $tab = "dashboard";
        }
        $repo = new Repository();
        $s = Settings::get();
        echo '<div class="wrap vsp-wrap" dir="rtl"><h1>قیمت‌گذاری هوشمند ویراتل <small>۰٫۱ — نسخه آزمایشی</small></h1><p>رتبه قیمتی مشاهده‌شده با موقعیت نمایش در ترب متفاوت است.</p>';
        if (isset($_GET["result"])) {
            echo '<div class="notice notice-info"><p>' .
                esc_html(
                    $_GET["result"] === "ok"
                        ? "عملیات ثبت شد."
                        : "عملیات متوقف شد؛ جزئیات را در گزارش بررسی کنید. کد: " .
                            sanitize_text_field($_GET["result"]),
                ) .
                "</p></div>";
        }
        echo '<nav class="nav-tab-wrapper">';
        foreach (self::TABS as $key => $label) {
            echo '<a class="nav-tab ' .
                ($tab === $key ? "nav-tab-active" : "") .
                '" href="' .
                esc_url(
                    add_query_arg(
                        ["page" => "viratel-smart-pricing", "tab" => $key],
                        admin_url("admin.php"),
                    ),
                ) .
                '">' .
                esc_html($label) .
                "</a>";
        }
        echo "</nav>";
        echo '<div class="vsp-status"><strong>حالت: ' .
            esc_html(
                [
                    "monitor" => "پایش",
                    "suggest" => "پیشنهاد",
                    "auto" => "خودکار",
                ][$s["mode"] ?? "monitor"],
            ) .
            "</strong> — " .
            (!empty($s["paused"]) ? "توقف فعال است" : "توقف غیرفعال است") .
            "</div>";
        if (current_user_can("vsp_manage")) {
            $this->begin(!empty($s["paused"]) ? "resume" : "pause");
            $this->end(
                !empty($s["paused"])
                    ? "رفع توقف (بدون رفع موانع داده)"
                    : "توقف اضطراری اعمال قیمت",
            );
        }
        echo '<div class="notice notice-warning inline"><p>مسیر پایش JSON-LD عمومی ترب پیاده شده؛ موفقیت دریافت به اتصال همین هاست وابسته است. زمان داده، کیفیت و هویت فروشندگان احراز نشده است. اعمال خودکار با این داده مسدود می‌ماند. اتصال‌های دیگر پیکربندی نشده‌اند.</p></div>';
        if ($tab === "dashboard") {
            echo '<div class="vsp-cards">';
            foreach (
                [
                    "mappings" => "محصولات ثبت‌شده",
                    "proposals" => "پیشنهادها",
                    "changes" => "رویدادهای تغییر",
                    "usage" => "تلاش‌های دریافت منبع",
                ]
                as $t => $label
            ) {
                echo "<article><h2>" .
                    esc_html($label) .
                    "</h2><b>" .
                    esc_html($repo->count($t)) .
                    "</b></article>";
            }
            echo "</div><p>روند: محصول ← لینک دقیق ← تأیید مدل و کیفیت ← کف/سقف ← پایش ← پیشنهاد ← تأیید منتخب.</p>";
            if (defined("DISABLE_WP_CRON") && DISABLE_WP_CRON) {
                echo "<p>WP-Cron غیرفعال است؛ اجرای cron سرور/CLI ضروری است. آخرین heartbeat صف: " .
                    esc_html(
                        get_option("vsp_queue_heartbeat", "هنوز اجرا نشده"),
                    ) .
                    "</p>";
            }
            echo "<p>کمترین مشاهده‌شده فقط در داده موجود است؛ تضمین کمترین قیمت کل بازار یا رتبه اول وجود ندارد.</p>";
        }
        if (in_array($tab, ["products", "sources"], true)) {
            $this->mappingForm();
            $this->rows("mappings", $tab);
            if ($tab === "sources") {
                $this->begin("poll");
                $this->input(
                    "شناسه محصول برای پایش خواندنی",
                    "product_id",
                    "",
                    "number",
                );
                $this->end("ثبت کار پایش");
                $this->rows("snapshots", $tab);
            }
        }
        if ($tab === "rules") {
            $this->rows("groups", $tab);
            if (current_user_can("vsp_manage")) {
                $this->begin("group");
                $this->input("نام لاتین گروه", "group_key", "normal");
                $this->input(
                    "فاصله بررسی (ثانیه؛ حداقل ۹۰۰)",
                    "interval",
                    "21600",
                    "number",
                );
                $this->input(
                    "حداکثر عمر داده (ثانیه)",
                    "max_source_age",
                    "21600",
                    "number",
                );
                $this->end("ذخیره گروه");
            }
            echo "<p>قاعده محصول مستقل و نسخه‌دار است. resolver تقدم محصول، گروه، دسته، برند و پیشفرض را در هسته دارد؛ ارث‌بری دسته/برند در این نسخه به پنل متصل نیست.</p>";
        }
        if ($tab === "proposals") {
            $this->begin("preview");
            $this->input("شناسه محصول", "product_id", "", "number");
            $this->input("شناسه snapshot پایش", "snapshot_id");
            $this->end("محاسبه پیش‌نمایش بدون تغییر قیمت");
            $this->rows("proposals", $tab);
        }
        if ($tab === "history") {
            $this->rows("changes", $tab);
        }
        if ($tab === "queue") {
            echo "<p>گروه صف: viratel-smart-pricing؛ هر کار کوتاه و مستقل است. retry محدود و بودجه درخواست پیش از شبکه کنترل می‌شوند.</p>";
            if (function_exists("as_get_scheduled_actions")) {
                $actions = as_get_scheduled_actions([
                    "group" => QueueService::GROUP,
                    "per_page" => 30,
                    "orderby" => "date",
                    "order" => "DESC",
                ]);
                echo '<table class="widefat"><tr><th>کار</th><th>زمان</th><th>شناسه</th></tr>';
                foreach ($actions as $id => $a) {
                    echo "<tr><td>" .
                        esc_html($a->get_hook()) .
                        "</td><td>" .
                        esc_html($a->get_schedule()->get_date()->format("c")) .
                        "</td><td>" .
                        esc_html($id) .
                        "</td></tr>";
                }
                echo "</table>";
            }
            $this->rows("usage", $tab);
        }
        if ($tab === "csv") {
            echo "<p>CSV با مبلغ صریح تومان؛ dry-run اجباری و بررسی ۱۰۰ ردیف در هر کار؛ اعمال ۵۰ ردیف در هر کار. این عملیات فقط تنظیمات را تغییر می‌دهد.</p>";
            if (current_user_can("vsp_manage")) {
                $this->begin("csv_stage");
                $this->input(
                    "فایل UTF-8 CSV (حداکثر ۲MB)",
                    "csv_file",
                    "",
                    "file",
                );
                $this->end("بارگذاری و پیش‌نمایش اجباری");
            }
            $this->begin("csv_export");
            $this->end("خروجی CSV سازگار با Excel");
            $this->rows("imports", $tab);
        }
        if ($tab === "settings") {
            $this->settingsForm($s);
        }
        echo "</div>";
    }
    private function mappingForm(): void
    {
        if (!current_user_can("vsp_manage")) {
            return;
        }
        $repo = new Repository();
        $old = $repo->get("mappings", absint($_GET["edit"] ?? 0));
        $m = $old["data"] ?? [];
        $r = $old ? $repo->get("rules", $old["rule_version"])["data"] : [];
        $currency = $r["currency"] ?? "IRT";
        $unit = $currency === "IRR" ? "ریال" : "تومان";
        $this->begin("mapping", [
            "expected_version" => $old["rule_version"] ?? "",
            "expected_mapping_hash" => $old
                ? hash("sha256", $old["config"])
                : "",
            "rule_currency" => $currency,
        ]);
        echo "<h2>" .
            ($old ? "ویرایش تنظیمات محصول منتخب" : "ثبت تطبیق و قاعده محصول") .
            "</h2>";
        $this->input(
            "شناسه محصول ساده یا variation",
            "product_id",
            $old ? (string) $old["product_id"] : "",
            "number",
        );
        $this->input(
            "لینک دقیق عمومی ترب",
            "source_url",
            $m["source_url"] ?? "",
            "url",
        );
        foreach (
            [
                "model" => "مدل دقیق",
                "quality" => "کیفیت دقیق (مثلاً OEM)",
                "condition" => "نو/کارکرده",
                "color" => "رنگ",
                "frame" => "با فریم/بدون فریم",
                "technical_code" => "کد فنی",
            ]
            as $key => $label
        ) {
            $this->input($label, $key, $m["attributes"][$key] ?? "");
        }
        $this->select(
            "تطبیق",
            "match_status",
            [
                "pending" => "نیازمند بررسی",
                "confirmed" => "مدل و کیفیت را تأیید می‌کنم",
            ],
            $m["match_status"] ?? "pending",
        );
        $this->select(
            "حالت محصول",
            "mode",
            [
                "monitor" => "پایش",
                "suggest" => "پیشنهاد",
                "auto" => "خودکار با کنترل ایمنی",
            ],
            $m["mode"] ?? "monitor",
        );
        $this->select(
            "فعال‌بودن محصول در برنامه",
            "enabled",
            ["1" => "فعال", "0" => "غیرفعال"],
            !isset($m["enabled"]) || $m["enabled"] ? "1" : "0",
        );
        $this->input("کف (" . $unit . ")", "floor", $r["floor"] ?? "");
        $this->input("سقف (" . $unit . ")", "ceiling", $r["ceiling"] ?? "");
        $this->input(
            "اختلاف مبلغی (" . $unit . ")",
            "delta",
            $r["delta"] ?? "1000",
        );
        $this->input(
            "اختلاف درصدی (%)",
            "delta_percent",
            \Viratel\SmartPricing\Domain\Money::format(
                (int) ($r["delta_bps"] ?? 100),
                "IRR",
            ),
        );
        $this->input(
            "گام گردکردن (" . $unit . ")",
            "round_step",
            $r["round_step"] ?? "1",
        );
        $this->input(
            "گروه برنامه",
            "schedule_group",
            $m["schedule_group"] ?? "normal",
        );
        $this->input(
            "سقف تغییر هر بار (%)",
            "max_change_percent",
            \Viratel\SmartPricing\Domain\Money::format(
                (int) ($r["max_change_bps"] ?? 1000),
                "IRR",
            ),
        );
        $this->input(
            "سقف تغییر تجمعی روزانه (" . $unit . ")",
            "max_daily_change",
            $r["max_daily_change"] ?? "50000",
        );
        $this->select(
            "قاعده",
            "rule_type",
            [
                "fixed_below" => "مبلغ کمتر از رقیب",
                "fixed_above" => "مبلغ بیشتر از رقیب",
                "percent_below" => "درصد کمتر از رقیب",
                "percent_above" => "درصد بیشتر از رقیب",
                "match" => "تطبیق قیمت",
            ],
            $r["type"] ?? "fixed_below",
        );
        $this->select(
            "مرجع قیمت",
            "basis",
            [
                "min" => "کمترین مشاهده‌شده معتبر",
                "mean" => "میانگین offers معتبر",
                "max" => "بیشترین offers معتبر",
            ],
            $r["basis"] ?? "min",
        );
        $this->end("ذخیره تنظیمات؛ بدون تغییر قیمت");
    }
    private function settingsForm(array $s): void
    {
        if (!current_user_can("vsp_manage")) {
            return;
        }
        $this->begin("settings");
        $this->select(
            "حالت سراسری",
            "mode",
            [
                "monitor" => "پایش",
                "suggest" => "پیشنهاد",
                "auto" => "خودکار برای منتخب‌ها",
            ],
            $s["mode"] ?? "monitor",
        );
        $this->select(
            "واحد WooCommerce (باید با تنظیم فروشگاه یکسان باشد)",
            "currency",
            ["" => "پیکربندی نشده", "IRT" => "تومان IRT", "IRR" => "ریال IRR"],
            $s["currency"] ?? "",
        );
        $this->input(
            "دامنه‌های دقیق خودی، جدا با کاما",
            "own_domains",
            implode(",", $s["own_domains"] ?? []),
        );
        $this->input(
            "شناسه فروشگاه‌های خودی، جدا با کاما",
            "own_store_ids",
            implode(",", $s["own_store_ids"] ?? []),
        );
        $this->input(
            "بودجه روزانه درخواست",
            "daily_budget",
            (string) ($s["daily_budget"] ?? 100),
            "number",
        );
        $this->input(
            "بودجه ماهانه درخواست",
            "monthly_budget",
            (string) ($s["monthly_budget"] ?? 3000),
            "number",
        );
        $this->select(
            "فعال‌سازی پایش برنامه‌ریزی‌شده ترب",
            "source_configured",
            ["0" => "خاموش", "1" => "روشن (در حدود بودجه)"],
            !empty($s["source_configured"]) ? "1" : "0",
        );
        $this->select(
            "هنگام توقف، پایش هم متوقف شود",
            "pause_monitor",
            ["0" => "پایش ادامه یابد", "1" => "پایش هم متوقف شود"],
            !empty($s["pause_monitor"]) ? "1" : "0",
        );
        $this->end("ذخیره تنظیمات");
        echo "<p>حذف داده هنگام uninstall پیشفرض خاموش است. قیمت‌گذاری ارزی، منابع دیگر، اعلان و Worker: پیکربندی نشده / توسعه بعدی.</p>";
    }
    private function rows(string $table, string $tab): void
    {
        $repo = new Repository();
        $page = max(1, (int) ($_GET["paged"] ?? 1));
        $search = sanitize_text_field($_GET["s"] ?? "");
        $rows = $repo->page($table, $page, 20, $search);
        if ($table === "mappings") {
            echo '<form method="get"><input type="hidden" name="page" value="viratel-smart-pricing"><input type="hidden" name="tab" value="' .
                esc_attr($tab) .
                '"><input name="s" placeholder="جستجوی SKU، مدل، عنوان یا کیفیت" value="' .
                esc_attr($search) .
                '"><button class="button">جستجو</button></form>';
        }
        echo '<div class="vsp-table"><table class="widefat striped"><thead><tr><th>شناسه</th><th>وضعیت و اطلاعات</th><th>عملیات</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $id = $r["id"] ?? ($r["product_id"] ?? $r["group_key"]);
            echo "<tr><td><code>" .
                esc_html($id) .
                "</code></td><td><b>" .
                esc_html($this->label($r["status"] ?? "")) .
                "</b>";
            if (isset($r["created_at"])) {
                echo "<p>" .
                    esc_html(wp_date("Y/m/d H:i:s", (int) $r["created_at"])) .
                    "</p>";
            }
            $d = $r["data"] ?? $r;
            if (!empty($d["is_test"])) {
                echo "<p><strong>داده آزمایشی — مجوز قیمت واقعی ندارد</strong></p>";
            }
            if ($table === "mappings") {
                echo "<p>" .
                    esc_html(
                        ($d["title"] ?? "") .
                            " | SKU: " .
                            ($d["sku"] ?? "") .
                            " | مدل: " .
                            ($d["attributes"]["model"] ?? "") .
                            " | کیفیت: " .
                            ($d["attributes"]["quality"] ?? ""),
                    ) .
                    "</p>";
            }
            if ($table === "proposals") {
                $currency = $d["currency"];
                $unit = $currency === "IRT" ? "تومان" : "ریال";
                $amount = $d["result"]["amount"];
                echo "<p>قیمت فعلی: " .
                    esc_html($d["before"]["effective"] . " " . $unit) .
                    " ← پیشنهاد: " .
                    esc_html(
                        $amount === null
                            ? "بدون پیشنهاد"
                            : \Viratel\SmartPricing\Domain\Money::format(
                                    $amount,
                                    $currency,
                                ) .
                                " " .
                                $unit,
                    ) .
                    "</p>";
                echo "<p>دلایل: " .
                    esc_html(
                        implode(
                            "، ",
                            array_map(
                                [$this, "label"],
                                $d["result"]["reasons"],
                            ),
                        ),
                    ) .
                    "</p>";
                if (!empty($d["result"]["limits"])) {
                    echo "<p>کف مؤثر: " .
                        esc_html(
                            \Viratel\SmartPricing\Domain\Money::format(
                                $d["result"]["limits"]["floor"],
                                $currency,
                            ) .
                                " " .
                                $unit,
                        ) .
                        " | سقف: " .
                        esc_html(
                            \Viratel\SmartPricing\Domain\Money::format(
                                $d["result"]["limits"]["ceiling"],
                                $currency,
                            ) .
                                " " .
                                $unit,
                        ) .
                        "</p>";
                }
            }
            if ($table === "snapshots" && !empty($d["offers"])) {
                echo "<table><tr><th>فروشنده</th><th>قیمت منبع</th><th>موجودی / زمان داده</th></tr>";
                foreach ($d["offers"] as $offer) {
                    echo "<tr><td>" .
                        esc_html(
                            $offer["seller_name"] ??
                                ($offer["store_id"] ?? "نامعلوم"),
                        ) .
                        "</td><td>" .
                        esc_html(
                            ($offer["price"] ?? "") .
                                " " .
                                (($offer["currency"] ?? "") === "IRT"
                                    ? "تومان"
                                    : "ریال"),
                        ) .
                        "</td><td>" .
                        esc_html(
                            ($offer["in_stock"] ?? null) === true
                                ? "موجود"
                                : "نامعلوم / ناموجود",
                        ) .
                        " / " .
                        esc_html($offer["observed_at"] ?? "نامعلوم") .
                        "</td></tr>";
                }
                echo "</table>";
            }
            if ($table === "changes") {
                echo "<p>قیمت مؤثر قبل: " .
                    esc_html($d["before"]["effective"]) .
                    " ← بعد: " .
                    esc_html($d["after"]["effective"]) .
                    " " .
                    esc_html(
                        ($d["currency"] ?? "IRT") === "IRT" ? "تومان" : "ریال",
                    ) .
                    "</p>";
            }
            if ($table === "imports") {
                $d = [
                    "status" => $r["status"],
                    "rows" => count($d["rows"]),
                    "cursor" => $d["cursor"],
                    "errors" => $d["errors"],
                    "sample" => array_slice($d["rows"], 0, 3),
                ];
            }
            echo '<details><summary>جزئیات و دلایل</summary><pre dir="ltr">' .
                esc_html(
                    wp_json_encode(
                        $d,
                        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
                    ),
                ) .
                "</pre></details>";
            if ($table === "snapshots") {
                echo "<p>آخرین درخواست: " .
                    esc_html($d["fetched_at"]) .
                    " | زمان مشاهده منبع: " .
                    esc_html($d["source_observed_at"] ?? "نامعلوم") .
                    " | پوشش: " .
                    esc_html($d["coverage"]) .
                    "</p>";
            }
            echo "</td><td>";
            if (
                $table === "proposals" &&
                $r["status"] === "ready" &&
                empty($r["data"]["is_test"]) &&
                current_user_can("vsp_apply")
            ) {
                $this->begin("apply", ["proposal_id" => $id]);
                echo '<label><input type="checkbox" name="confirmed" value="1" required>اجرای همین پیشنهاد را تأیید می‌کنم</label>';
                $this->end("اعمال محدود");
            }
            if (
                $table === "changes" &&
                $r["status"] === "applied" &&
                empty($r["data"]["rollback_of"]) &&
                current_user_can("vsp_apply")
            ) {
                $this->begin("rollback", ["change_id" => $id]);
                $this->end("بازگردانی مشروط");
            }
            if ($table === "mappings" && current_user_can("vsp_manage")) {
                echo '<a class="button" href="' .
                    esc_url(
                        add_query_arg(
                            [
                                "page" => "viratel-smart-pricing",
                                "tab" => "products",
                                "edit" => $id,
                            ],
                            admin_url("admin.php"),
                        ),
                    ) .
                    '">ویرایش</a>';
                $this->begin("unlock", ["product_id" => $id]);
                $this->end("رفع قفل دستی");
            }
            if ($table === "imports" && current_user_can("vsp_manage")) {
                if (in_array($r["status"], ["validated", "applying"], true)) {
                    $this->begin("csv_apply", ["import_id" => $id]);
                    $this->end("تأیید پیش‌نمایش و اعمال تنظیمات");
                }
                if (in_array($r["status"], ["applied", "rolling_back"], true)) {
                    $this->begin("csv_rollback", ["import_id" => $id]);
                    $this->end("بازگردانی مشروط تنظیمات ورود");
                }
                if ($r["status"] === "validating") {
                    $this->begin("csv_validate", ["import_id" => $id]);
                    $this->end("ادامه پیش‌نمایش");
                }
            }
            echo "</td></tr>";
        }
        if (!$rows) {
            echo '<tr><td colspan="3">هنوز داده‌ای ثبت نشده است.</td></tr>';
        }
        echo "</tbody></table></div>";
        echo "<p>";
        if ($page > 1) {
            echo '<a class="button" href="' .
                esc_url(add_query_arg("paged", $page - 1)) .
                '">قبلی</a> ';
        }
        if (count($rows) === 20) {
            echo '<a class="button" href="' .
                esc_url(add_query_arg("paged", $page + 1)) .
                '">بعدی</a>';
        }
        echo "</p>";
    }
    public function label(string $code): string
    {
        $labels = [
            "disabled" => "غیرفعال",
            "ready_monitoring" => "آماده پایش",
            "proposal_ready" => "پیشنهاد آماده",
            "ready" => "پیشنهاد آماده",
            "hold" => "متوقف برای بررسی",
            "applied" => "اعمال‌شده",
            "intent" => "قصد تغییر ثبت‌شده؛ نیازمند بررسی نتیجه",
            "cancelled" => "لغوشده",
            "conflict" => "تعارض تغییر",
            "expired" => "منقضی",
            "monitoring" => "پایش",
            "source_time_unknown" => "زمان داده منبع نامعلوم",
            "stale_or_unknown" => "داده کهنه یا زمان مشاهده نامعلوم",
            "own_identity_missing" => "هویت فروشگاه خودمان ثبت نشده",
            "identity_ambiguous" => "هویت فروشنده قابل مقایسه نیست",
            "match_unconfirmed" => "تطبیق مدل و کیفیت تأیید نشده",
            "sale_conflict" => "تعارض با تخفیف فعال یا زمان‌دار",
            "manual_lock" => "قفل ویرایش دستی",
            "paused" => "توقف سراسری",
            "no_competitor" => "رقیب معتبر باقی نمانده",
            "floor_limited" => "محدود به کف",
            "ceiling_limited" => "محدود به سقف",
            "change_limit" => "تغییر بیشتر از سقف مجاز هر بار",
            "daily_change_limit" => "سقف تغییر تجمعی روزانه",
            "cooldown" => "فاصله مجاز از تغییر قبلی نگذشته",
            "single_competitor" => "رقیب کافی برای اجرای بدون بررسی نیست",
            "outlier_review" => "قیمت پرت؛ بررسی انسانی لازم است",
            "coverage_insufficient" => "پوشش فروشندگان کافی نیست",
            "valid_proposal" => "پیشنهاد معتبر در حدود",
            "negligible_change" => "تغییر ناچیز؛ قیمت قبلی حفظ می‌شود",
            "no-op" => "بدون تغییر",
            "network_error" => "خطای اتصال منبع",
            "provider_budget_exhausted" => "بودجه دریافت تمام شده",
            "provider_circuit_open" => "منبع موقتاً متوقف است",
            "redirect_pending" => "ادامه دریافت از لینک canonical در صف",
            "validating" => "پیش‌نمایش در حال بررسی",
            "validated" => "پیش‌نمایش معتبر؛ در انتظار تأیید مدیر",
            "applying" => "اعمال مرحله‌ای تنظیمات",
            "rolling_back" => "بازگردانی مرحله‌ای تنظیمات",
            "rolled_back" => "تنظیمات بازگردانده شد",
            "invalid" => "ورودی نامعتبر",
            "source_error" => "خطای منبع",
            "cost_stale" => "هزینه خرید کهنه یا زمان آن نامعلوم",
        ];
        return $labels[$code] ?? $code;
    }
    public function handle(): void
    {
        $op = sanitize_key($_POST["operation"] ?? "");
        $cap = in_array($op, ["apply", "rollback"], true)
            ? "vsp_apply"
            : ($op === "csv_export"
                ? "vsp_view"
                : "vsp_manage");
        if (!current_user_can($cap)) {
            wp_die("دسترسی مجاز نیست.", "", ["response" => 403]);
        }
        check_admin_referer("vsp_admin");
        $result = "ok";
        try {
            $repo = new Repository();
            $post = wp_unslash($_POST);
            $id = absint($post["product_id"] ?? 0);
            if (in_array($op, ["pause", "resume"], true)) {
                $s = Settings::get();
                $s["paused"] = $op === "pause";
                update_option("vsp_settings", $s, false);
            } elseif ($op === "settings") {
                $s = Settings::get();
                if (
                    !in_array(
                        $post["mode"] ?? "",
                        ["monitor", "suggest", "auto"],
                        true,
                    ) ||
                    !in_array($post["currency"] ?? "", ["", "IRT", "IRR"], true)
                ) {
                    throw new \InvalidArgumentException("settings_invalid");
                }
                $s["mode"] = $post["mode"];
                $s["currency"] = $post["currency"];
                $domains = array_filter(
                    array_map("trim", explode(",", $post["own_domains"] ?? "")),
                );
                $s["own_domains"] = [];
                foreach ($domains as $d) {
                    $host = \Viratel\SmartPricing\Domain\PricingEngine::host(
                        $d,
                    );
                    if (!$host) {
                        throw new \InvalidArgumentException(
                            "own_domain_invalid",
                        );
                    }
                    $s["own_domains"][] = $host;
                }
                $ids = array_filter(
                    array_map(
                        "trim",
                        explode(",", $post["own_store_ids"] ?? ""),
                    ),
                );
                foreach ($ids as $store) {
                    if (!ctype_digit($store)) {
                        throw new \InvalidArgumentException("store_id_invalid");
                    }
                }
                $s["own_store_ids"] = array_values($ids);
                foreach (["daily_budget", "monthly_budget"] as $k) {
                    $s[$k] = max(0, min(1000000, absint($post[$k] ?? 0)));
                }
                foreach (["source_configured", "pause_monitor"] as $k) {
                    $s[$k] = ($post[$k] ?? "0") === "1";
                }
                update_option("vsp_settings", $s, false);
            } elseif ($op === "mapping") {
                $attrs = [];
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
                    if (($post[$key] ?? "") !== "") {
                        $attrs[$key] = sanitize_text_field($post[$key]);
                    }
                }
                $current = $repo->get("mappings", $id);
                $oldRule = $current
                    ? $repo->get("rules", $current["rule_version"])["data"]
                    : [];
                $currency = $post["rule_currency"] ?? "IRT";
                $rule = array_merge($oldRule, [
                    "currency" => $currency,
                    "floor" => $post["floor"] ?? "",
                    "ceiling" => $post["ceiling"] ?? "",
                    "delta" => $post["delta"] ?? "1000",
                    "delta_bps" => \Viratel\SmartPricing\Domain\Money::parse(
                        $post["delta_percent"] ?? "1",
                        "IRR",
                    ),
                    "round_step" => $post["round_step"] ?? "1",
                    "type" => $post["rule_type"] ?? "fixed_below",
                    "max_change_bps" => \Viratel\SmartPricing\Domain\Money::parse(
                        $post["max_change_percent"] ?? "10",
                        "IRR",
                    ),
                    "max_daily_change" => $post["max_daily_change"] ?? "50000",
                    "basis" => $post["basis"] ?? "min",
                ]);
                (new MappingService())->save(
                    $id,
                    [
                        "source_url" => $post["source_url"] ?? "",
                        "attributes" => $attrs,
                        "match_status" => $post["match_status"] ?? "pending",
                        "mode" => $post["mode"] ?? "monitor",
                        "enabled" => ($post["enabled"] ?? "1") === "1",
                        "schedule_group" => $post["schedule_group"] ?? "normal",
                    ],
                    $rule,
                    $post["expected_version"] ?? "",
                    $post["expected_mapping_hash"] ?? "",
                );
            } elseif ($op === "group") {
                $key = sanitize_key($post["group_key"] ?? "");
                if (!$key) {
                    throw new \InvalidArgumentException("group_invalid");
                }
                $row = [
                    "data" => [
                        "interval" => max(
                            900,
                            absint($post["interval"] ?? 21600),
                        ),
                        "max_source_age" => max(
                            60,
                            absint($post["max_source_age"] ?? 21600),
                        ),
                    ],
                ];
                if ($repo->get("groups", $key)) {
                    $repo->update("groups", $key, $row);
                } else {
                    $repo->insert(
                        "groups",
                        array_merge(["group_key" => $key], $row),
                    );
                }
            } elseif ($op === "preview") {
                (new ProposalService())->create(
                    $id,
                    sanitize_text_field($post["snapshot_id"] ?? ""),
                );
            } elseif ($op === "apply") {
                if (($post["confirmed"] ?? "") !== "1") {
                    throw new \RuntimeException("confirmation_required");
                }
                (new PriceApplier())->apply(
                    sanitize_text_field($post["proposal_id"] ?? ""),
                    true,
                );
            } elseif ($op === "rollback") {
                (new PriceApplier())->rollback(
                    sanitize_text_field($post["change_id"] ?? ""),
                );
            } elseif ($op === "unlock") {
                (new MappingService())->unlock($id);
            } elseif ($op === "poll") {
                if (!function_exists("as_enqueue_async_action")) {
                    throw new \RuntimeException("scheduler_unavailable");
                }
                as_enqueue_async_action(
                    "vsp_poll",
                    [$id, 0],
                    QueueService::GROUP,
                    true,
                );
            } elseif ($op === "csv_stage") {
                $file = $_FILES["csv_file"] ?? null;
                if (
                    !$file ||
                    $file["error"] !== UPLOAD_ERR_OK ||
                    !is_uploaded_file($file["tmp_name"]) ||
                    $file["size"] > 2097152
                ) {
                    throw new \InvalidArgumentException("csv_upload_invalid");
                }
                $import = (new CsvService())->stage(
                    file_get_contents($file["tmp_name"]),
                );
                as_enqueue_async_action(
                    "vsp_import_validate",
                    [$import],
                    QueueService::GROUP,
                    true,
                );
            } elseif ($op === "csv_validate") {
                $this->validateImport(
                    sanitize_text_field($post["import_id"] ?? ""),
                );
            } elseif ($op === "csv_rollback") {
                as_enqueue_async_action(
                    "vsp_import_rollback",
                    [sanitize_text_field($post["import_id"] ?? "")],
                    QueueService::GROUP,
                    true,
                );
            } elseif ($op === "csv_apply") {
                as_enqueue_async_action(
                    "vsp_import_apply",
                    [sanitize_text_field($post["import_id"] ?? "")],
                    QueueService::GROUP,
                    true,
                );
            } elseif ($op === "csv_export") {
                nocache_headers();
                header("Content-Type: text/csv; charset=utf-8");
                header(
                    "Content-Disposition: attachment; filename=viratel-mappings.csv",
                );
                (new CsvService())->export();
                exit();
            } else {
                throw new \InvalidArgumentException("operation_invalid");
            }
        } catch (\Throwable $e) {
            $result = sanitize_key($e->getMessage());
            if ($result === "") {
                $result = "operation_failed";
            }
        }
        wp_safe_redirect(
            add_query_arg(
                ["page" => "viratel-smart-pricing", "result" => $result],
                admin_url("admin.php"),
            ),
        );
        exit();
    }
}
