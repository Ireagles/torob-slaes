=== Viratel Smart Pricing ===
Contributors: viratel
Tags: woocommerce, pricing, monitoring
Requires at least: 7.0
Tested up to: 7.1.3
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

پایش و پیشنهاد قیمت قطعات موبایل با کف/سقف و کنترل ایمنی.

== Description ==
نسخه آزمایشی برای staging؛ پیشفرض پایش و بدون درخواست خودکار بیرونی.
Provider واقعی JSON-LD عمومی ترب برای پایش دارد؛ زمان مشاهده، کیفیت و هویت فروشندگان در آن احراز نشده و اجازه اعمال قیمت ندارد.
هسته قطعی، تطبیق دقیق، حذف خودی، جدول تاریخچه، پیشنهاد نسخه‌دار، WooCommerce CRUD، rollback مشروط، Action Scheduler و CSV chunked دارد.
هدف «کمترین مشاهده‌شده معتبر» است؛ رتبه اول ترب تضمین نمی‌شود.

== Installation ==
WooCommerce را فعال و ZIP را نصب کنید. WooCommerce -> قیمت‌گذاری ویراتل.
ابتدا واحد واقعی، هویت خودی، لینک دقیق، مدل/کیفیت و حدود را روی staging تنظیم کنید.
پایش برنامه‌ریزی‌شده پیشفرض خاموش است. راهنمای فارسی و محدودیت‌های اتصال را بخوانید.

== Frequently Asked Questions ==
= آیا آماده تغییر خودکار قیمت تولید است؟ =
خیر؛ پایلوت و داده معتبر منبع هنوز لازم‌اند. داده عمومی فعلی فقط پایش است.
= آیا موجودی، سفارش یا قالب فروشگاه را تغییر می‌دهد؟ =
خیر. CSS فقط در صفحه پنل افزونه بارگذاری می‌شود.
= آیا غیرفعال‌سازی داده را پاک می‌کند؟ =
خیر؛ update و deactivation داده را حفظ می‌کنند. uninstall هم پیشفرض حفظ داده است.

== Changelog ==
= 0.1.0 =
هسته آزمایشی، پایش واقعی عمومی با محدودیت روشن، موتور دقیق و کنترل تغییر، CSV و صف.
