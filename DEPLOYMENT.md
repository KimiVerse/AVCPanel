# راهنمای استقرار ربات AVCPanel 📚

## پیش‌نیازها
- PHP 7+ با افزونه‌ها: cURL، PDO، OpenSSL.
- پایگاه داده MySQL (MariaDB یا مشابه).
- توکن ربات تلگرام (از @BotFather).
- سرور با پشتیبانی از وظایف زمان‌بندی‌شده (برای corn.php و mag.php).
- Git نصب‌شده (اختیاری برای کلون کردن).

## مراحل استقرار گام‌به‌گام

### 1. کلون کردن مخزن
git clone https://github.com/KimiVerse/AVCPanel.git
cd AVCPanel

### 2. راه‌اندازی پایگاه داده
- یک پایگاه داده MySQL و کاربر ایجاد کنید.
- فایل `setting/config.php` را ویرایش کنید (از `config.example.php` کپی کنید):
  - `BOT_TOKEN`، `OWNER_ID`، `DB_HOST` و غیره را تنظیم کنید.
- فایل `table.php` را در مرورگر اجرا کنید (مثلاً http://yourdomain.com/table.php) برای ایجاد جداول.

### 3. پیکربندی فایل‌ها
- `setting/config.php` را با اطلاعات خود به‌روز کنید.
- مطمئن شوید `jdf.php` و `functions.php` در پوشه `setting/` هستند.

### 4. آپلود به سرور
- از FTP (FileZilla) برای آپلود همه فایل‌ها به هاست خود (مثلاً public_html) استفاده کنید.
- دسترسی‌ها را تنظیم کنید: پوشه‌ها 755، فایل‌ها 644.

### 5. تنظیم وب‌هوک تلگرام (توصیه‌شده برای تولید)
- در مرورگر اجرا کنید: `https://api.telegram.org/botYOUR_TOKEN/setWebhook?url=https://yourdomain.com/bot.php`
- یا از بلندپالینگ استفاده کنید: اسکریپت `bot.php` را در یک حلقه اجرا کنید (مناسب تولید نیست).

### 6. تنظیم وظایف زمان‌بندی‌شده
- در cPanel یا crontab:
  - هر 5-15 دقیقه: `php /path/to/corn.php?cron_secret=avcpanel`
  - هر 1-5 دقیقه: `php /path/to/mag.php?cron_secret=avcpanel` (برای صف پیام).

### 7. تست
- به ربات پیام دهید: /start.
- خطاها را در لاگ‌ها بررسی کنید (error_log در PHP).

### رفع مشکلات
- خطاهای پایگاه داده: اعتبارنامه‌ها را در config.php بررسی کنید.
- مشکلات cURL: مطمئن شوید SSL فعال است.
- متن پارسی: کدگذاری پایگاه داده باید utf8mb4 باشد.

برای کمک، یک مورد در GitHub باز کنید.