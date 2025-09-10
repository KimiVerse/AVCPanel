<?php

//======================================================================
//
// By KiMia
// Github: AVCPANEL
// Administrator: @amirmasoud_rsli
//
//======================================================================

/**
 * ----------------------------------------------------------------------
 * SECTION 1: REQUIRED SETTINGS (MUST BE CHANGED)
 * ----------------------------------------------------------------------
 *
 * Fill in these values with your specific details.
 */

// Your bot's unique authentication token from BotFather.
define('BOT_TOKEN', 'YOUR_TOKEN_HERE');

// The Telegram user ID of the bot's primary owner.
define('OWNER_ID', 'YOUR_ADMIN_ID_HERE');

// The password for your database user.
define('DB_PASS', 'YOUR_DB_PASSWORD_HERE');


/**
 * ----------------------------------------------------------------------
 * SECTION 2: ADVANCED SETTINGS (CHANGE ONLY IF NEEDED)
 * ----------------------------------------------------------------------
 *
 * It is recommended to leave these settings as they are unless you have
 * a custom server or database configuration.
 */

// The hostname of your database server.
// Leave as 'localhost' unless your database is on a different server.
define('DB_HOST', 'localhost');

// The name of the database.
// Change only if you used a different name during setup.
define('DB_NAME', 'avcpanel');

// The username for accessing the database.
// Change only if you used a different username during setup.
define('DB_USER', 'avcpanel');

// Your bot's username without the '@' symbol.
define('BOT_USERNAME', 'avcpanelbot');

// An encryption key for securing sensitive data.
// DO NOT CHANGE this value after the initial setup, as it can lead to data loss.
define('ENCRYPTION_KEY', 'YOUR_ENCRYPTION_KEY_HERE');

// A secret key to prevent unauthorized access to your cron job scripts.
define('CRON_SECRET', 'YOUR_CRON_SECRET_HERE');

/**
 * ----------------------------------------------------------------------
 * CRON JOB INSTRUCTIONS (NO CHANGES NEEDED HERE)
 * ----------------------------------------------------------------------
 * 
 * Use the following URLs to set up your cron jobs. Replace
 * 'YOUR_CRON_SECRET_HERE' with the secret key you defined above.
 *
 * For handling suspended services:
 * https://yourdomain.com/namefolder/cron.php?cron_secret=YOUR_CRON_SECRET_HERE
 *
 * For managing forwarded and broadcast messages:
 * https://yourdomain.com/namefolder/msg.php?cron_secret=YOUR_CRON_SECRET_HERE
 */

?>