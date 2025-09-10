<?php
//--------------------------------------------------------------------------------
// By KiMia
// Github AVCPANEL 
// Adminstration @amirmasoud_rsli
//--------------------------------------------------------------------------------

define('BOT_TOKEN', 'YOUR_TOKEN_HERE');  //توکن قرار دهید سر جا Token

define('OWNER_ID', 'YOUR_ADMIN_ID_HERE'); //آیدی مالک ادمین ها قابل تنظیم هستن داخل پنل

define('DB_HOST', 'localhost'); //دست زده نشود در صورت نیاز.
define('DB_NAME', 'avcpanel'); //نام دیتابیس 
define('DB_USER', 'avcpanel'); //یوزرنیم دیتابیس 
define('DB_PASS', 'YOUR_DB_PASSWORD_HERE'); //پسورد دیتابیس 

define('ENCRYPTION_KEY', 'YOUR_ENCRYPTION_KEY_HERE'); // دست زده نشود
define('BOT_USERNAME', 'avcpanelbot'); //یوزرنیم ربات بدون @

define('CRON_SECRET', 'YOUR_CRON_SECRET_HERE'); //رمز کل وب های پایین

// https://yourdomain.com/namefolder/cron.php?cron_secret=YOUR_CRON_SECRET_HERE
//کرون جابز باید به صورت بالا انجام شه تا بتونه تشخیص بده سرویس های معلق شده
// https://yourdomain.com/namefolder/msg.php?cron_secret=YOUR_CRON_SECRET_HERE
//کرون جابز باید به صورت بالا انجام شه تا بتونه پیام های فوروارد شده با همگانی رو کنترل کنه
?>