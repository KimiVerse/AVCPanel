<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<pre style='font-family: monospace; line-height: 1.6;'>"; // برای نمایش خروجی خواناتر در مرورگر

require_once __DIR__ . '/setting/config.php';

try {

    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Successfully connected to the database: " . DB_NAME . "\n\n";

    $sql_users = "CREATE TABLE IF NOT EXISTS `users` (
      `id` BIGINT NOT NULL AUTO_INCREMENT,
      `user_id` BIGINT NOT NULL,
      `first_name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
      `last_name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `username` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `balance` BIGINT NOT NULL DEFAULT 0,
      `has_received_test` TINYINT(1) NOT NULL DEFAULT 0,
      `inviter_id` BIGINT NULL DEFAULT NULL,
      `join_status` TINYINT(1) NOT NULL DEFAULT 0,
      `join_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `step` VARCHAR(100) NULL DEFAULT NULL,
      `temp_data` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_users);
    echo "Table 'users' checked/created successfully.\n";

    $sql_admins = "CREATE TABLE IF NOT EXISTS `admins` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `user_id` BIGINT NOT NULL,
      `permissions` TEXT NULL DEFAULT NULL,
      `added_by` BIGINT NOT NULL,
      `add_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_admins);
    echo "Table 'admins' checked/created successfully.\n";

    $sql_panels = "CREATE TABLE IF NOT EXISTS `panels` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `name` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
      `url` TEXT NOT NULL,
      `username` VARCHAR(255) NOT NULL,
      `password` TEXT NOT NULL,
      `session_cookie` TEXT NULL DEFAULT NULL,
      `sticker` VARCHAR(255) NULL DEFAULT NULL,
      `api_token` TEXT NULL DEFAULT NULL,
      `status` TINYINT(1) NOT NULL DEFAULT 1,
      `added_by` BIGINT NOT NULL,
      `add_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_panels);
    echo "Table 'panels' checked/created successfully.\n";
    
    $sql_categories = "CREATE TABLE IF NOT EXISTS `categories` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `name` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
      `emoji` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_categories);
    echo "Table 'categories' checked/created successfully.\n";
    
    $sql_plans = "CREATE TABLE IF NOT EXISTS `plans` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `name` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
      `days` INT NOT NULL,
      `traffic` INT NOT NULL,
      `price` BIGINT NOT NULL,
      `description` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
      `status` TINYINT(1) NOT NULL DEFAULT 1,
      `panel_id` INT NOT NULL,
      `category_id` INT NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_plans);
    echo "Table 'plans' checked/created successfully.\n";
    
    $sql_services = "CREATE TABLE IF NOT EXISTS `services` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `user_id` BIGINT NOT NULL,
      `plan_id` INT NOT NULL,
      `panel_id` INT NOT NULL,
      `vpn_username` VARCHAR(255) NOT NULL,
      `purchase_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `expiration_date` TIMESTAMP NULL DEFAULT NULL,
      `warning_sent` TINYINT(1) NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_services);
    echo "Table 'services' checked/created successfully.\n";
    
    $sql_transactions = "CREATE TABLE IF NOT EXISTS `transactions` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `user_id` BIGINT NOT NULL,
      `plan_id` INT NOT NULL,
      `price` BIGINT NOT NULL,
      `tracking_code` VARCHAR(20) NULL DEFAULT NULL,
      `receipt_file_id` VARCHAR(255) NULL,
      `status` VARCHAR(50) NOT NULL DEFAULT 'pending',
      `request_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_transactions);
    echo "Table 'transactions' checked/created successfully.\n";

    $sql_join_channels = "CREATE TABLE IF NOT EXISTS `join_channels` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `channel_id` VARCHAR(100) NOT NULL,
      `channel_name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
      `invite_link` TEXT NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `channel_id` (`channel_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_join_channels);
    echo "Table 'join_channels' checked/created successfully.\n";

    $sql_message_queue = "CREATE TABLE IF NOT EXISTS `message_queue` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `message_type` VARCHAR(50) NOT NULL,
      `content` TEXT NOT NULL,
      `from_chat_id` BIGINT NULL DEFAULT NULL,
      `status` VARCHAR(50) NOT NULL DEFAULT 'pending',
      `total_users` INT NOT NULL,
      `sent_count` INT NOT NULL DEFAULT 0,
      `request_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_message_queue);
    echo "Table 'message_queue' checked/created successfully.\n";

    $sql_message_progress = "CREATE TABLE IF NOT EXISTS `message_progress` (
      `queue_id` INT NOT NULL,
      `last_user_id` BIGINT NOT NULL,
      PRIMARY KEY (`queue_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_message_progress);
    echo "Table 'message_progress' checked/created successfully.\n";
    
    $sql_tickets = "CREATE TABLE IF NOT EXISTS `tickets` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `user_id` BIGINT NOT NULL,
      `title` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
      `status` VARCHAR(50) NOT NULL DEFAULT 'open',
      `creation_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `last_update` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_tickets);
    echo "Table 'tickets' checked/created successfully.\n";

    $sql_ticket_replies = "CREATE TABLE IF NOT EXISTS `ticket_replies` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `ticket_id` INT NOT NULL,
      `sender_id` BIGINT NOT NULL,
      `message_text` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
      `sent_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_ticket_replies);
    echo "Table 'ticket_replies' checked/created successfully.\n";

    $sql_tutorials = "CREATE TABLE IF NOT EXISTS `tutorials` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `platform_name` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
      `video_file_id` VARCHAR(255) NULL DEFAULT NULL,
      `image_file_id` VARCHAR(255) NULL DEFAULT NULL,
      `text_content` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
      `download_link` TEXT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `platform_name` (`platform_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_tutorials);
    echo "Table 'tutorials' checked/created successfully.\n";


    $sql_settings = "CREATE TABLE IF NOT EXISTS `settings` (
      `setting_key` VARCHAR(100) NOT NULL,
      `setting_value` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
      PRIMARY KEY (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql_settings);
    echo "Table 'settings' checked/created successfully.\n";
    
    $pdo->exec("INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
      ('balance_payment_status', '1'),
      ('card_payment_status', '1'),
      ('bank_card_info', 'هنوز تنظیم نشده است.'),
      ('charge_card_status', '1'),
      ('charge_other_methods_status', '0'),
      ('test_account_status', '0'),
      ('test_account_traffic', '1'),
      ('test_account_days', '1'),
      ('test_account_panel_id', '0'),
      ('force_join_status', '0'),
      ('invitation_bonus', '5000'),
      ('invitation_status', '1'),
      ('expiration_delete_days', '3')
    ");
    $pdo->exec("DELETE FROM `settings` WHERE `setting_key` = 'force_join_channel_id'"); // Clean up old setting
    echo "Initial values for 'settings' inserted or updated.\n";
    
    $pdo->exec("INSERT IGNORE INTO `admins` (`user_id`, `permissions`, `added_by`) VALUES (".OWNER_ID.", '{\"all\":true}', ".OWNER_ID.")");
    echo "Owner set as the first admin successfully.\n";


    echo "\n\n✔️✔️✔️ All tables and initial settings are set up correctly, Kimia !";

} catch (PDOException $e) {
    die("❌ Database error: " . $e->getMessage());
}

echo "</pre>";

?>