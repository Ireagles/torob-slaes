# محیط توسعه محلی

مخزن اولیه در /workspace/torob-slaes خالی و بدون AGENTS.md بود. dependencies و دیتابیس در /workspace/vsp-runtime، خارج از کد و ZIP نگهداری می‌شوند؛ هیچ credential سایت واقعی درخواست یا استفاده نشد. MariaDB فقط Unix socket دارد؛ HTTP توسعه فقط 127.0.0.1:8090 است. تست بسته از دیتابیس جدا vsp_package استفاده می‌کند.

`tools/setup-runtime.sh` PHP و MariaDB را از بسته‌های Debian امضاشده داخل workspace استخراج می‌کند؛ WordPress و WooCommerce از انتشارهای رسمی گرفته می‌شوند. system package یا vendor code تغییر نکرده است. `tools/start-runtime.sh` پس از restore فرایندها را شروع و SELECT 1، core is-installed و vsp status را بررسی می‌کند. اجرای idempotent روی محیط موجود موفق بود؛ بازسازی روی ماشین تازه یا snapshot بعدی هنوز مستقل تأیید نشده است.

پیش‌نویس پیکربندی cloud با install_script و start_skill ذخیره شد. دامنه‌های افزوده شبکه: torob.com، www.torob.com، wordpress.org، api.wordpress.org، downloads.wordpress.org، developer.wordpress.org، developer.woocommerce.com و actionscheduler.org؛ presetهای package manager حفظ شدند. ذخیره draft به معنی اعمال یا انتشار نیست؛ برای محیط‌های بعدی در تنظیمات محیط Review/Save و سپس Publish لازم است. انجام توسعه به این انتشار وابسته نشده است.

اتصال Python از proxy محیط موفق بود؛ DNS امن WordPress برای ترب هنوز blocked است. خاموش‌کردن TLS/SSRF راه‌حل نیست. نسخه‌ها و آزمون‌ها در test-report.md ثبت شده‌اند. MU plugin خاموش‌کردن async برای benchmark، runtime، رمزهای local، فایل تنظیمات DB و dependencies در بسته نصب یا source ZIP نیستند.
