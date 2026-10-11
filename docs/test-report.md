# گزارش آزمون 0.1.0

تاریخ ۱۰ اکتبر ۲۰۲۶. همه نوشتن‌های آزمون روی WordPress محلی مستقل و محصولات برچسب‌خورده آزمایشی بوده‌اند. سایت ویراتل در دسترس این اجرا نبوده و تغییر نکرده است.

| نوع شاهد | نتیجه | حدود نتیجه |
|---|---|---|
| Unit / property | PASS، ۸۴ assertion | مثال‌های بریف، money دقیق، کیفیت/خودی/عمر/پوشش، bounds/سود/گردکردن، ۲۰۰۰ بازار متفاوت، parser روی HTML واقعی ضبط‌شده. |
| Integration Woo/DB | PASS، ۹۷ assertion | CRUD واقعی ساده/variation، idempotency، intent و crash قبل/بعد save، manual edit، pause وسط کار، snapshot/rule/group stale، sale، ریال/تومان، rollback، nonce/cap، CSV transaction/conflict، lease و HTTP شبیه‌سازی‌شده. |
| مرورگر | PASS، ۲۸ بررسی | دسکتاپ ۱۴۴۰×۱۰۰۰ و موبایل ۳۹۰×۸۴۴، همه ۹ tab، جست‌وجوی SKU، ویرایش persist و stale conflict، pause/resume، nonce نامعتبر 403، بدون خطای JavaScript؛ CSS افزونه در storefront بارگیری نشد. |
| Benchmark | PASS داخلی | ۲۰۰۰ محصول محلی / ۸۰۰۰ AS job، شواهد و محدودیت‌ها در benchmark.md. |
| Live Python | PASS خواندنی | سه صفحه واقعی ترب و prices IRR؛ آخرین probe timestamp دقیق در torob-live-probe.json. observation/quality/identity نامعلوم؛ خودکار blocked. |
| Live PHP / WP safe HTTP | BLOCKED محیط | DNS Torob resolve نشد؛ WordPress safe transport URL را نپذیرفت. TLS/SSRF خاموش نشده؛ هیچ قیمت جعلی جای موفقیت قرار نگرفت. |
| نصب بسته مستقل | گزارش package-smoke.json | آزمون ZIP واقعی جدا از symlink checkout؛ نصب اولیه passive، ۹ جدول، CRUD/حفظ داده پس از جایگزینی نسخه و غیرفعال/فعال. |
| پایلوت ۳–۷ روز و production | PENDING | داده/نسخه مقصد و مجوز مستقل تولید موجود نیست. |

PHP lint همه ۳۴ فایل توسعه و افزونه را گذراند. کد با syntax هدف PHP 8.1 قالب‌بندی شده؛ اجرای واقعی فقط روی PHP 8.4.24 آزموده شده است. WP 7.1.3، WC 11.2.1، AS 4.1.0 و MariaDB 11.8.6 ماتریس آزموده‌شده‌اند. ادعای سازگاری نسخه‌های دیگر یا HPOS وجود ندارد.

## QA رابط

Browser plugin در ابزارهای این نشست موجود نبود؛ Playwright با Chromium واقعی محلی استفاده شد. screenshot دسکتاپ و موبایل مشاهده شد. رابط افزونه فارسی/RTL است؛ پوسته عمومی مدیریت WordPress محلی زبان انگلیسی داشت. فرم واحد قاعده موجود را حفظ می‌کند و قیمت‌های پیشنهاد را با تومان/ریال نمایش می‌دهد. جزئیات فنی در بخش بازشونده جدا هستند. جدول‌های عریض داخل scroll container هستند؛ dashboard موبایل overflow نداشت. fixture برچسب آزمایشی دارد و دکمه اعمال قیمت واقعی آن پنهان است؛ منع backend نیز مستقل برقرار است.

## اجرای دوباره

```sh
bash tools/setup-runtime.sh
bash tools/start-runtime.sh
/workspace/vsp-runtime/bin/php tests/Unit/run.php
/workspace/vsp-runtime/bin/wp eval-file tests/Integration/run.php
node tests/Browser/admin.cjs
bash tools/benchmark.sh
python3 tools/build.py
bash tools/package-smoke.sh
```

Integration/benchmark را همزمان اجرا نکنید؛ تنظیمات سراسری آزمایشی را تغییر می‌دهند. Browser test نیازمند Playwright/Chromium و حساب local در فایل خارج مخزن /workspace/vsp-runtime/ui-credentials.json است؛ مقادیر رمز در بسته نیستند. setup اولیه حساب تصادفی می‌سازد؛ برای اجرای مرورگر بعدی حساب آزمون محلی را جدا تنظیم کنید. HTTP403/429/timeout/challenge/redirect/oversize در integration **شبیه‌سازی** هستند؛ parser fixture **بازپخش** است؛ این دو آزمون live نیستند.

مشکلات شناخته‌شده: AS CLI resolution و شمارنده پایانی، deadlock اولیه اصلاح‌شده و محدودیت میان‌افزونه‌ای در benchmark/architecture ثبت شده‌اند. آزمون فشار واقعی منبع، پایلوت چندروزه، سایت مقصد و همزیستی نویسندگان قیمت همچنان لازم‌اند.
