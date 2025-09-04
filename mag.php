<?php
//--------------------------------------------------------------------------------
// By KiMia
// Github AVCPANEL 
// Adminstration @amirmasoud_rsli
//--------------------------------------------------------------------------------

require_once __DIR__ . '/setting/config.php';
require_once __DIR__ . '/setting/functions.php';


if (php_sapi_name() !== 'cli' && !isset($_GET['cron_secret'])) die('Access Denied');
if(isset($_GET['cron_secret']) && $_GET['cron_secret'] !== CRON_SECRET) die('Invalid Secret');

$conn = getDbConnection();
if (!$conn) die("Database connection failed.");


$queue_stmt = $conn->query("SELECT * FROM message_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 1");
$queue_item = $queue_stmt->fetch(PDO::FETCH_ASSOC);

if (!$queue_item) {
    echo "No pending messages in queue.";
    exit();
}

$queue_id = $queue_item['id'];
$limit = 150; 

$progress_stmt = $conn->prepare("SELECT last_user_id FROM message_progress WHERE queue_id = ?");
$progress_stmt->execute([$queue_id]);
$last_user_id = $progress_stmt->fetchColumn() ?: 0;

$users_stmt = $conn->prepare("SELECT user_id FROM users WHERE user_id > ? ORDER BY user_id ASC LIMIT ?");
$users_stmt->execute([$last_user_id, $limit]);
$users_to_send = $users_stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($users_to_send)) {
    $conn->prepare("UPDATE message_queue SET status = 'completed' WHERE id = ?")->execute([$queue_id]);
    $conn->prepare("DELETE FROM message_progress WHERE queue_id = ?")->execute([$queue_id]);
    sendMessage(OWNER_ID, "✅ ارسال همگانی با شناسه #{$queue_id} با موفقیت به پایان رسید.");
    echo "Broadcast #{$queue_id} completed.";
    exit();
}

$sent_this_run = 0;
foreach ($users_to_send as $user_id) {
    $success = false;
    if ($queue_item['message_type'] == 'text') {
        $result = sendMessage($user_id, $queue_item['content']);
        if($result && isset($result['message_id'])) $success = true;
    } elseif ($queue_item['message_type'] == 'forward') {
        $result = apiRequest('forwardMessage', [
            'chat_id' => $user_id,
            'from_chat_id' => $queue_item['from_chat_id'],
            'message_id' => $queue_item['content']
        ]);
        if($result && isset($result['message_id'])) $success = true;
    }
    
    if($success){
        $sent_this_run++;
    }
    usleep(200000);
}

$new_sent_count = $queue_item['sent_count'] + $sent_this_run;
$last_processed_user_id = end($users_to_send);

$conn->prepare("UPDATE message_queue SET sent_count = ? WHERE id = ?")->execute([$new_sent_count, $queue_id]);
$conn->prepare("REPLACE INTO message_progress (queue_id, last_user_id) VALUES (?, ?)")->execute([$queue_id, $last_processed_user_id]);

echo "Processed {$sent_this_run} messages for queue #{$queue_id}. Last user ID: {$last_processed_user_id}.";

?>