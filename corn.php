<?php

//--------------------------------------------------------------------------------
// By KiMia
// Github AVCPANEL 
// Adminstration @amirmasoud_rsli
//--------------------------------------------------------------------------------

require_once __DIR__ . '/setting/config.php';
require_once __DIR__ . '/setting/functions.php';


if (php_sapi_name() !== 'cli' && !isset($_GET['cron_secret'])) {
    die('Access Denied');
}
if(isset($_GET['cron_secret']) && $_GET['cron_secret'] !== CRON_SECRET){
    die('Invalid Secret');
}


$conn = getDbConnection();
if (!$conn) {
    error_log("Cron Job failed: Database connection failed.");
    die("Database connection failed.");
}

$settings_stmt = $conn->query("SELECT * FROM settings");
$settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$delete_grace_days = (int)($settings['expiration_delete_days'] ?? 3);

echo "Starting Cron Job...\n";

try {
    $services_to_delete_stmt = $conn->prepare("
        SELECT s.*, p.url as panel_url, p.api_token 
        FROM services s 
        JOIN panels p ON s.panel_id = p.id 
        WHERE s.expiration_date < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $services_to_delete_stmt->execute([$delete_grace_days]);
    $services_to_delete = $services_to_delete_stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($services_to_delete) . " services to delete.\n";

    foreach ($services_to_delete as $service) {
        $api_result = deleteVpnUser($service['panel_url'], $service['api_token'], $service['vpn_username']);
        
        if ($api_result['success']) {
            $conn->prepare("DELETE FROM services WHERE id = ?")->execute([$service['id']]);
            sendMessage($service['user_id'], "⚠️ سرویس شما با نام کاربری `{$service['vpn_username']}` به دلیل عدم تمدید در مهلت مقرر، به طور کامل از سیستم حذف گردید.");
            echo "Service {$service['vpn_username']} for user {$service['user_id']} successfully deleted.\n";
            error_log("Service {$service['vpn_username']} for user {$service['user_id']} successfully deleted.");
        } else {
            echo "Failed to delete service {$service['vpn_username']} via API. Error: " . $api_result['error'] . "\n";
            error_log("Failed to delete service {$service['vpn_username']} via API. Error: " . $api_result['error']);
        }
    }
} catch (PDOException $e) {
    error_log("Cron Job (Delete Section) DB Error: " . $e->getMessage());
}

try {
    $services_to_warn_stmt = $conn->prepare("
        SELECT * FROM services 
        WHERE expiration_date < NOW() 
          AND expiration_date >= DATE_SUB(NOW(), INTERVAL ? DAY) 
          AND warning_sent = 0
    ");
    $services_to_warn_stmt->execute([$delete_grace_days]);
    $services_to_warn = $services_to_warn_stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($services_to_warn) . " services to warn.\n";

    foreach ($services_to_warn as $service) {
        $message = "⚠️ کاربر گرامی، سرویس شما با نام کاربری `{$service['vpn_username']}` منقضی شده است.\n\n";
        $message .= "برای جلوگیری از حذف کامل سرویس، لطفا طی <b>{$delete_grace_days} روز</b> آینده از طریق دکمه 'سرویس های من' اقدام به تمدید آن نمایید.";
        
        sendMessage($service['user_id'], $message);
        
        $conn->prepare("UPDATE services SET warning_sent = 1 WHERE id = ?")->execute([$service['id']]);
        echo "Expiration warning sent to user {$service['user_id']} for service {$service['vpn_username']}.\n";
        error_log("Expiration warning sent to user {$service['user_id']} for service {$service['vpn_username']}.");
    }
} catch (PDOException $e) {
    error_log("Cron Job (Warning Section) DB Error: " . $e->getMessage());
}

echo "Cron job executed successfully.";

?>