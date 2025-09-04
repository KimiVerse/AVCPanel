# ربات AVCPanel 🚀

[![GitHub لایسنس](https://img.shields.io/github/license/KimiVerse/AVCPanel)](https://github.com/KimiVerse/AVCPanel/blob/main/LICENSE)
[![ستاره‌های GitHub](https://img.shields.io/github/stars/KimiVerse/AVCPanel)](https://github.com/KimiVerse/AVCPanel/stargazers)
[![موارد GitHub](https://img.shields.io/github/issues/KimiVerse/AVCPanel)](https://github.com/KimiVerse/AVCPanel/issues)
[![نسخه PHP](https://img.shields.io/badge/php-%3E=7.0-آبی)](https://php.net)

رباتی قدرتمند در تلگرام برای مدیریت پنل‌های VPN، خدمات کاربران و ویژگی‌های ادمین. با PHP و PDO برای تعامل امن با پایگاه داده ساخته شده است.

![تصویر ربات AVCPanel](https://via.placeholder.com/800x400?text=ربات+AVCPanel+دمو) <!-- جایگزین با URL تصویر واقعی کنید -->

## ویژگی‌ها ✨
- **مدیریت کاربران**: ثبت‌نام، موجودی، خدمات و دعوت‌نامه‌ها.
- **پنل ادمین**: مدیریت پنل‌ها، پلن‌ها، تیکت‌ها و آمار.
- **پرداخت‌ها**: پرداخت کارت به کارت و از موجودی.
- **امنیت**: داده‌های رمزنگاری‌شده، اجبار عضویت در کانال و ادغام API.
- **وظایف زمان‌بندی‌شده**: حذف خودکار خدمات منقضی و ارسال هشدارها.
- **تیکت و پشتیبانی**: سیستم تیکتینگ درون‌رباتی.
- **آموزش‌ها**: راهنماهای پلتفرم‌محور.

## فناوری‌های استفاده‌شده 🛠️
- **زبان**: PHP 7+ (با cURL، PDO، OpenSSL).
- **پایگاه داده**: MySQL (utf8mb4 برای پشتیبانی پارسی).
- **وابستگی‌ها**: jdf.php برای تاریخ جلالی.
- **API**: API ربات تلگرام.

## نصب و استقرار 📦
جزئیات گام‌به‌گام را در [DEPLOYMENT.md](DEPLOYMENT.md) ببینید.

## شروع سریع ⚡
1. مخزن را کلون کنید: `git clone https://github.com/KimiVerse/AVCPanel.git`
2. پایگاه داده را راه‌اندازی کنید: فایل `table.php` را اجرا کنید.
3. پیکربندی کنید: فایل `setting/config.example.php` را کپی کرده و به `setting/config.php` تغییر نام دهید و اطلاعات خود را وارد کنید.
4. روی سروری با PHP و MySQL میزبانی کنید.
5. وب‌هوک را تنظیم کنید: از `/setWebhook` تلگرام استفاده کنید یا اسکریپت را به‌صورت بلندپالینگ اجرا کنید.

## مشارکت 🤝
فورک کنید، تغییرات را اعمال کنید و درخواست ادغام (Pull Request) ارسال کنید. کد رفتار را در [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) دنبال کنید.

## لایسنس 📄
این پروژه تحت لایسنس MIT منتشر شده است - جزئیات را در [LICENSE](LICENSE) ببینید.

**توسعه‌دهنده: کی‌میا | GitHub: [KimiVerse](https://github.com/KimiVerse/AVCPanel) | ادمین: @amirmasoud_rsli**

*توجه: کپی یا ویرایش بدون اجازه صراحتاً ممنوع است.*
