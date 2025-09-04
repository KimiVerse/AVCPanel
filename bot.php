<?php

//--------------------------------------------------------------------------------
// By KiMia
// Github AVCPANEL 
// Adminstration @amirmasoud_rsli
//--------------------------------------------------------------------------------
//Unauthorized copying is strictly prohibited.
//Editing or redistributing this content without explicit permission from the Avcpanel family is forbidden.
//کپی رایت بدون اطلاع مساوی کس مادرت !!
//ادیت بدون ذکر اجازه داخل خانواده Avcpanel مساوی کس مادرت!!

require_once __DIR__ . '/setting/config.php';
require_once __DIR__ . '/setting/functions.php';
require_once __DIR__ . '/setting/jdf.php';

$update = json_decode(file_get_contents("php://input"), TRUE);
$conn = getDbConnection();

if (isset($update["message"])) {
    $chat_id = $update["message"]["chat"]["id"];
    $message_id = $update["message"]["message_id"];
    $text = $update["message"]["text"];
    $from_id = $update["message"]["from"]["id"];
    $first_name = $update["message"]["from"]["first_name"];
    $last_name = isset($update["message"]["from"]["last_name"]) ? $update["message"]["from"]["last_name"] : null;
    $username = isset($update["message"]["from"]["username"]) ? $update["message"]["from"]["username"] : null;
} elseif (isset($update["callback_query"])) {
    $chat_id = $update["callback_query"]["message"]["chat"]["id"];
    $message_id = $update["callback_query"]["message"]["message_id"];
    $data = $update["callback_query"]["data"];
    $from_id = $update["callback_query"]["from"]["id"];
    //answerCallbackQuery($update["callback_query"]["id"]);
} else {
    exit();
}

$user_step = null;
$temp_data = null;

if (isset($from_id)) {
    try {
        $stmt = $conn->prepare("SELECT step, temp_data FROM users WHERE user_id = :user_id");
        $stmt->bindParam(':user_id', $from_id);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $user_step = $result['step'];
            if (!empty($result['temp_data'])) {
                $temp_data = json_decode($result['temp_data'], true);
            }
        }
    } catch (PDOException $e) {
        error_log("Database error fetching user step for user_id {$from_id}: " . $e->getMessage());
    }
}

if (strpos($user_step, 'awaiting_receipt_') === 0 || strpos($user_step, 'awaiting_charge_receipt_') === 0) {
    if (!isset($update["message"]["photo"])) { 
        sendMessage($chat_id, "❌ لطفا فقط یک عکس به عنوان رسید ارسال کنید.");
        exit(); 
    }
    
    $is_charge = strpos($user_step, 'awaiting_charge_receipt_') === 0;
    $transaction_id = str_replace(['awaiting_receipt_', 'awaiting_charge_receipt_'], '', $user_step);
    $photo_id = $update["message"]["photo"][count($update["message"]["photo"]) - 1]['file_id'];
    
    $stmt = $conn->prepare("UPDATE transactions SET receipt_file_id = :receipt, status = 'awaiting_confirmation' WHERE id = :id");
    $stmt->execute([':receipt' => $photo_id, ':id' => $transaction_id]);
    
    setUserStep($from_id, null, null);
    sendMessage($chat_id, "✅ رسید شما دریافت شد. پس از تایید توسط مدیریت، نتیجه به شما اطلاع داده خواهد شد.");

    $admin_message = '';
    if ($is_charge) {
        $stmt = $conn->prepare("SELECT t.*, u.first_name, u.user_id FROM transactions t JOIN users u ON t.user_id = u.user_id WHERE t.id = :id");
        $stmt->execute([':id' => $transaction_id]);
        $tx_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $admin_message = "<b>رسید شارژ حساب برای تایید:</b>\n";
        if (isset($tx_info['tracking_code'])) {
            $admin_message .= "<b>کد پیگیری:</b> <code>{$tx_info['tracking_code']}</code>\n\n";
        }
        $admin_message .= "کاربر: <a href='tg://user?id={$tx_info['user_id']}'>{$tx_info['first_name']}</a>\n";
        $admin_message .= "مبلغ شارژ: " . number_format($tx_info['price']) . " تومان\n";
    } else { 
        $stmt = $conn->prepare("SELECT t.*, p.name as plan_name, u.first_name, u.user_id FROM transactions t JOIN plans p ON t.plan_id = p.id JOIN users u ON t.user_id = u.user_id WHERE t.id = :id");
        $stmt->execute([':id' => $transaction_id]);
        $tx_info = $stmt->fetch(PDO::FETCH_ASSOC);

        $admin_message = "<b>رسید خرید سرویس برای تایید:</b>\n";
        if (isset($tx_info['tracking_code'])) {
            $admin_message .= "<b>کد پیگیری:</b> <code>{$tx_info['tracking_code']}</code>\n\n";
        }
        $admin_message .= "کاربر: <a href='tg://user?id={$tx_info['user_id']}'>{$tx_info['first_name']}</a>\n";
        $admin_message .= "پلن: {$tx_info['plan_name']}\n";
        $admin_message .= "مبلغ: " . number_format($tx_info['price']) . " تومان\n";
    }
    
    $keyboard = [[
        ['text' => '✅ تایید', 'callback_data' => 'confirm_tx_' . $transaction_id],
        ['text' => '❌ رد کردن', 'callback_data' => 'reject_tx_' . $transaction_id]
    ]];
    $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
    
    $admins_stmt = $conn->query("SELECT user_id FROM admins");
    $db_admin_ids = $admins_stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($db_admin_ids as $admin_id) {
        if (hasPermission($conn, $admin_id, 'manage_users')) {
            apiRequest('sendPhoto', [
                'chat_id' => $admin_id,
                'photo' => $photo_id,
                'caption' => $admin_message,
                'parse_mode' => 'HTML',
                'reply_markup' => $reply_markup
            ]);
        }
    }
    exit();
}

    if ($user_step == 'awaiting_support_ticket') {
        $stmt = $conn->prepare("INSERT INTO tickets (user_id, title) VALUES (?, ?)");
        $stmt->execute([$from_id, $text]);
        $ticket_id = $conn->lastInsertId();

        $reply_stmt = $conn->prepare("INSERT INTO ticket_replies (ticket_id, sender_id, message_text) VALUES (?, ?, ?)");
        $reply_stmt->execute([$ticket_id, $from_id, $text]);

        setUserStep($from_id, null, null);
        sendMessage($chat_id, "✅ تیکت شما با شماره پیگیری #{$ticket_id} با موفقیت ثبت شد. پشتیبانی به زودی پاسخ شما را ارسال خواهد کرد.");

        $admin_message = "<b>تیکت پشتیبانی جدید</b>\n\n";
        $admin_message .= "از طرف: <a href='tg://user?id={$from_id}'>{$first_name}</a>\n";
        $admin_message .= "شماره تیکت: #{$ticket_id}\n\n";
        $admin_message .= "<b>متن پیام:</b>\n" . htmlspecialchars($text);

        $keyboard = [[['text' => "پاسخ به تیکت #{$ticket_id}", 'callback_data' => 'reply_to_ticket_' . $ticket_id]]];
        $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
        
        $admins_stmt = $conn->query("SELECT user_id FROM admins");
        $db_admin_ids = $admins_stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($db_admin_ids as $admin_id) {
            if (hasPermission($conn, $admin_id, 'support')) {
                sendMessage($admin_id, $admin_message, $reply_markup);
            }
        }
        exit();
    }

    if (strpos($user_step, 'user_replying_to_') === 0) {
        $ticket_id = str_replace('user_replying_to_', '', $user_step);

        $stmt = $conn->prepare("INSERT INTO ticket_replies (ticket_id, sender_id, message_text) VALUES (?, ?, ?)");
        $stmt->execute([$ticket_id, $from_id, $text]);

        $conn->prepare("UPDATE tickets SET status = 'answered_user' WHERE id = ?")->execute([$ticket_id]);
        
        setUserStep($from_id, null, null);
        sendMessage($chat_id, "✅ پاسخ شما برای تیکت #{$ticket_id} با موفقیت ثبت شد.");

        $notification = "⚪️ <b>پاسخ جدید از طرف کاربر</b>\n\n";
        $notification .= "برای تیکت شماره #{$ticket_id} یک پاسخ جدید از طرف <a href='tg://user?id={$from_id}'>{$first_name}</a> دریافت شد.";
        $keyboard = [[['text' => "مشاهده و پاسخ به تیکت #{$ticket_id}", 'callback_data' => 'reply_to_ticket_' . $ticket_id]]];
        $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
        
        $admins_stmt = $conn->query("SELECT user_id FROM admins");
        $db_admin_ids = $admins_stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($db_admin_ids as $admin_id) {
            if (hasPermission($conn, $admin_id, 'support')) {
                sendMessage($admin_id, $notification, $reply_markup);
            }
        }
        exit();
    }

    if (strpos($user_step, 'admin_replying_to_') === 0) {
        if (!hasPermission($conn, $from_id, 'support')) { exit(); }

        $ticket_id = str_replace('admin_replying_to_', '', $user_step);

        $stmt = $conn->prepare("INSERT INTO ticket_replies (ticket_id, sender_id, message_text) VALUES (?, ?, ?)");
        $stmt->execute([$ticket_id, $from_id, $text]);

        $conn->prepare("UPDATE tickets SET status = 'answered_admin' WHERE id = ?")->execute([$ticket_id]);
        
        setUserStep($from_id, null, null);
        sendMessage($chat_id, "✅ پاسخ شما برای تیکت #{$ticket_id} با موفقیت ثبت شد.");

        $ticket_user_id = $conn->query("SELECT user_id FROM tickets WHERE id = $ticket_id")->fetchColumn();
        $notification = "🟢 <b>پاسخ جدید از طرف پشتیبانی</b>\n\n";
        $notification .= "برای تیکت شماره #{$ticket_id} یک پاسخ جدید دریافت کردید.\n\n";
        $notification .= "<b>متن پاسخ:</b>\n" . htmlspecialchars($text);
        $keyboard = [[['text' => "مشاهده و پاسخ به تیکت #{$ticket_id}", 'callback_data' => 'view_ticket_' . $ticket_id]]];
        sendMessage($ticket_user_id, $notification, json_encode(['inline_keyboard' => $keyboard]));
        
        exit();
    }


    if ($user_step == 'awaiting_charge_amount') {
        if (!is_numeric($text) || $text < 1000) { // حداقل مبلغ شارژ 1000 تومان
            sendMessage($chat_id, "❌ لطفا مبلغ را به صورت عدد و حداقل 1,000 تومان وارد کنید.");
        } else {
            $amount = (int)$text;
            
            $tracking_code = 'CH-' . strtoupper(bin2hex(random_bytes(3)));
            
            $stmt = $conn->prepare("INSERT INTO transactions (user_id, plan_id, price, tracking_code, status) VALUES (?, 0, ?, ?, 'pending')");
            $stmt->execute([$from_id, $amount, $tracking_code]);
            $transaction_id = $conn->lastInsertId();
            
            $card_info = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'bank_card_info'")->fetchColumn();

            setUserStep($from_id, 'awaiting_charge_receipt_' . $transaction_id);
            $message = "لطفا مبلغ <b>" . number_format($amount) . " تومان</b> را به کارت زیر واریز کرده و سپس از رسید خود یک عکس واضح ارسال کنید:\n\n<code>{$card_info}</code>";
            sendMessage($chat_id, $message);
        }
        exit();
    }

if (isset($update['message']['text']) && $update['message']['text'] == '/cancel') {
    if (isAdmin($conn, $from_id)) {
        if ($user_step) {
            setUserStep($from_id, null, null);
            sendMessage($chat_id, "✅ عملیات فعلی با موفقیت لغو شد.");
        } else {
            sendMessage($chat_id, "شما در حال انجام هیچ عملیاتی نیستید.");
        }
    }
    exit();
}



//استارت//
if (strpos($text, '/start') === 0) {
    handleStartCommand($conn, $from_id, $chat_id, $text, $first_name, $last_name, $username, $user_step);
}
   
elseif ($text == 'خرید سرویس') {
    $stmt = $conn->query("SELECT * FROM categories ORDER BY id ASC");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($categories) > 0) {
        $keyboard = [];
        $category_chunks = array_chunk($categories, 2);
        foreach ($category_chunks as $chunk) {
            $row = [];
            foreach ($chunk as $category) {
                $row[] = ['text' => "{$category['emoji']} {$category['name']}", 'callback_data' => 'show_plans_in_cat_' . $category['id']];
            }
            $keyboard[] = $row;
        }

        $keyboard[] = [['text' => '❌ بستن منو', 'callback_data' => 'close_message']];

        $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
        sendMessage($chat_id, "🗂️ لطفا دسته‌بندی سرویس مورد نظر خود را انتخاب کنید:", $reply_markup);
    } else {
        sendMessage($chat_id, "😔 متاسفانه در حال حاضر سرویس فعالی برای فروش وجود ندارد.");
    }
}

        elseif (strpos($data, 'show_plans_in_cat_') === 0) {
            $category_id = str_replace('show_plans_in_cat_', '', $data);
            
            $category_stmt = $conn->prepare("SELECT name FROM categories WHERE id = :id");
            $category_stmt->bindParam(':id', $category_id);
            $category_stmt->execute();
            $category_name = $category_stmt->fetchColumn();

            $stmt = $conn->prepare("
                SELECT p.* 
                FROM plans p 
                JOIN panels pn ON p.panel_id = pn.id 
                WHERE p.category_id = :cat_id AND p.status = 1 
                ORDER BY p.price ASC
            ");
            $stmt->bindParam(':cat_id', $category_id);
            $stmt->execute();
            $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $all_plans_in_cat_stmt = $conn->prepare("SELECT id, panel_id FROM plans WHERE category_id = :cat_id");
            $all_plans_in_cat_stmt->execute([':cat_id' => $category_id]);
            $all_plans_in_cat = $all_plans_in_cat_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $existing_panel_ids = $conn->query("SELECT id FROM panels")->fetchAll(PDO::FETCH_COLUMN);

            $deleted_orphans = false;
            foreach ($all_plans_in_cat as $plan_check) {
                if (!in_array($plan_check['panel_id'], $existing_panel_ids)) {
                    $conn->prepare("DELETE FROM plans WHERE id = ?")->execute([$plan_check['id']]);
                    $deleted_orphans = true;
                }
            }


            if ($deleted_orphans) {
                $stmt->execute();
                $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }


            if (count($plans) > 0) {
                $message = "<b>لیست سرویس‌های دسته‌بندی '{$category_name}':</b>\n\n";

                foreach ($plans as $plan) {
                    $traffic_text = $plan['traffic'] == 0 ? 'نامحدود' : $plan['traffic'] . ' گیگابایت';
                    $description = str_replace(
                        ['{days}', '{traffic}', '{price}'],
                        [$plan['days'], $traffic_text, number_format($plan['price'])],
                        $plan['description']
                    );
                    $message .= "➖➖➖➖➖➖➖➖\n" . $description . "\n";
                }
                $message .= "\nلطفا سرویس مورد نظر خود را از دکمه‌های زیر انتخاب کنید:";

                $keyboard = [];
                foreach ($plans as $plan) {
                    $button_text = "🛒 خرید " . $plan['name'];
                    $keyboard[] = [['text' => $button_text, 'callback_data' => 'buy_plan_' . $plan['id']]];
                }
                
                $keyboard[] = [['text' => '🔙 بازگشت به دسته‌بندی‌ها', 'callback_data' => 'back_to_categories']];
                $keyboard[] = [['text' => '❌ بستن منو', 'callback_data' => 'close_message']];
                
                $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
                editMessageText($chat_id, $message_id, $message, $reply_markup);

            } else {
                answerCallbackQuery($update["callback_query"]["id"], "متاسفانه در این دسته‌بندی پلن فعالی وجود ندارد.", true);
            }
        }
 elseif ($data == 'back_to_categories') {

            $stmt = $conn->query("SELECT * FROM categories ORDER BY id ASC");
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $keyboard = [];
            $category_chunks = array_chunk($categories, 2);
            foreach ($category_chunks as $chunk) {
                $row = [];
                foreach ($chunk as $category) {
                    $row[] = ['text' => "{$category['emoji']} {$category['name']}", 'callback_data' => 'show_plans_in_cat_' . $category['id']];
                }
                $keyboard[] = $row;
            }

                $keyboard[] = [['text' => '❌ بستن منو', 'callback_data' => 'close_message']];

            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            editMessageText($chat_id, $message_id, "🗂️ لطفا دسته‌بندی سرویس مورد نظر خود را انتخاب کنید:", $reply_markup);
}


elseif ($text == '🧪 دریافت اکانت تست 🧪') {
    $stmt = $conn->query("SELECT * FROM settings");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    if ($settings['test_account_status'] != 1 || empty($settings['test_account_panel_id'])) {
        sendMessage($chat_id, "😔 متاسفانه دریافت اکانت تست در حال حاضر امکان‌پذیر نیست.");
        exit();
    }

    $stmt = $conn->prepare("SELECT has_received_test FROM users WHERE user_id = :user_id");
    $stmt->bindParam(':user_id', $from_id);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && $user['has_received_test'] == 1) {
        sendMessage($chat_id, "شما قبلاً یک بار اکانت تست خود را دریافت کرده‌اید و امکان دریافت مجدد وجود ندارد.");
        exit();
    }

    sendMessage($chat_id, "⏳ لطفا چند لحظه صبر کنید، در حال آماده‌سازی اکانت تست شما...");

    $panel_stmt = $conn->prepare("SELECT url, api_token FROM panels WHERE id = :id");
    $panel_stmt->bindParam(':id', $settings['test_account_panel_id']);
    $panel_stmt->execute();
    $panel_info = $panel_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$panel_info) {
        sendMessage($chat_id, "❌ خطای داخلی: سرور تنظیم شده برای اکانت تست یافت نشد.");
        exit();
    }

    $vpn_username = $chat_id . 'test';
    $api_result = createVpnUser(
        $panel_info['url'],
        $panel_info['api_token'],
        $vpn_username,
        $settings['test_account_traffic'],
        $settings['test_account_days']
    );


    if ($api_result['success']) {
        $update_user = $conn->prepare("UPDATE users SET has_received_test = 1 WHERE user_id = :user_id");
        $update_user->bindParam(':user_id', $from_id);
        $update_user->execute();


        $test_plan_id = 0;
        $panel_id_for_test = $settings['test_account_panel_id'];

        $insert_service_stmt = $conn->prepare(
            "INSERT INTO services (user_id, plan_id, panel_id, vpn_username) VALUES (:user_id, :plan_id, :panel_id, :vpn_username)"
        );
        $insert_service_stmt->bindParam(':user_id', $from_id);
        $insert_service_stmt->bindParam(':plan_id', $test_plan_id);
        $insert_service_stmt->bindParam(':panel_id', $panel_id_for_test);
        $insert_service_stmt->bindParam(':vpn_username', $vpn_username);
        $insert_service_stmt->execute();

        sendMessage($chat_id, "✅ اکانت تست شما با موفقیت ساخته شد و به بخش 'سرویس های من' اضافه گردید.");
    } else {
        sendMessage($chat_id, "❌ متاسفانه در حال حاضر مشکلی در ساخت خودکار اکانت تست پیش آمده. لطفا بعداً تلاش کنید.");
        error_log("Test Account API Error for user {$from_id}: " . ($api_result['error'] ?? 'Unknown Error'));
    }
}

    
//حساب من//
elseif ($text == 'حساب من') {
    $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = :user_id");
    $stmt->bindParam(':user_id', $from_id);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("SELECT COUNT(*) FROM services WHERE user_id = :user_id");
    $stmt->bindParam(':user_id', $from_id);
    $stmt->execute();
    $service_count = $stmt->fetchColumn();

    $message = "<b>اطلاعات حساب شما:</b>\n\n";
    $message .= "👤 <b>نام:</b> " . htmlspecialchars($user['first_name']) . "\n";
    $message .= "🆔 <b>آیدی عددی:</b> <code>{$user['user_id']}</code>\n";
    $message .= "💰 <b>موجودی حساب:</b> " . number_format($user['balance']) . " تومان\n";
    $message .= "🌐 <b>تعداد سرویس‌های فعال:</b> {$service_count} عدد\n\n";
    $message .= "🔗 <b>لینک دعوت شما:</b>\n<code>https://t.me/" . BOT_USERNAME . "?start={$from_id}</code>";

    $keyboard = [[['text' => '💰 افزایش موجودی', 'callback_data' => 'charge_wallet_menu']]];
    $reply_markup = json_encode(['inline_keyboard' => $keyboard]);

    sendMessage($chat_id, $message, $reply_markup);
}

        elseif ($data == 'charge_wallet_menu') {
            $stmt = $conn->query("SELECT * FROM settings");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $keyboard = [];
            if ($settings['charge_card_status'] == 1) {
                $keyboard[] = [['text' => '💳 کارت به کارت', 'callback_data' => 'charge_by_card']];
                $keyboard[] = [['text' => '❌ منصرف شدن', 'callback_data' => 'close_message']];
            }
            
            if (empty($keyboard)) {
                answerCallbackQuery($update["callback_query"]["id"], "متاسفانه در حال حاضر روشی برای شارژ حساب فعال نیست.", true);
            } else {
                $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
                editMessageText($chat_id, $message_id, "لطفا روش مورد نظر برای افزایش موجودی را انتخاب کنید:", $reply_markup);
            }
        }

        elseif ($data == 'charge_by_card') {
            setUserStep($from_id, 'awaiting_charge_amount');
            editMessageText($chat_id, $message_id, "لطفا مبلغی را که می‌خواهید حسابتان را شارژ کنید، به تومان و به صورت عدد ارسال کنید:");
        }

    






elseif ($text == 'پشتیبانی') {
    $keyboard = [
        [['text' => '➕ ارسال تیکت جدید', 'callback_data' => 'new_ticket']],
        [['text' => '📋 مشاهده تیکت‌های من', 'callback_data' => 'my_tickets']],
        [['text' => '❌ بستن بخش', 'callback_data' => 'close_message']]
    ];
    $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
    sendMessage($chat_id, "به بخش پشتیبانی خوش آمدید. لطفا یکی از گزینه‌های زیر را انتخاب کنید:", $reply_markup);
}

        elseif ($data == 'new_ticket') {
            setUserStep($from_id, 'awaiting_support_ticket');
            editMessageText($chat_id, $message_id, "لطفا مشکل یا سوال خود را به صورت یک پیام کامل ارسال کنید. پیام شما به عنوان یک تیکت پشتیبانی ثبت خواهد شد.");
        }

        elseif ($data == 'my_tickets') {
            $stmt = $conn->prepare("SELECT * FROM tickets WHERE user_id = ? ORDER BY last_update DESC");
            $stmt->execute([$from_id]);
            $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($tickets) > 0) {
                $message = "<b>لیست تیکت‌های شما:</b>\n\n";
                $keyboard = [];
                foreach ($tickets as $ticket) {
                    $status_text = '';
                    if($ticket['status'] == 'open' || $ticket['status'] == 'answered_user') $status_text = '⚪️ منتظر پاسخ';
                    elseif($ticket['status'] == 'answered_admin') $status_text = '🟢 پاسخ داده شده';
                    elseif($ticket['status'] == 'closed') $status_text = '⚫️ بسته شده';

                    $message .= "▫️ تیکت شماره #{$ticket['id']} - {$status_text}\n";
                    $keyboard[] = [['text' => "مشاهده تیکت #{$ticket['id']}", 'callback_data' => 'view_ticket_' . $ticket['id']]];
                }
                $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
                editMessageText($chat_id, $message_id, $message, $reply_markup);
            } else {
                editMessageText($chat_id, $message_id, "شما تاکنون هیچ تیکتی ثبت نکرده‌اید.");
            }
        }











 elseif ($text == 'سرویس های من') {
    $stmt = $conn->prepare("SELECT id as service_id, vpn_username FROM services WHERE user_id = :user_id ORDER BY id DESC");
    $stmt->bindParam(':user_id', $from_id);
    $stmt->execute();
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($services) > 0) {
        $keyboard = [];
        foreach ($services as $service) {
            $keyboard[] = [['text' => "🔸 " . $service['vpn_username'], 'callback_data' => 'show_service_details_' . $service['service_id']]];
        }
        $keyboard[] = [['text' => '❌ بستن لیست', 'callback_data' => 'close_message']];
        $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
        sendMessage($chat_id, "برای مشاهده جزئیات، روی سرویس مورد نظر کلیک کنید:", $reply_markup);
    } else {
        sendMessage($chat_id, "شما در حال حاضر هیچ سرویس فعالی ندارید.");
    }
}
        elseif (strpos($data, 'show_service_details_') === 0) {
            $service_id = str_replace('show_service_details_', '', $data);

            editMessageText($chat_id, $message_id, "⏳ در حال بررسی اطلاعات سرویس...");


            $stmt = $conn->prepare("
                SELECT s.vpn_username, s.panel_id, p.url as panel_url, p.api_token 
                FROM services s 
                LEFT JOIN panels p ON s.panel_id = p.id 
                WHERE s.id = :service_id AND s.user_id = :user_id
            ");
            $stmt->bindParam(':service_id', $service_id);
            $stmt->bindParam(':user_id', $from_id);
            $stmt->execute();
            $service_info = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$service_info) {
                editMessageText($chat_id, $message_id, "❌ خطای داخلی: این سرویس در لیست شما وجود ندارد.");
                exit();
            }

            if ($service_info['panel_url'] === null) {
                $conn->prepare("DELETE FROM services WHERE id = ?")->execute([$service_id]);

                $user_message = "⚠️ سرویس شما با نام کاربری `{$service_info['vpn_username']}` به دلیل حذف سرور اصلی، از لیست سرویس‌های شما پاک شد.\n\n";
                $user_message .= "لطفاً برای دریافت سرویس جدید یا پیگیری با پشتیبانی در ارتباط باشید.";
                
                editMessageText($chat_id, $message_id, $user_message);
                exit();
            }

            if (empty($service_info['api_token'])) {
                editMessageText($chat_id, $message_id, "❌ خطای داخلی: توکن API برای سرور این سرویس تعریف نشده است.");
                exit();
            }
            
            $details_result = getUserDetails($service_info['panel_url'], $service_info['api_token'], $service_info['vpn_username']);
            $uri_result = getUserUri($service_info['panel_url'], $service_info['api_token'], $service_info['vpn_username']);

            if (!$details_result['success']) {
                $detailed_error = $details_result['error'];
                editMessageText($chat_id, $message_id, "❌ خطایی در دریافت اطلاعات از سرور رخ داد. لطفا با پشتیبانی تماس بگیرید.");
                error_log("API Error (getUserDetails) for user {$from_id} on service {$service_id}: {$detailed_error}");
                exit();
            }
            
            $details = $details_result['data'];
            $uris = $uri_result['data'] ?? [];

            $output = "<b>اطلاعات سرویس:</b> <code>{$service_info['vpn_username']}</code>\n\n";
            
            if (isset($details['status'])) {
                $status_icon = $details['status'] == 'active' ? '✅' : '❌';
                $output .= "<b>وضعیت:</b> {$status_icon} {$details['status']}\n";
            }
            
            $expiration_date_jalali = 'نامحدود';
            $remaining_days_text = '';

            if (isset($details['expiration_days']) && $details['expiration_days'] > 0) {
                $days_left = $details['expiration_days'];
                $expire_timestamp = time() + ($days_left * 86400);
                $expiration_date_jalali = jdate('Y/m/d', $expire_timestamp);
                $remaining_days_text = "<b>روز اتمام:</b> " . $days_left . " روز دیگر\n";
            } elseif (isset($details['expiration_days']) && $details['expiration_days'] <= 0) {
                $expiration_date_jalali = 'منقضی شده';
                $remaining_days_text = "<b>روز اتمام:</b> <span style='color:red;'>منقضی شده</span>\n";
            }
            
            $output .= "<b>تاریخ انقضا:</b> " . $expiration_date_jalali . "\n";
            if (!empty($remaining_days_text)) {
                $output .= $remaining_days_text;
            }
            
            $download_bytes = $details['download_bytes'] ?? 0;
            $upload_bytes = $details['upload_bytes'] ?? 0;
            $total_traffic = $details['max_download_bytes'] ?? $details['traffic_limit'] ?? 0;
            $used_traffic = $download_bytes + $upload_bytes;
            $traffic_line = "<b>حجم مصرفی:</b> " . formatBytes($used_traffic);
            if(isset($details['unlimited_user']) && $details['unlimited_user'] === true){
                 $traffic_line .= " از نامحدود";
            } else if ($total_traffic > 0) {
                $traffic_line .= " از " . formatBytes($total_traffic);
            }
            $output .= $traffic_line . "\n";
            
            $output .= "\n";

            if (!empty($uris['normal_sub'])) {
                $output .= "<b>لینک اشتراک (Subscription):</b>\n<code>{$uris['normal_sub']}</code>\n\n";
            }
            if (!empty($uris['ipv4'])) {
                $output .= "<b>لینک اتصال IPv4:</b>\n<code>{$uris['ipv4']}</code>\n\n";
            }
            if (!empty($uris['ipv6'])) {
                $output .= "<b>لینک اتصال IPv6:</b>\n<code>{$uris['ipv6']}</code>\n\n";
            }

            $keyboard = [
                [['text' => '🔄 تمدید سرویس', 'callback_data' => 'renew_service_' . $service_id]],
                [['text' => '🔙 بازگشت به لیست سرویس‌ها', 'callback_data' => 'back_to_services_list']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
                
            editMessageText($chat_id, $message_id, rtrim($output), $reply_markup);
        }

        elseif (strpos($data, 'renew_service_') === 0) {
            $service_id = str_replace('renew_service_', '', $data);
            
            editMessageText($chat_id, $message_id, "⏳ در حال دریافت اطلاعات فعلی سرویس...");

            $stmt = $conn->prepare("SELECT s.vpn_username, s.panel_id, p.url as panel_url, p.api_token FROM services s JOIN panels p ON s.panel_id = p.id WHERE s.id = :service_id AND s.user_id = :user_id");
            $stmt->execute([':service_id' => $service_id, ':user_id' => $from_id]);
            $service_info = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$service_info || empty($service_info['api_token'])) {
                editMessageText($chat_id, $message_id, "❌ خطای داخلی: اطلاعات مورد نیاز برای تمدید این سرویس یافت نشد.");
                exit();
            }

            $details_result = getUserDetails($service_info['panel_url'], $service_info['api_token'], $service_info['vpn_username']);
            if (!$details_result['success']) {
                editMessageText($chat_id, $message_id, "❌ خطایی در ارتباط با سرور برای دریافت اطلاعات فعلی رخ داد.");
                exit();
            }
            $details = $details_result['data'];
            
            $current_days = $details['expiration_days'] ?? 0;
            $current_traffic_bytes = $details['max_download_bytes'] ?? $details['traffic_limit'] ?? 0;
            
            $renewal_data = [
                'vpn_username' => $service_info['vpn_username'],
                'panel_id' => $service_info['panel_id'],
                'panel_url' => $service_info['panel_url'],
                'api_token' => $service_info['api_token'],
                'current_days' => $current_days < 0 ? 0 : $current_days,
                'current_traffic_bytes' => $current_traffic_bytes
            ];
            setUserStep($from_id, 'selecting_renewal_plan', json_encode($renewal_data));
            
            $plan_stmt = $conn->prepare("SELECT * FROM plans WHERE panel_id = :panel_id AND status = 1");
            $plan_stmt->execute([':panel_id' => $service_info['panel_id']]);
            $plans = $plan_stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($plans) > 0) {
                $keyboard = [];
                foreach ($plans as $plan) {
                    $keyboard[] = [['text' => "{$plan['name']} - " . number_format($plan['price']) . " تومان", 'callback_data' => 'select_renewal_plan_' . $plan['id']]];
                }
                $keyboard[] = [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_services_list']];
                $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
                editMessageText($chat_id, $message_id, "لطفا یکی از پلن‌های زیر را برای تمدید انتخاب کنید:", $reply_markup);
            } else {
                editMessageText($chat_id, $message_id, "هیچ پلن تمدیدی برای این سرور یافت نشد.");
                setUserStep($from_id, null, null);
            }
        }
        
        elseif (strpos($data, 'select_renewal_plan_') === 0 && $user_step == 'selecting_renewal_plan') {
            $plan_id = str_replace('select_renewal_plan_', '', $data);
            

            $keyboard = [
                [['text' => '💰 پرداخت با موجودی', 'callback_data' => 'pay_balance_renewal_' . $plan_id]],
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_services_list']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            editMessageText($chat_id, $message_id, "لطفا روش پرداخت برای تمدید را انتخاب کنید:", $reply_markup);
        }

                elseif (strpos($data, 'pay_balance_renewal_') === 0) {
            $plan_id = str_replace('pay_balance_renewal_', '', $data);
            
            $user_stmt = $conn->prepare("SELECT balance FROM users WHERE user_id = :user_id");
            $user_stmt->execute([':user_id' => $from_id]);
            $user = $user_stmt->fetch(PDO::FETCH_ASSOC);

            $plan_stmt = $conn->prepare("SELECT * FROM plans WHERE id = :id");
            $plan_stmt->execute([':id' => $plan_id]);
            $plan = $plan_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$plan) {
                editMessageText($chat_id, $message_id, "❌ خطای داخلی: پلن تمدید یافت نشد.");
                setUserStep($from_id, null, null);
                exit();
            }

            if ($user['balance'] < $plan['price']) {
                answerCallbackQuery($update["callback_query"]["id"], "موجودی شما برای تمدید کافی نیست.", true);
                exit();
            }
            
            $conn->prepare("UPDATE users SET balance = balance - :price WHERE user_id = :user_id")->execute([':price' => $plan['price'], ':user_id' => $from_id]);
            
            editMessageText($chat_id, $message_id, "⏳ موجودی کسر شد. در حال تمدید سرویس شما...");

            $renewal_info = $temp_data;
            
            $current_days_remaining = (int)($renewal_info['current_days'] ?? 0);
            $plan_days_to_add = (int)($plan['days'] ?? 0);
            $new_total_days = $current_days_remaining + $plan_days_to_add;
            $new_total_traffic_gb = (float)($plan['traffic'] ?? 0);

            $api_result = editVpnUser(
                $renewal_info['panel_url'],
                $renewal_info['api_token'],
                $renewal_info['vpn_username'],
                $new_total_traffic_gb,
                $new_total_days
            );
            
            if ($api_result['success']) {
                $new_expire_timestamp = time() + ($new_total_days * 86400);
                $new_expiration_date_sql = date("Y-m-d H:i:s", $new_expire_timestamp);

                $conn->prepare("UPDATE services SET expiration_date = :exp_date, warning_sent = 0, plan_id = :plan_id WHERE vpn_username = :vpn_username")
                     ->execute([
                         ':exp_date' => $new_expiration_date_sql, 
                         ':plan_id' => $plan_id,
                         ':vpn_username' => $renewal_info['vpn_username']
                     ]);

                sendMessage($chat_id, "✅ سرویس شما با موفقیت تمدید شد.");
            } else {
                $conn->prepare("UPDATE users SET balance = balance + :price WHERE user_id = :user_id")->execute([':price' => $plan['price'], ':user_id' => $from_id]);
                $error_message = "❌ متاسفانه در تمدید سرویس شما خطایی رخ داد. مبلغ به حساب شما بازگردانده شد.\n\n<b>دلیل خطا:</b> " . htmlspecialchars($api_result['error']);
                sendMessage($chat_id, $error_message);
                sendMessage(OWNER_ID, "خطای API هنگام تمدید برای کاربر {$from_id}: " . $api_result['error']);
            }
            
            setUserStep($from_id, null, null);
        }

elseif ($text == 'راهنما') {
    $tutorials = $conn->query("SELECT id, platform_name FROM tutorials ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    if (count($tutorials) > 0) {
        $keyboard = [];
        $chunks = array_chunk($tutorials, 2);
        foreach ($chunks as $chunk) {
            $row = [];
            foreach ($chunk as $tutorial) {
                $row[] = ['text' => "آموزش اتصال در {$tutorial['platform_name']}", 'callback_data' => 'show_tutorial_' . $tutorial['id']];
            }
            $keyboard[] = $row;
        }
        $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
        sendMessage($chat_id, "لطفا پلتفرم مورد نظر خود را برای مشاهده راهنمای اتصال انتخاب کنید:", $reply_markup);
    } else {
        sendMessage($chat_id, "😔 در حال حاضر هیچ راهنمایی در سیستم ثبت نشده است.");
    }
}
        elseif (strpos($data, 'show_tutorial_') === 0) {
            $tutorial_id = str_replace('show_tutorial_', '', $data);
            $stmt = $conn->prepare("SELECT * FROM tutorials WHERE id = ?");
            $stmt->execute([$tutorial_id]);
            $tutorial = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($tutorial) {
                editMessageText($chat_id, $message_id, "⏳ در حال ارسال راهنمای اتصال برای {$tutorial['platform_name']}...");
                
                apiRequest('sendVideo', [
                    'chat_id' => $chat_id,
                    'video' => $tutorial['video_file_id']
                ]);

                apiRequest('sendPhoto', [
                    'chat_id' => $chat_id,
                    'photo' => $tutorial['image_file_id'],
                    'caption' => $tutorial['text_content'],
                    'parse_mode' => 'HTML'
                ]);
                
                $keyboard = [[['text' => "دانلود نرم‌افزار برای {$tutorial['platform_name']}", 'url' => $tutorial['download_link']]]];
                $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
                sendMessage($chat_id, "از لینک زیر می‌توانید نرم‌افزار مورد نیاز را دانلود کنید:", $reply_markup);

            } else {
                answerCallbackQuery($update["callback_query"]["id"], "راهنما یافت نشد.", true);
            }
        }



        elseif ($data == 'check_join') {
            answerCallbackQuery($update["callback_query"]["id"]);

            apiRequest('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $message_id]);

            $cb_first_name = $update["callback_query"]["from"]["first_name"];
            $cb_last_name = isset($update["callback_query"]["from"]["last_name"]) ? $update["callback_query"]["from"]["last_name"] : null;
            $cb_username = isset($update["callback_query"]["from"]["username"]) ? $update["callback_query"]["from"]["username"] : null;
            
            handleStartCommand($conn, $from_id, $chat_id, '/start', $cb_first_name, $cb_last_name, $cb_username, $user_step);
        }

        elseif ($data == 'close_message') {
            apiRequest('deleteMessage', [
                'chat_id' => $chat_id,
                'message_id' => $message_id
            ]);
        }

        elseif ($data == 'back_to_services_list') {
            $stmt = $conn->prepare("SELECT id as service_id, vpn_username FROM services WHERE user_id = :user_id ORDER BY id DESC");
            $stmt->bindParam(':user_id', $from_id);
            $stmt->execute();
            $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $keyboard = [];
            foreach ($services as $service) {
                $keyboard[] = [['text' => "🔸 " . $service['vpn_username'], 'callback_data' => 'show_service_details_' . $service['service_id']]];
            }
            $keyboard[] = [['text' => '❌ بستن لیست', 'callback_data' => 'close_message']];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            
            editMessageText($chat_id, $message_id, "برای مشاهده جزئیات، روی سرویس مورد نظر کلیک کنید:", $reply_markup);
        }

 elseif (strpos($data, 'buy_plan_') === 0) {
            $plan_id = str_replace('buy_plan_', '', $data);
            
            $stmt = $conn->query("SELECT * FROM settings");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $keyboard = [];
            if ($settings['balance_payment_status'] == 1) {
                $keyboard[] = [['text' => '💰 پرداخت با موجودی', 'callback_data' => 'pay_balance_' . $plan_id]];
            }
            if ($settings['card_payment_status'] == 1) {
                $keyboard[] = [['text' => '💳 کارت به کارت', 'callback_data' => 'pay_card_' . $plan_id]];
            }

            if (empty($keyboard)) {
                answerCallbackQuery($update["callback_query"]["id"], "متاسفانه در حال حاضر هیچ روش پرداختی فعال نیست.", true);
            } else {
                $keyboard[] = [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_categories']];
                $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
                editMessageText($chat_id, $message_id, "لطفا روش پرداخت خود را انتخاب کنید:", $reply_markup);
            }
        }

        elseif (strpos($data, 'pay_balance_') === 0) {
            $plan_id = str_replace('pay_balance_', '', $data);

            $user_stmt = $conn->prepare("SELECT balance FROM users WHERE user_id = :user_id");
            $user_stmt->bindParam(':user_id', $from_id);
            $user_stmt->execute();
            $user = $user_stmt->fetch(PDO::FETCH_ASSOC);

            $plan_stmt = $conn->prepare("SELECT price FROM plans WHERE id = :id");
            $plan_stmt->bindParam(':id', $plan_id);
            $plan_stmt->execute();
            $plan = $plan_stmt->fetch(PDO::FETCH_ASSOC);

            if ($user['balance'] < $plan['price']) {
                answerCallbackQuery($update["callback_query"]["id"], "موجودی شما برای خرید این سرویس کافی نیست.", true);
                exit();
            }

            $update_balance_stmt = $conn->prepare("UPDATE users SET balance = balance - :price WHERE user_id = :user_id");
            $update_balance_stmt->bindParam(':price', $plan['price']);
            $update_balance_stmt->bindParam(':user_id', $from_id);
            $update_balance_stmt->execute();
            
            editMessageText($chat_id, $message_id, "⏳ موجودی کسر شد. در حال ساخت سرویس شما...");

            $result = finalizePurchaseAndCreateService($conn, $from_id, $plan_id);

            if ($result['success']) {
                $success_message = "✅ سرویس شما با موفقیت ساخته شد و به لیست سرویس‌های شما اضافه گردید.";
                sendMessage($chat_id, $success_message);
            } else {
                $update_balance_stmt->execute();
                $error_message = "❌ متاسفانه در ساخت سرویس شما خطایی رخ داد. مبلغ به حساب شما بازگردانده شد.\n\nدلیل خطا: " . $result['error'];
                sendMessage($chat_id, $error_message);
                sendMessage(OWNER_ID, "خطای API هنگام خرید با موجودی برای کاربر {$from_id}: " . $result['error']);
            }
        }
        
        elseif (strpos($data, 'pay_card_') === 0) {
            $plan_id = str_replace('pay_card_', '', $data);
            
            $stmt = $conn->prepare("SELECT price FROM plans WHERE id = :id");
            $stmt->bindParam(':id', $plan_id);
            $stmt->execute();
            $plan = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$plan) {
                editMessageText($chat_id, $message_id, "❌ خطای داخلی: پلن مورد نظر یافت نشد.");
                exit();
            }

            $tracking_code = 'TX-' . strtoupper(bin2hex(random_bytes(3)));

            $stmt = $conn->prepare("INSERT INTO transactions (user_id, plan_id, price, tracking_code, status) VALUES (:user_id, :plan_id, :price, :code, 'pending')");
            $stmt->execute([
                ':user_id' => $from_id,
                ':plan_id' => $plan_id,
                ':price' => $plan['price'],
                ':code' => $tracking_code
            ]);
            $transaction_id = $conn->lastInsertId();

            $card_info = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'bank_card_info'")->fetchColumn();

            setUserStep($from_id, 'awaiting_receipt_' . $transaction_id);
            $message = "لطفا مبلغ <b>" . number_format($plan['price']) . " تومان</b> را به کارت زیر واریز کرده و سپس از رسید خود یک عکس واضح ارسال کنید:\n\n<code>{$card_info}</code>";
            editMessageText($chat_id, $message_id, $message);
        }

        elseif (strpos($data, 'view_ticket_') === 0) {
            $ticket_id = str_replace('view_ticket_', '', $data);
            
            $ticket_stmt = $conn->prepare("SELECT * FROM tickets WHERE id = ?");
            $ticket_stmt->execute([$ticket_id]);
            $ticket = $ticket_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ticket || $ticket['user_id'] != $from_id) {
                answerCallbackQuery($update["callback_query"]["id"], "شما به این تیکت دسترسی ندارید.", true);
                exit();
            }

            $replies_stmt = $conn->prepare("SELECT r.*, u.first_name FROM ticket_replies r JOIN users u ON r.sender_id = u.user_id WHERE r.ticket_id = ? ORDER BY r.sent_date ASC");
            $replies_stmt->execute([$ticket_id]);
            $replies = $replies_stmt->fetchAll(PDO::FETCH_ASSOC);

            $message = "<b>مکالمات تیکت شماره #{$ticket_id}</b>\n\n";
            foreach ($replies as $reply) {
                $sender_name = isAdmin($conn, $reply['sender_id']) ? "پشتیبانی" : "شما"; // نام فرستنده برای کاربر
                $message .= "<b>{$sender_name}:</b>\n" . htmlspecialchars($reply['message_text']) . "\n➖➖➖➖➖➖\n";
            }

            $keyboard = [];
            if ($ticket['status'] != 'closed') {
                $keyboard[] = [['text' => '✍️ ارسال پاسخ', 'callback_data' => 'user_send_reply_to_' . $ticket_id]];
            } else {
                 $message .= "\n<b>این تیکت توسط پشتیبانی بسته شده است.</b>";
            }
            $keyboard[] = [['text' => '🔙 بازگشت به لیست تیکت‌ها', 'callback_data' => 'my_tickets']];
            
            editMessageText($chat_id, $message_id, $message, json_encode(['inline_keyboard' => $keyboard]));
        }

        elseif (strpos($data, 'user_send_reply_to_') === 0) {
            $ticket_id = str_replace('user_send_reply_to_', '', $data);
            
            $ticket_user_id = $conn->query("SELECT user_id FROM tickets WHERE id = $ticket_id")->fetchColumn();
            if ($ticket_user_id != $from_id) {
                answerCallbackQuery($update["callback_query"]["id"], "خطا: این تیکت متعلق به شما نیست.", true);
                exit();
            }

            setUserStep($from_id, 'user_replying_to_' . $ticket_id);
            editMessageText($chat_id, $message_id, "لطفا پاسخ خود را برای تیکت #{$ticket_id} ارسال کنید:");
        }



//پنل//
if (isAdmin($conn, $from_id)) {

    if ($text == '/cancel') {
        setUserStep($from_id, null, null);
        sendMessage($chat_id, "✅ عملیات فعلی با موفقیت لغو شد.");
        exit();
    }

    if ($user_step == 'add_panel_url') {
        $stmt = $conn->prepare("SELECT id FROM panels WHERE url = :url");
        $stmt->bindParam(':url', $text);
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            setUserStep($from_id, null, null);
            sendMessage($chat_id, "❌ این لینک پنل قبلاً در سیستم ثبت شده است. عملیات لغو شد.");
        } else {
            setUserStep($from_id, 'add_panel_name', json_encode(['url' => $text]));
            sendMessage($chat_id, "✅ لینک ثبت شد.\n\n🖊️ حالا یک **نام منحصر به فرد** برای این پنل ارسال کنید:");
        }
        exit();
    }
    if ($user_step == 'add_panel_name') {
        $stmt = $conn->prepare("SELECT id FROM panels WHERE name = :name");
        $stmt->bindParam(':name', $text);
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            setUserStep($from_id, null, null);
            sendMessage($chat_id, "❌ این نام قبلاً انتخاب شده است. عملیات لغو شد.");
        } else {
            $temp_data['name'] = $text;
            setUserStep($from_id, 'add_panel_emoji', json_encode($temp_data));
            sendMessage($chat_id, "✅ نام ثبت شد.\n\n🏞️ حالا یک **ایموجی** برای این پنل ارسال کنید:");
        }
        exit();
    }
    if ($user_step == 'add_panel_emoji') {
        $emoji = trim($text); 

        $regex = '/[a-zA-Z0-9]/';

        if (!empty($emoji) && !preg_match($regex, $emoji)) {
            $temp_data['sticker'] = $emoji;
            setUserStep($from_id, 'add_panel_username', json_encode($temp_data));
            sendMessage($chat_id, "✅ ایموجی ثبت شد.\n\n👨‍💻 حالا نام کاربری پنل را ارسال کنید:");
        } else {
            sendMessage($chat_id, "❌ خطا: لطفا فقط ایموجی ارسال کنید (بدون متن یا عدد). برای لغو از /cancel استفاده نمایید.");
        }
        exit();
    }
    if ($user_step == 'add_panel_username') {
        $temp_data['username'] = $text;
        setUserStep($from_id, 'add_panel_password', json_encode($temp_data));
        sendMessage($chat_id, "✅ نام کاربری ثبت شد.\n\n🔑 حالا رمز عبور پنل را ارسال کنید:");
        exit();
    }
    if ($user_step == 'add_panel_password') {
        $url = $temp_data['url'];
        $name = $temp_data['name'];
        $sticker = $temp_data['sticker'];
        $username = $temp_data['username'];
        $plain_password = $text;

        $wait_message = sendMessage($chat_id, "⏳ در حال بررسی اطلاعات و تلاش برای ورود به پنل...");
        $login_result = checkPanelStatus($url, $username, $plain_password);

        if ($login_result['success']) {
            $encrypted_password = encrypt_data($plain_password);
            $session_cookie = $login_result['cookie'];

            $stmt = $conn->prepare("INSERT INTO panels (url, name, username, password, session_cookie, sticker, added_by) VALUES (:url, :name, :username, :password, :session_cookie, :sticker, :added_by)");
            $stmt->bindParam(':url', $url);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':password', $encrypted_password);
            $stmt->bindParam(':session_cookie', $session_cookie);
            $stmt->bindParam(':sticker', $sticker);
            $stmt->bindParam(':added_by', $from_id);
            $stmt->execute();
            
            $panel_id = $conn->lastInsertId();

            setUserStep($from_id, 'add_api_token', json_encode(['panel_id' => $panel_id, 'panel_name' => $name]));
            editMessageText($chat_id, $wait_message['message_id'], "✅ پنل با موفقیت تایید و ثبت شد.\n\n" .
                "**(مرحله نهایی)**\nحالا توکن API مربوط به این پنل را ارسال کنید:");

        } else {
            setUserStep($from_id, null, null);
            $error_message = $login_result['error'];
            editMessageText($chat_id, $wait_message['message_id'], "❌ خطا در اتصال به پنل.\n\n<b>دلیل:</b> {$error_message}\n\nعملیات لغو شد.");
        }
        exit();
    }

        if ($user_step == 'add_api_token') {
        $api_token = $text;
        $panel_id = $temp_data['panel_id'];
        $panel_name = $temp_data['panel_name'];
        
        $wait_message = sendMessage($chat_id, "⏳ در حال اعتبارسنجی توکن API...");

        $stmt = $conn->prepare("SELECT url FROM panels WHERE id = :panel_id");
        $stmt->bindParam(':panel_id', $panel_id);
        $stmt->execute();
        $panel = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($panel) {
            $validation_result = validateApiToken($panel['url'], $api_token);

            if ($validation_result['success']) {
                $update_stmt = $conn->prepare("UPDATE panels SET api_token = :api_token WHERE id = :panel_id");
                $update_stmt->bindParam(':api_token', $api_token);
                $update_stmt->bindParam(':panel_id', $panel_id);
                $update_stmt->execute();

$keyboard = [
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
                
                setUserStep($from_id, null, null);
                editMessageText($chat_id, $wait_message['message_id'], "✔️ توکن API برای پنل '<b>{$panel_name}</b>' با موفقیت تایید و ذخیره شد. فرآیند ثبت پنل تکمیل گردید." , $reply_markup);
            } else {
                setUserStep($from_id, null, null);
                $error_message = $validation_result['error'];
                editMessageText($chat_id, $wait_message['message_id'], "❌ توکن API نامعتبر است.\n\n<b>دلیل:</b> {$error_message}\n\nعملیات لغو شد. لطفاً در آینده از طریق پنل مدیریت، توکن صحیح را برای این پنل ثبت کنید." , $reply_markup);
            }
        } else {
            setUserStep($from_id, null, null);
            editMessageText($chat_id, $wait_message['message_id'], "❌ خطای داخلی: پنل یافت نشد. عملیات لغو شد." , $reply_markup);
        }
        exit();
    }


    if ($user_step == 'add_plan_select_panel') {
        $panel_id = str_replace('select_panel_for_plan_', '', $data);
        editMessageText($chat_id, $message_id, "✅ سرور انتخاب شد.\n\n🖊️ حالا یک نام منحصر به فرد برای پلن جدید ارسال کنید:");
        setUserStep($from_id, 'add_plan_name', json_encode(['panel_id' => $panel_id]));
        exit();
    }
    if ($user_step == 'add_plan_name') {
        $stmt = $conn->prepare("SELECT id FROM plans WHERE name = :name");
        $stmt->bindParam(':name', $text);
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            sendMessage($chat_id, "❌ این نام قبلاً برای پلن دیگری استفاده شده. لطفا نام دیگری انتخاب کنید.");
        } else {
            $temp_data['name'] = $text;
            sendMessage($chat_id, "✅ نام ثبت شد.\n\n📊 حالا حجم سرویس را به گیگابایت (GB) ارسال کنید (عدد بین 0 تا 10000. 0 برای نامحدود):");
            setUserStep($from_id, 'add_plan_traffic', json_encode($temp_data));
        }
        exit();
    }
    if ($user_step == 'add_plan_traffic') {
        if (!is_numeric($text) || $text < 0 || $text > 10000) {
            sendMessage($chat_id, "❌ لطفاً فقط یک عدد صحیح بین 0 و 10000 وارد کنید.");
        } else {
            $temp_data['traffic'] = $text;
            sendMessage($chat_id, "✅ حجم ثبت شد.\n\n💰 حالا قیمت پلن را به تومان (فقط عدد) ارسال کنید:");
            setUserStep($from_id, 'add_plan_price', json_encode($temp_data));
        }
        exit();
    }
    if ($user_step == 'add_plan_price') {
        if (!is_numeric($text) || $text < 0) {
            sendMessage($chat_id, "❌ لطفاً فقط عدد صحیح وارد کنید.");
        } else {
            $temp_data['price'] = $text;
            sendMessage($chat_id, "✅ قیمت ثبت شد.\n\n🗓️ حالا مدت زمان اعتبار پلن را به روز (فقط عدد) ارسال کنید:");
            setUserStep($from_id, 'add_plan_days', json_encode($temp_data));
        }
        exit();
    }
    if ($user_step == 'add_plan_days') {
        if (!is_numeric($text) || $text < 1) {
            sendMessage($chat_id, "❌ لطفاً فقط عدد صحیح و بزرگتر از صفر وارد کنید.");
        } else {
            $temp_data['days'] = $text;

            $stmt = $conn->query("SELECT id, name, emoji FROM categories ORDER BY id DESC");
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($categories) > 0) {
                $keyboard = [];
                foreach ($categories as $category) {
                    $keyboard[] = [['text' => "{$category['emoji']} {$category['name']}", 'callback_data' => 'select_category_for_plan_' . $category['id']]];
                }
                $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
                sendMessage($chat_id, "✅ زمان ثبت شد.\n\n🗂️ حالا این پلن را به کدام دسته‌بندی اضافه می‌کنید؟", $reply_markup);
                setUserStep($from_id, 'add_plan_select_category', json_encode($temp_data));
            } else {
                sendMessage($chat_id, "❌ هیچ دسته‌بندی‌ای یافت نشد. ابتدا از منوی مدیریت، یک دسته‌بندی بسازید. عملیات لغو شد.");
                setUserStep($from_id, null, null);
            }
        }
        exit();
    }


    if ($user_step == 'get_user_username') {
        $username = trim($text);
        $panel_id = $temp_data['panel_id'];

        $wait_message = sendMessage($chat_id, "⏳ در حال دریافت اطلاعات کاربر `{$username}`...");

        $stmt = $conn->prepare("SELECT url, api_token FROM panels WHERE id = :panel_id");
        $stmt->bindParam(':panel_id', $panel_id);
        $stmt->execute();
        $panel = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$panel || empty($panel['api_token'])) {
            editMessageText($chat_id, $wait_message['message_id'], "❌ خطا: توکن API برای این پنل ثبت نشده است.");
            setUserStep($from_id, null, null);
            exit();
        }

        $details_result = getUserDetails($panel['url'], $panel['api_token'], $username);
        $uri_result = getUserUri($panel['url'], $panel['api_token'], $username);

        if (!$details_result['success']) {
            editMessageText($chat_id, $wait_message['message_id'], $details_result['error']);
        } else {
            $details = $details_result['data'];
            $uris = $uri_result['data'] ?? [];

            $output = "<b>اطلاعات کاربر:</b> <code>{$username}</code>\n\n";

            if (isset($details['status'])) {
                $status_icon = $details['status'] == 'active' ? '✅' : '❌';
                $output .= "<b>وضعیت:</b> {$status_icon} {$details['status']}\n";
            }
            
            $used_traffic = ($details['download_bytes'] ?? 0) + ($details['upload_bytes'] ?? 0);
            $total_traffic = $details['max_download_bytes'] ?? 0;
            $output .= "<b>حجم مصرفی:</b> " . formatBytes($used_traffic);
            if ($total_traffic > 0) {
                $output .= " / " . formatBytes($total_traffic);
            }
            $output .= "\n";

            if (isset($details['expiration_days'])) {
                $output .= "<b>روزهای باقیمانده:</b> {$details['expiration_days']} روز\n";
            }

            $output .= "\n";

            if (!empty($uris['ipv4'])) {
                $output .= "<b>لینک اتصال IPv4:</b>\n<code>{$uris['ipv4']}</code>\n\n";
            }
            if (!empty($uris['normal_sub'])) {
                $output .= "<b>لینک اشتراک (Subscription):</b>\n<code>{$uris['normal_sub']}</code>\n";
            }

$keyboard = [
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);

            editMessageText($chat_id, $wait_message['message_id'], $output , $reply_markup);
        }

        setUserStep($from_id, null, null);
        exit();
    }

    if ($user_step == 'add_category_name') {
        $stmt = $conn->prepare("SELECT id FROM categories WHERE name = :name");
        $stmt->bindParam(':name', $text);
        $stmt->execute();

$keyboard = [
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);

        if ($stmt->rowCount() > 0) {
            setUserStep($from_id, null, null);
            sendMessage($chat_id, "❌ این نام قبلاً برای یک دسته‌بندی دیگر انتخاب شده است. عملیات لغو شد." , $reply_markup);
        } else {
            setUserStep($from_id, 'add_category_emoji', json_encode(['name' => $text]));
            sendMessage($chat_id, "✅ نام ثبت شد.\n\nحالا یک ایموجی برای این دسته‌بندی ارسال کنید:");
        }
        exit();
    }
    if ($user_step == 'add_category_emoji') {
        $name = $temp_data['name'];
        $emoji = $text;

        $stmt = $conn->prepare("INSERT INTO categories (name, emoji) VALUES (:name, :emoji)");
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':emoji', $emoji);
        $stmt->execute();

$keyboard = [
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);

        setUserStep($from_id, null, null);
        sendMessage($chat_id, "✔️ دسته‌بندی جدید با نام '<b>{$name}</b>' با موفقیت ساخته شد." , $reply_markup);
        exit();
    }
    if ($user_step == 'edit_category_name') {
        $category_id = $temp_data['category_id'];
        $temp_data['name'] = $text;
        setUserStep($from_id, 'edit_category_emoji', json_encode($temp_data));
        sendMessage($chat_id, "✅ نام جدید ثبت شد. حالا ایموجی جدید را ارسال کنید:");
        exit();
    }
    if ($user_step == 'edit_category_emoji') {
        $category_id = $temp_data['category_id'];
        $name = $temp_data['name'];
        $emoji = $text;

        $stmt = $conn->prepare("UPDATE categories SET name = :name, emoji = :emoji WHERE id = :id");
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':emoji', $emoji);
        $stmt->bindParam(':id', $category_id);
        $stmt->execute();

$keyboard = [
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);

        setUserStep($from_id, null, null);
        sendMessage($chat_id, "✔️ دسته‌بندی با موفقیت ویرایش شد." , $reply_markup);
        exit();
    }

    if ($user_step == 'edit_plan_price') {
        if (!is_numeric($text) || $text < 0) {
            sendMessage($chat_id, "❌ لطفا فقط عدد صحیح وارد کنید.");
        } else {
            $plan_id = $temp_data['plan_id'];
            $new_price = $text;
            
            $stmt = $conn->prepare("UPDATE plans SET price = :price WHERE id = :id");
            $stmt->bindParam(':price', $new_price);
            $stmt->bindParam(':id', $plan_id);
            $stmt->execute();

$keyboard = [
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);

            setUserStep($from_id, null, null);
            sendMessage($chat_id, "✔️ قیمت پلن با موفقیت به‌روزرسانی شد." , $reply_markup);
        }
        exit();
    }
    if ($user_step == 'edit_plan_name') {
        $plan_id = $temp_data['plan_id'];
        $new_name = $text;
        
        $stmt = $conn->prepare("UPDATE plans SET name = :name WHERE id = :id");
        $stmt->bindParam(':name', $new_name);
        $stmt->bindParam(':id', $plan_id);
        $stmt->execute();

$keyboard = [
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);

        setUserStep($from_id, null, null);
        sendMessage($chat_id, "✔️ نام پلن با موفقیت به‌روزرسانی شد." , $reply_markup);
        exit();
    }

    if ($user_step == 'set_card_info') {
        $stmt = $conn->prepare("UPDATE settings SET setting_value = :value WHERE setting_key = 'bank_card_info'");
        $stmt->bindParam(':value', $text);
        $stmt->execute();

$keyboard = [
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);

        setUserStep($from_id, null, null);
        sendMessage($chat_id, "✔️ اطلاعات کارت با موفقیت به‌روزرسانی شد." , $reply_markup);
        exit();
    }

    if ($user_step == 'get_user_id_for_balance') {
        if (!is_numeric($text)) {
            sendMessage($chat_id, "❌ لطفا فقط شناسه عددی کاربر را ارسال کنید.");
        } else {
            $target_user_id = $text;
            $stmt = $conn->prepare("SELECT first_name, balance FROM users WHERE user_id = :user_id");
            $stmt->bindParam(':user_id', $target_user_id);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $user_name = htmlspecialchars($user['first_name']);
                $current_balance = number_format($user['balance']);
                
                $message = "کاربر '<b>{$user_name}</b>' پیدا شد.\n\n";
                $message .= "💰 <b>موجودی فعلی:</b> {$current_balance} تومان\n\n";
                $message .= "چه عملیاتی می‌خواهید انجام دهید؟";

                $keyboard = [[
                    ['text' => '➕ افزودن موجودی', 'callback_data' => 'add_balance_to_user_' . $target_user_id],
                    ['text' => '➖ کسر موجودی', 'callback_data' => 'subtract_balance_from_user_' . $target_user_id]
                ]];
                $reply_markup = json_encode(['inline_keyboard' => $keyboard]);

                sendMessage($chat_id, $message, $reply_markup);
                setUserStep($from_id, null, null);
            } else {

$keyboardb = [
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markupb = json_encode(['inline_keyboard' => $keyboardb]);

                sendMessage($chat_id, "❌ کاربری با این شناسه عددی یافت نشد." , $reply_markupb);
                setUserStep($from_id, null, null);
            }
        }
        exit();
    }

    if ($user_step == 'add_balance_amount' || $user_step == 'subtract_balance_amount') {
        if (!is_numeric($text) || $text < 0) {
            sendMessage($chat_id, "❌ لطفا فقط مبلغ را به صورت عدد صحیح و مثبت وارد کنید.");
        } else {
            $amount = $text;
            $target_user_id = $temp_data['target_user_id'];
            
            $operation = ($user_step == 'add_balance_amount') ? '+' : '-';
            
            $update_stmt = $conn->prepare("UPDATE users SET balance = balance {$operation} :amount WHERE user_id = :user_id");
            $update_stmt->bindParam(':amount', $amount);
            $update_stmt->bindParam(':user_id', $target_user_id);
            $update_stmt->execute();

            $select_stmt = $conn->prepare("SELECT first_name, balance FROM users WHERE user_id = :user_id");
            $select_stmt->bindParam(':user_id', $target_user_id);
            $select_stmt->execute();
            $user = $select_stmt->fetch(PDO::FETCH_ASSOC);
            $new_balance = number_format($user['balance']);
            $user_name = htmlspecialchars($user['first_name']);
            
            $action_text = ($operation == '+') ? 'اضافه' : 'کسر';

$keyboard = [
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);

            $admin_message = "✅ مبلغ " . number_format($amount) . " تومان با موفقیت به حساب '<b>{$user_name}</b>' {$action_text} شد.\n\n";
            $admin_message .= "💰 <b>موجودی جدید کاربر:</b> {$new_balance} تومان.";
            sendMessage($chat_id, $admin_message , $reply_markup);
            
            $user_message = "📢 اطلاعیه مدیریت:\n\n";
            $user_message .= "مبلغ " . number_format($amount) . " تومان به حساب شما {$action_text} گردید.\n";
            $user_message .= "موجودی جدید شما: <b>{$new_balance}</b> تومان.";
            sendMessage($target_user_id, $user_message);
            
            setUserStep($from_id, null, null);
        }
        exit();
    }

    if ($user_step == 'set_test_traffic' || $user_step == 'set_test_days') {
        if (!is_numeric($text) || $text < 1) {
            sendMessage($chat_id, "❌ لطفا فقط عدد صحیح و بزرگتر از صفر وارد کنید.");
        } else {
            $key_to_set = ($user_step == 'set_test_traffic') ? 'test_account_traffic' : 'test_account_days';
            $stmt = $conn->prepare("UPDATE settings SET setting_value = :val WHERE setting_key = :key");
            $stmt->bindParam(':val', $text);
            $stmt->bindParam(':key', $key_to_set);
            $stmt->execute();

$keyboard = [
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            
            setUserStep($from_id, null, null);
            sendMessage($chat_id, "✔️ تنظیمات با موفقیت ذخیره شد." , $reply_markup);
        }
        exit();
    }

    if ($user_step == 'add_join_channel_id') {
        $channel_id = $text;
        
        $wait_message = sendMessage($chat_id, "⏳ در حال بررسی اطلاعات کانال...");

        $chat_info = getChat($channel_id);
        
        if (!$chat_info || (isset($chat_info['ok']) && !$chat_info['ok'])) {
            editMessageText($chat_id, $wait_message['message_id'], "❌ خطا: کانال یافت نشد یا ربات در آن ادمین نیست. لطفا دوباره تلاش کنید.");
            exit();
        }

        $channel_name = $chat_info['title'];
        $invite_link = '';

        if (isset($chat_info['invite_link'])) {
            $invite_link = $chat_info['invite_link'];
        } elseif (isset($chat_info['username'])) {
            $invite_link = "https://t.me/" . $chat_info['username'];
        } else {
            $link_info = exportChatInviteLink($channel_id);
            if ($link_info && is_string($link_info)) {
                $invite_link = $link_info;
            } else {
                editMessageText($chat_id, $wait_message['message_id'], "❌ خطا در ساخت لینک دعوت. مطمئن شوید ربات دسترسی 'Invite Users via Link' را دارد.");
                exit();
            }
        }
        
        try {
            $stmt = $conn->prepare("INSERT INTO join_channels (channel_id, channel_name, invite_link) VALUES (?, ?, ?)");
            $stmt->execute([$channel_id, $channel_name, $invite_link]);
            setUserStep($from_id, null, null);
            editMessageText($chat_id, $wait_message['message_id'], "✅ کانال '<b>{$channel_name}</b>' با موفقیت به لیست جوین اجباری اضافه شد." , $reply_markup);
        } catch (PDOException $e) {

$keyboard = [
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);

            setUserStep($from_id, null, null);
            editMessageText($chat_id, $wait_message['message_id'], "❌ این کانال قبلاً ثبت شده است." , $reply_markup);
        }
        exit();
    }
    
    if ($user_step == 'set_invitation_bonus') {
        if (!is_numeric($text) || $text < 0) { sendMessage($chat_id, "❌ لطفا فقط عدد وارد کنید."); }
        else {

$keyboard = [
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);

            $conn->prepare("UPDATE settings SET setting_value = :val WHERE setting_key = 'invitation_bonus'")->execute([':val' => $text]);
            setUserStep($from_id, null, null);
            sendMessage($chat_id, "✔️ مبلغ پاداش دعوت با موفقیت تنظیم شد." , $reply_markup);
        }
        exit();
    }

    if ($user_step == 'set_expiration_delete_days') {
        if (!is_numeric($text) || $text < 1) {
            sendMessage($chat_id, "❌ لطفا فقط عدد صحیح و بزرگتر از صفر وارد کنید.");
        } else {
            $stmt = $conn->prepare("UPDATE settings SET setting_value = :val WHERE setting_key = 'expiration_delete_days'");
            $stmt->bindParam(':val', $text);
            $stmt->execute();

$keyboard = [
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);

            setUserStep($from_id, null, null);
            sendMessage($chat_id, "✔️ مهلت حذف سرویس‌ها با موفقیت به <b>{$text} روز</b> تغییر یافت." , $reply_markup);
        }
        exit();
    }

    if ($user_step == 'get_text_broadcast') {
        $total_users = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $stmt = $conn->prepare("INSERT INTO message_queue (message_type, content, total_users) VALUES ('text', ?, ?)");
        $stmt->execute([$text, $total_users]);
        
        setUserStep($from_id, null, null);
        sendMessage($chat_id, "✅ پیام شما در صف ارسال قرار گرفت و به زودی برای {$total_users} نفر ارسال خواهد شد. پس از اتمام، به شما اطلاع داده می‌شود.");
        exit();
    }
    

    if ($user_step == 'get_forward_broadcast') {
        if (isset($update['message']['forward_from']) || isset($update['message']['forward_from_chat'])) {
            $total_users = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $stmt = $conn->prepare("INSERT INTO message_queue (message_type, content, from_chat_id, total_users) VALUES ('forward', ?, ?, ?)");
            $stmt->execute([$message_id, $chat_id, $total_users]);
            
            setUserStep($from_id, null, null);
            sendMessage($chat_id, "✅ پیام فورواردی شما در صف ارسال قرار گرفت و به زودی برای {$total_users} نفر ارسال خواهد شد. پس از اتمام، به شما اطلاع داده می‌شود.");
        } else {
            sendMessage($chat_id, "❌ لطفا فقط یک پیام را فوروارد کنید.");
        }
        exit();
    }

    if ($user_step == 'add_tutorial_platform') {
        $platform = trim($text);
        if ($conn->query("SELECT id FROM tutorials WHERE platform_name = '$platform'")->fetchColumn()) {
            sendMessage($chat_id, "❌ این پلتفرم قبلاً ثبت شده است. لطفا نام دیگری انتخاب کنید.");
        } else {
            setUserStep($from_id, 'add_tutorial_video', json_encode(['platform' => $platform]));
            sendMessage($chat_id, "✅ نام پلتفرم ثبت شد.\n\nحالا ویدیوی آموزشی را ارسال کنید:");
        }
        exit();
    }
    if ($user_step == 'add_tutorial_video') {
        if (!isset($update['message']['video'])) {
            sendMessage($chat_id, "❌ لطفا فقط یک ویدیو ارسال کنید.");
        } else {
            $temp_data['video_id'] = $update['message']['video']['file_id'];
            setUserStep($from_id, 'add_tutorial_image', json_encode($temp_data));
            sendMessage($chat_id, "✅ ویدیو ثبت شد.\n\nحالا تصویر آموزشی را ارسال کنید:");
        }
        exit();
    }
    if ($user_step == 'add_tutorial_image') {
        if (!isset($update['message']['photo'])) {
            sendMessage($chat_id, "❌ لطفا فقط یک تصویر ارسال کنید.");
        } else {
            $temp_data['image_id'] = $update['message']['photo'][count($update['message']['photo']) - 1]['file_id'];
            setUserStep($from_id, 'add_tutorial_text', json_encode($temp_data));
            sendMessage($chat_id, "✅ تصویر ثبت شد.\n\nحالا متن آموزشی (که همراه تصویر ارسال می‌شود) را بنویسید:");
        }
        exit();
    }
    if ($user_step == 'add_tutorial_text') {
        $temp_data['text'] = $text;
        setUserStep($from_id, 'add_tutorial_link', json_encode($temp_data));
        sendMessage($chat_id, "✅ متن ثبت شد.\n\nحالا لینک دانلود نرم‌افزار مربوطه را ارسال کنید:");
        exit();
    }
    if ($user_step == 'add_tutorial_link') {
        $platform = $temp_data['platform'];
        $video_id = $temp_data['video_id'];
        $image_id = $temp_data['image_id'];
        $text_content = $temp_data['text'];
        $download_link = $text;

        $stmt = $conn->prepare("INSERT INTO tutorials (platform_name, video_file_id, image_file_id, text_content, download_link) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$platform, $video_id, $image_id, $text_content, $download_link]);

$keyboard = [
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);

        setUserStep($from_id, null, null);
        sendMessage($chat_id, "✔️ راهنمای پلتفرم '<b>{$platform}</b>' با موفقیت ثبت شد." , $reply_markup);
        exit();
    }

    if ($user_step == 'get_admin_id_to_add' && $from_id == OWNER_ID) {
        if (!is_numeric($text)) {
            sendMessage($chat_id, "❌ لطفا فقط شناسه عددی کاربر را ارسال کنید.");
        } else {
            try {

$keyboard = [
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);

                $conn->prepare("INSERT INTO admins (user_id, added_by) VALUES (?, ?)")->execute([$text, $from_id]);
                sendMessage($chat_id, "✅ کاربر با شناسه {$text} با موفقیت به لیست ادمین‌ها اضافه شد. اکنون از طریق لیست ادمین‌ها، دسترسی‌های او را مشخص کنید." , $reply_markup);
            } catch (PDOException $e) {
                sendMessage($chat_id, "❌ این کاربر از قبل ادمین است.");
            }
            setUserStep($from_id, null, null);
        }
        exit();
    }


$permissions_list = [
    'manage_panels' => 'مدیریت پنل‌ها',
    'manage_categories' => 'مدیریت دسته‌بندی‌ها',
    'manage_plans' => 'مدیریت پلن‌ها',
    'manage_users' => 'مدیریت کاربران',
    'sales_settings' => 'تنظیمات فروش',
    'bot_settings' => 'تنظیمات ربات',
    'broadcast' => 'ارسال همگانی',
    'support' => 'پشتیبانی',
    'get_user_info' => 'خروجی کانفیگ کاربر',
    'test_account' => 'تنظیمات اکانت تست',
    'view_stats' => 'مشاهده آمار ربات'
];



    if ($text == '/panel') {
        if (!isAdmin($conn, $from_id)) {
            exit();
        }

        $keyboard = [];

        if (hasPermission($conn, $from_id, 'view_stats')) {
            $keyboard[] = [['text' => '📊 آمار ربات', 'callback_data' => 'bot_stats']];
        }
        
        if (hasPermission($conn, $from_id, 'manage_panels')) {
            $keyboard[] = [['text' => '➕ ثبت پنل جدید', 'callback_data' => 'add_panel'], ['text' => '📋 لیست پنل‌ها', 'callback_data' => 'list_panels']];
        }
        if (hasPermission($conn, $from_id, 'manage_categories')) {
            $keyboard[] = [['text' => '🗂️ مدیریت دسته‌بندی‌ها', 'callback_data' => 'manage_categories']];
        }
        if (hasPermission($conn, $from_id, 'manage_plans')) {
            $keyboard[] = [['text' => '🛍️ مدیریت پلن‌ها', 'callback_data' => 'manage_plans']];
        }
        if (hasPermission($conn, $from_id, 'manage_users')) {
            $keyboard[] = [['text' => '👤 مدیریت کاربران', 'callback_data' => 'manage_users']];
        }
        if (hasPermission($conn, $from_id, 'bot_settings')) {
            $keyboard[] = [['text' => '🤖 تنظیمات ربات', 'callback_data' => 'bot_settings']];
        }
        if (hasPermission($conn, $from_id, 'sales_settings')) {
            $keyboard[] = [['text' => '⚙️ تنظیمات فروش', 'callback_data' => 'sales_settings']];
        }
        if (hasPermission($conn, $from_id, 'test_account')) {
            $keyboard[] = [['text' => '🧪 تنظیمات اکانت تست', 'callback_data' => 'test_account_settings']];
        }
        if (hasPermission($conn, $from_id, 'broadcast')) {
            $keyboard[] = [['text' => '📣 ارسال همگانی', 'callback_data' => 'broadcast_menu']];
        }
        if (hasPermission($conn, $from_id, 'manage_tutorials')) {
            $keyboard[] = [['text' => '📚 مدیریت راهنما', 'callback_data' => 'manage_tutorials']];
        }
        if (hasPermission($conn, $from_id, 'support')) {
            $keyboard[] = [['text' => '📞 پشتیبانی (تیکت‌ها)', 'callback_data' => 'support_menu']];
        }
        if (hasPermission($conn, $from_id, 'get_user_info')) {
            $keyboard[] = [['text' => '🕵️ خروجی کاربر', 'callback_data' => 'get_user_info']];
        }

        if ($from_id == OWNER_ID) {
            $keyboard[] = [['text' => '👑 مدیریت ادمین‌ها', 'callback_data' => 'manage_admins']];
        }

        if (empty($keyboard) && $from_id != OWNER_ID) {
            sendMessage($chat_id, "شما به هیچ بخشی از پنل مدیریت دسترسی ندارید.");
            exit();
        }

        $keyboard[] = [['text' => '❌ بستن پنل', 'callback_data' => 'close_message']];
        
        $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
        sendMessage($chat_id, "به پنل مدیریت خوش آمدید. لطفا یک گزینه را انتخاب کنید:", $reply_markup);
    }

        elseif ($data == 'back_to_panel_main') {
            if (!isAdmin($conn, $from_id)) {
                apiRequest('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $message_id]);
                exit();
            }

            $keyboard = [];
        
        if (hasPermission($conn, $from_id, 'view_stats')) {
            $keyboard[] = [['text' => '📊 آمار ربات', 'callback_data' => 'bot_stats']];
        }
        
        if (hasPermission($conn, $from_id, 'manage_panels')) {
            $keyboard[] = [['text' => '➕ ثبت پنل جدید', 'callback_data' => 'add_panel'], ['text' => '📋 لیست پنل‌ها', 'callback_data' => 'list_panels']];
        }
        if (hasPermission($conn, $from_id, 'manage_categories')) {
            $keyboard[] = [['text' => '🗂️ مدیریت دسته‌بندی‌ها', 'callback_data' => 'manage_categories']];
        }
        if (hasPermission($conn, $from_id, 'manage_plans')) {
            $keyboard[] = [['text' => '🛍️ مدیریت پلن‌ها', 'callback_data' => 'manage_plans']];
        }
        if (hasPermission($conn, $from_id, 'manage_users')) {
            $keyboard[] = [['text' => '👤 مدیریت کاربران', 'callback_data' => 'manage_users']];
        }
        if (hasPermission($conn, $from_id, 'bot_settings')) {
            $keyboard[] = [['text' => '🤖 تنظیمات ربات', 'callback_data' => 'bot_settings']];
        }
        if (hasPermission($conn, $from_id, 'sales_settings')) {
            $keyboard[] = [['text' => '⚙️ تنظیمات فروش', 'callback_data' => 'sales_settings']];
        }
        if (hasPermission($conn, $from_id, 'test_account')) {
            $keyboard[] = [['text' => '🧪 تنظیمات اکانت تست', 'callback_data' => 'test_account_settings']];
        }
        if (hasPermission($conn, $from_id, 'broadcast')) {
            $keyboard[] = [['text' => '📣 ارسال همگانی', 'callback_data' => 'broadcast_menu']];
        }
        if (hasPermission($conn, $from_id, 'manage_tutorials')) {
            $keyboard[] = [['text' => '📚 مدیریت راهنما', 'callback_data' => 'manage_tutorials']];
        }
        if (hasPermission($conn, $from_id, 'support')) {
            $keyboard[] = [['text' => '📞 پشتیبانی (تیکت‌ها)', 'callback_data' => 'support_menu']];
        }
        if (hasPermission($conn, $from_id, 'get_user_info')) {
            $keyboard[] = [['text' => '🕵️ خروجی کاربر', 'callback_data' => 'get_user_info']];
        }

            if ($from_id == OWNER_ID) {
                $keyboard[] = [['text' => '👑 مدیریت ادمین‌ها', 'callback_data' => 'manage_admins']];
            }
            
            $keyboard[] = [['text' => '❌ بستن پنل', 'callback_data' => 'close_message']];
            
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            
            editMessageText($chat_id, $message_id, "به پنل مدیریت خوش آمدید. لطفا یک گزینه را انتخاب کنید:", $reply_markup);
        }

    if (isset($data)) {
        if ($data == 'add_panel' && hasPermission($conn, $from_id, 'manage_panels')) {
            setUserStep($from_id, 'add_panel_url');
            editMessageText($chat_id, $message_id, "لطفا لینک کامل ورود به پنل را ارسال کنید (مثال: http://1.2.3.4:5678):");
        }
        
        elseif ($data == 'list_panels' && hasPermission($conn, $from_id, 'manage_panels')) {
            $stmt = $conn->query("SELECT * FROM panels ORDER BY id DESC");
            $panels = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($panels) > 0) {
                $message = "<b>لیست پنل های ثبت شده:</b>\n\n";
                $keyboard = [];
                foreach ($panels as $panel) {
                    $decrypted_pass = decrypt_data($panel['password']);
                    $login_result = checkPanelStatus($panel['url'], $panel['username'], $decrypted_pass);
                    $status_icon = $login_result['success'] ? '🟢' : '🔴';

                    $message .= "{$status_icon} {$panel['name']}\n";

                    $keyboard[] = [['text' => "🗑 حذف '{$panel['name']}'", 'callback_data' => 'delete_panel_' . urlencode($panel['name'])]];
                }
                
                $keyboard[] = [
                    ['text' => '🔄 بروزرسانی لیست', 'callback_data' => 'list_panels'],
                    ['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']
                ];

                $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
                editMessageText($chat_id, $message_id, $message, $reply_markup);
            } else {
                $message = "هیچ پنلی در سیستم ثبت نشده است.";
                $keyboard = [[['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]];
                $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
                editMessageText($chat_id, $message_id, $message, $reply_markup);
            }
        }

        elseif ($data == 'manage_plans' && hasPermission($conn, $from_id, 'manage_plans')) {
            $keyboard = [
                [['text' => '➕ ساخت پلن جدید', 'callback_data' => 'add_plan']],
                [['text' => '📋 لیست پلن‌ها', 'callback_data' => 'list_plans_sale']],
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            editMessageText($chat_id, $message_id, "بخش مدیریت پلن‌های فروش:", $reply_markup);
        }

        elseif ($data == 'list_plans_sale' && hasPermission($conn, $from_id, 'manage_plans')) {
            $stmt = $conn->query("SELECT p.*, pn.name as panel_name FROM plans p JOIN panels pn ON p.panel_id = pn.id ORDER BY p.id DESC");
            $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $message = "<b>لیست پلن‌های فروش:</b>\n\n";
            $keyboard = [];
            if(count($plans) > 0){
                $counter = 1;
                foreach ($plans as $plan) {
                    $message .= "<b>{$counter}.</b> 🔹 <b>نام:</b> {$plan['name']}\n";
                    $message .= "   - <b>سرور:</b> {$plan['panel_name']}\n";
                    $message .= "   - <b>قیمت:</b> " . number_format($plan['price']) . " تومان\n";
                    $keyboard[] = [
                        ['text' => "✏️ ویرایش {$counter}", 'callback_data' => 'edit_plan_menu_' . $plan['id']],
                        ['text' => "🗑 حذف {$counter}", 'callback_data' => 'confirm_delete_plan_' . $plan['id']]
                    ];
                    $counter++;
                }
            } else {
                 $message .= "هیچ پلنی یافت نشد.";
            }
            $keyboard[] = [['text' => '🔙 بازگشت', 'callback_data' => 'manage_plans']];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            editMessageText($chat_id, $message_id, $message, $reply_markup);
        }
        
        elseif (strpos($data, 'confirm_delete_plan_') === 0  && hasPermission($conn, $from_id, 'manage_plans')) {
            $plan_id = str_replace('confirm_delete_plan_', '', $data);
            $stmt = $conn->prepare("SELECT name FROM plans WHERE id = :id");
            $stmt->bindParam(':id', $plan_id);
            $stmt->execute();
            $plan = $stmt->fetch(PDO::FETCH_ASSOC);
            $plan_name = $plan ? $plan['name'] : 'ناشناس';

            $keyboard = [
                [['text' => '❗️ بله، حذف کن', 'callback_data' => 'do_delete_plan_' . $plan_id]],
                [['text' => ' خیر', 'callback_data' => 'list_plans_sale']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            editMessageText($chat_id, $message_id, "❓ آیا از حذف پلن '<b>{$plan_name}</b>' مطمئن هستید؟", $reply_markup);
        }
        
        elseif (strpos($data, 'do_delete_plan_') === 0 && hasPermission($conn, $from_id, 'manage_plans')) {
            $plan_id = str_replace('do_delete_plan_', '', $data);
            $stmt = $conn->prepare("DELETE FROM plans WHERE id = :id");
            $stmt->bindParam(':id', $plan_id);
            $stmt->execute();
            editMessageText($chat_id, $message_id, "✅ پلن با موفقیت حذف شد.");
        }
        
        elseif (strpos($data, 'edit_plan_menu_') === 0 && hasPermission($conn, $from_id, 'manage_plans')) {
            $plan_id = str_replace('edit_plan_menu_', '', $data);
            $keyboard = [
                [['text' => '✏️ ویرایش نام', 'callback_data' => 'edit_plan_name_' . $plan_id]],
                [['text' => '✏️ ویرایش قیمت', 'callback_data' => 'edit_plan_price_' . $plan_id]],
                [['text' => '🔙 بازگشت به لیست', 'callback_data' => 'list_plans_sale']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            editMessageText($chat_id, $message_id, "کدام مشخصه این پلن را می‌خواهید ویرایش کنید؟", $reply_markup);
        }
        
        elseif (strpos($data, 'edit_plan_price_') === 0 && hasPermission($conn, $from_id, 'manage_plans')) {
            $plan_id = str_replace('edit_plan_price_', '', $data);
            setUserStep($from_id, 'edit_plan_price', json_encode(['plan_id' => $plan_id]));
            editMessageText($chat_id, $message_id, "لطفا قیمت جدید را به تومان (فقط عدد) برای این پلن ارسال کنید:");
        }
        
        elseif (strpos($data, 'edit_plan_name_') === 0 && hasPermission($conn, $from_id, 'manage_plans')) {
            $plan_id = str_replace('edit_plan_name_', '', $data);
            setUserStep($from_id, 'edit_plan_name', json_encode(['plan_id' => $plan_id]));
            editMessageText($chat_id, $message_id, "لطفا نام جدید را برای این پلن ارسال کنید:");
        }

        elseif ($data == 'add_plan' && hasPermission($conn, $from_id, 'manage_plans')) {
            $stmt = $conn->query("SELECT id, name FROM panels ORDER BY id DESC");
            $panels = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($panels) > 0) {
                $keyboard = [];
                foreach ($panels as $panel) {
                    $keyboard[] = [['text' => $panel['name'], 'callback_data' => 'select_panel_for_plan_' . $panel['id']]];
                }
                $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
                editMessageText($chat_id, $message_id, "ساخت پلن جدید:\n\nابتدا انتخاب کنید این پلن روی کدام سرور ساخته شود:", $reply_markup);

                setUserStep($from_id, 'add_plan_select_panel');
            } else {
                editMessageText($chat_id, $message_id, "❌ ابتدا باید حداقل یک پنل (سرور) ثبت کنید.");
            }
        }
        elseif (strpos($data, 'select_category_for_plan_') === 0 && $user_step == 'add_plan_select_category' && hasPermission($conn, $from_id, 'manage_plans')) {
            $category_id = str_replace('select_category_for_plan_', '', $data);
            
            $panel_id = $temp_data['panel_id'];
            $name = $temp_data['name'];
            $traffic = $temp_data['traffic'];
            $price = $temp_data['price'];
            $days = $temp_data['days'];
            $description = "سرویس <b>{$name}</b>\n\n- مدت اعتبار: {days} روز\n- حجم ترافیک: {traffic}\n- قیمت: {price} تومان";

            $stmt = $conn->prepare("INSERT INTO plans (name, days, traffic, price, description, panel_id, category_id) VALUES (:name, :days, :traffic, :price, :description, :panel_id, :category_id)");
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':days', $days);
            $stmt->bindParam(':traffic', $traffic);
            $stmt->bindParam(':price', $price);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':panel_id', $panel_id);
            $stmt->bindParam(':category_id', $category_id);
            $stmt->execute();
            
            setUserStep($from_id, null, null);
            editMessageText($chat_id, $message_id, "✔️ پلن فروش '<b>{$name}</b>' با موفقیت در سیستم ثبت و به دسته‌بندی مورد نظر اضافه شد.");
        }

        elseif ($data == 'get_user_info' && hasPermission($conn, $from_id, 'get_user_info')) {
            $stmt = $conn->query("SELECT id, name FROM panels ORDER BY id DESC");
            $panels = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($panels) > 0) {
                $keyboard = [];
                foreach ($panels as $panel) {
                    $keyboard[] = [['text' => $panel['name'], 'callback_data' => 'select_panel_for_userinfo_' . $panel['id']]];
                }
                $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
                editMessageText($chat_id, $message_id, "لطفا پنلی که کاربر در آن قرار دارد را انتخاب کنید:", $reply_markup);
            } else {
                editMessageText($chat_id, $message_id, "ابتدا باید حداقل یک پنل ثبت کنید.");
            }
        }

        elseif (strpos($data, 'select_panel_for_userinfo_') === 0 && hasPermission($conn, $from_id, 'get_user_info')) {
            $panel_id = str_replace('select_panel_for_userinfo_', '', $data);
            setUserStep($from_id, 'get_user_username', json_encode(['panel_id' => $panel_id]));
            editMessageText($chat_id, $message_id, "✅ پنل انتخاب شد.\n\nحالا یوزرنیم کاربر مورد نظر را (به انگلیسی و بدون فاصله) ارسال کنید:");
        }

        elseif ($data == 'manage_categories' && hasPermission($conn, $from_id, 'manage_categories')) {
            $keyboard = [
                [['text' => '➕ ساخت دسته‌بندی', 'callback_data' => 'add_category']],
                [['text' => '📋 لیست دسته‌بندی‌ها', 'callback_data' => 'list_categories']],
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            editMessageText($chat_id, $message_id, "بخش مدیریت دسته‌بندی‌ها:", $reply_markup);
        }

        elseif ($data == 'add_category' && hasPermission($conn, $from_id, 'manage_categories')) {
            setUserStep($from_id, 'add_category_name');
            editMessageText($chat_id, $message_id, "لطفا یک نام برای دسته‌بندی جدید ارسال کنید:");
        }
        
        elseif ($data == 'list_categories' && hasPermission($conn, $from_id, 'manage_categories')) {
            $stmt = $conn->query("SELECT * FROM categories ORDER BY id DESC");
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $message = "<b>لیست دسته‌بندی‌ها:</b>\n\n";
            $keyboard = [];
            if(count($categories) > 0){
                $counter = 1;
                foreach ($categories as $cat) {
                    $message .= "<b>{$counter}.</b> {$cat['emoji']} {$cat['name']}\n";
                    $keyboard[] = [
                        ['text' => "✏️ ویرایش {$counter}", 'callback_data' => 'edit_category_' . $cat['id']],
                        ['text' => "🗑 حذف {$counter}", 'callback_data' => 'confirm_delete_category_' . $cat['id']]
                    ];
                    $counter++;
                }
            } else {
                $message .= "هیچ دسته‌بندی یافت نشد.";
            }
            $keyboard[] = [['text' => '🔙 بازگشت', 'callback_data' => 'manage_categories']];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            editMessageText($chat_id, $message_id, $message, $reply_markup);
        }
        
        elseif (strpos($data, 'confirm_delete_category_') === 0 && hasPermission($conn, $from_id, 'manage_categories')) {
            $cat_id = str_replace('confirm_delete_category_', '', $data);
            $stmt = $conn->prepare("SELECT name FROM categories WHERE id = :id");
            $stmt->bindParam(':id', $cat_id);
            $stmt->execute();
            $category = $stmt->fetch(PDO::FETCH_ASSOC);
            $category_name = $category ? $category['name'] : 'ناشناس';

            $keyboard = [
                [['text' => '❗️ بله، حذف کن', 'callback_data' => 'do_delete_category_' . $cat_id]],
                [['text' => ' خیر', 'callback_data' => 'list_categories']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            editMessageText($chat_id, $message_id, "❓ آیا از حذف دسته‌بندی '<b>{$category_name}</b>' و تمام پلن‌های داخل آن مطمئن هستید؟", $reply_markup);
        }
        
        elseif (strpos($data, 'do_delete_category_') === 0 && hasPermission($conn, $from_id, 'manage_categories')) {
            $cat_id = str_replace('do_delete_category_', '', $data);
            $stmt = $conn->prepare("DELETE FROM categories WHERE id = :id");
            $stmt->bindParam(':id', $cat_id);
            $stmt->execute();
            $stmt = $conn->prepare("DELETE FROM plans WHERE category_id = :cat_id");
            $stmt->bindParam(':cat_id', $cat_id);
            $stmt->execute();
            editMessageText($chat_id, $message_id, "✅ دسته‌بندی و تمام پلن‌های داخل آن با موفقیت حذف شدند.");
        }
        
        elseif (strpos($data, 'edit_category_') === 0) {
            $cat_id = str_replace('edit_category_', '', $data);
            setUserStep($from_id, 'edit_category_name', json_encode(['category_id' => $cat_id]));
            editMessageText($chat_id, $message_id, "لطفا نام جدید را برای این دسته‌بندی ارسال کنید:");
        }

elseif ($data == 'sales_settings' && hasPermission($conn, $from_id, 'sales_settings')) {
            $stmt = $conn->query("SELECT * FROM settings");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $balance_status_text = ($settings['balance_payment_status'] == 1) ? '✅ فعال' : '❌ غیرفعال';
            $card_status_text = ($settings['card_payment_status'] == 1) ? '✅ فعال' : '❌ غیرفعال';

            $keyboard = [
                [['text' => "پرداخت با موجودی: " . $balance_status_text, 'callback_data' => 'toggle_balance_payment']],
                [['text' => "کارت به کارت: " . $card_status_text, 'callback_data' => 'toggle_card_payment']],
                [['text' => '✏️ ویرایش اطلاعات کارت', 'callback_data' => 'edit_card_info']],
                [['text' => '💳 تنظیمات شارژ حساب', 'callback_data' => 'charge_wallet_settings']],
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            $message = "<b>تنظیمات روش‌های پرداخت و فروش:</b>\n\n- اطلاعات کارت فعلی:\n`{$settings['bank_card_info']}`";
            editMessageText($chat_id, $message_id, $message, $reply_markup);
        }

        elseif ($data == 'charge_wallet_settings' && hasPermission($conn, $from_id, 'sales_settings')) {
            $stmt = $conn->query("SELECT * FROM settings");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $charge_card_status_text = ($settings['charge_card_status'] == 1) ? '✅ فعال' : '❌ غیرفعال';

            $message = "در این بخش می‌توانید روش‌های شارژ حساب کاربری را مدیریت کنید:";
            $keyboard = [
                [['text' => "شارژ با کارت به کارت: " . $charge_card_status_text, 'callback_data' => 'toggle_charge_card']],
                // [['text' => "شارژ با درگاه پرداخت: ❌", 'callback_data' => '...']],
                [['text' => '🔙 بازگشت به تنظیمات فروش', 'callback_data' => 'sales_settings']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            editMessageText($chat_id, $message_id, $message, $reply_markup);
        }

        elseif ($data == 'toggle_charge_card' && hasPermission($conn, $from_id, 'sales_settings')) {
            $conn->query("UPDATE settings SET setting_value = 1 - setting_value WHERE setting_key = 'charge_card_status'");
            answerCallbackQuery($update["callback_query"]["id"], "وضعیت تغییر کرد.");
            $data = 'charge_wallet_settings';
        }



        elseif ($data == 'edit_card_info' && hasPermission($conn, $from_id, 'sales_settings')) {
            setUserStep($from_id, 'set_card_info');
            
            editMessageText($chat_id, $message_id, "لطفا شماره کارت جدید و نام صاحب حساب را ارسال کنید:");
        }

        elseif ($data == 'toggle_balance_payment' || $data == 'toggle_card_payment' && hasPermission($conn, $from_id, 'sales_settings')) {
            $key_to_toggle = ($data == 'toggle_balance_payment') ? 'balance_payment_status' : 'card_payment_status';
            
            $stmt = $conn->prepare("UPDATE settings SET setting_value = 1 - setting_value WHERE setting_key = :key");
            $stmt->bindParam(':key', $key_to_toggle);
            $stmt->execute();

            $stmt = $conn->query("SELECT * FROM settings");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $toggled_setting_name = ($key_to_toggle == 'balance_payment_status') ? 'پرداخت با موجودی' : 'کارت به کارت';
            $new_status_text = ($settings[$key_to_toggle] == 1) ? 'روشن' : 'خاموش';
            
            answerCallbackQuery($update["callback_query"]["id"], "✅ " . $toggled_setting_name . " " . $new_status_text . " شد.");
            
            $balance_status_text = ($settings['balance_payment_status'] == 1) ? '✅ فعال' : '❌ غیرفعال';
            $card_status_text = ($settings['card_payment_status'] == 1) ? '✅ فعال' : '❌ غیرفعال';

            $keyboard = [
                [['text' => "پرداخت با موجودی: " . $balance_status_text, 'callback_data' => 'toggle_balance_payment']],
                [['text' => "کارت به کارت: " . $card_status_text, 'callback_data' => 'toggle_card_payment']],
                [['text' => '✏️ ویرایش اطلاعات کارت', 'callback_data' => 'edit_card_info']],
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            $message = "<b>تنظیمات روش‌های پرداخت و فروش:</b>\n\n- اطلاعات کارت فعلی:\n`{$settings['bank_card_info']}`";
            
            editMessageText($chat_id, $message_id, $message, $reply_markup);
        }

        elseif ($data == 'manage_users' && hasPermission($conn, $from_id, 'manage_users')) {
            $keyboard = [
                [['text' => '💰 تغییر موجودی کاربر', 'callback_data' => 'change_balance']],
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            editMessageText($chat_id, $message_id, "بخش مدیریت کاربران:", $reply_markup);
        }
        
        elseif ($data == 'change_balance' && hasPermission($conn, $from_id, 'manage_users')) {
            setUserStep($from_id, 'get_user_id_for_balance');
            editMessageText($chat_id, $message_id, "لطفا شناسه عددی (User ID) کاربر مورد نظر را ارسال کنید:");
        }

        elseif (strpos($data, 'add_balance_to_user_') === 0 && hasPermission($conn, $from_id, 'manage_users')) {
            $user_id_to_change = str_replace('add_balance_to_user_', '', $data);
            setUserStep($from_id, 'add_balance_amount', json_encode(['target_user_id' => $user_id_to_change]));
            editMessageText($chat_id, $message_id, "لطفا مبلغی که می‌خواهید به حساب کاربر اضافه شود را به تومان (فقط عدد) ارسال کنید:");
        }
        
        elseif (strpos($data, 'subtract_balance_from_user_') === 0 && hasPermission($conn, $from_id, 'manage_users')) {
            $user_id_to_change = str_replace('subtract_balance_from_user_', '', $data);
            setUserStep($from_id, 'subtract_balance_amount', json_encode(['target_user_id' => $user_id_to_change]));
            editMessageText($chat_id, $message_id, "لطفا مبلغی که می‌خواهید از حساب کاربر کسر شود را به تومان (فقط عدد) ارسال کنید:");
        }

    elseif ($data == 'test_account_settings' && hasPermission($conn, $from_id, 'test_account')) {
        $stmt = $conn->query("SELECT * FROM settings");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $panel_name = 'انتخاب نشده';
        if (!empty($settings['test_account_panel_id'])) {
            $panel_stmt = $conn->prepare("SELECT name FROM panels WHERE id = :id");
            $panel_stmt->bindParam(':id', $settings['test_account_panel_id']);
            $panel_stmt->execute();
            $panel = $panel_stmt->fetch(PDO::FETCH_ASSOC);
            if ($panel) $panel_name = $panel['name'];
        }

        $status_text = ($settings['test_account_status'] == 1) ? '✅ فعال' : '❌ غیرفعال';

        $message = "<b>تنظیمات اکانت تست:</b>\n\n";
        $message .= "▫️ <b>وضعیت کلی:</b> {$status_text}\n";
        $message .= "▫️ <b>حجم:</b> {$settings['test_account_traffic']} گیگابایت\n";
        $message .= "▫️ <b>زمان:</b> {$settings['test_account_days']} روز\n";
        $message .= "▫️ <b>سرور ساخت:</b> {$panel_name}";

        $keyboard = [
            [['text' => "تغییر وضعیت: " . $status_text, 'callback_data' => 'toggle_test_account']],
            [['text' => '✏️ ویرایش حجم', 'callback_data' => 'edit_test_traffic'], ['text' => '✏️ ویرایش زمان', 'callback_data' => 'edit_test_days']],
            [['text' => '🌐 انتخاب سرور', 'callback_data' => 'select_test_panel']],
            [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
        ];
        $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
        editMessageText($chat_id, $message_id, $message, $reply_markup);
    }

        elseif ($data == 'toggle_test_account' && hasPermission($conn, $from_id, 'test_account')) {
            $conn->query("UPDATE settings SET setting_value = 1 - setting_value WHERE setting_key = 'test_account_status'");

            $new_status_query = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'test_account_status'")->fetchColumn();
            $status_text_notification = ($new_status_query == 1) ? 'فعال' : 'غیرفعال';
            answerCallbackQuery($update["callback_query"]["id"], "اکانت تست " . $status_text_notification . " شد.");

            $stmt = $conn->query("SELECT * FROM settings");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $panel_name = 'انتخاب نشده';
            if (!empty($settings['test_account_panel_id'])) {
                $panel_stmt = $conn->prepare("SELECT name FROM panels WHERE id = :id");
                $panel_stmt->bindParam(':id', $settings['test_account_panel_id']);
                $panel_stmt->execute();
                $panel = $panel_stmt->fetch(PDO::FETCH_ASSOC);
                if ($panel) $panel_name = $panel['name'];
            }
            $status_text = ($settings['test_account_status'] == 1) ? '✅ فعال' : '❌ غیرفعال';
            $message = "<b>تنظیمات اکانت تست:</b>\n\n▫️ <b>وضعیت کلی:</b> {$status_text}\n▫️ <b>حجم:</b> {$settings['test_account_traffic']} گیگابایت\n▫️ <b>زمان:</b> {$settings['test_account_days']} روز\n▫️ <b>سرور ساخت:</b> {$panel_name}";
            $keyboard = [
                [['text' => "تغییر وضعیت: " . $status_text, 'callback_data' => 'toggle_test_account']],
                [['text' => '✏️ ویرایش حجم', 'callback_data' => 'edit_test_traffic'], ['text' => '✏️ ویرایش زمان', 'callback_data' => 'edit_test_days']],
                [['text' => '🌐 انتخاب سرور', 'callback_data' => 'select_test_panel']],
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            
            editMessageText($chat_id, $message_id, $message, $reply_markup);
        }

        elseif ($data == 'edit_test_traffic' && hasPermission($conn, $from_id, 'test_account')) {
            setUserStep($from_id, 'set_test_traffic');
            editMessageText($chat_id, $message_id, "لطفا حجم اکانت تست را به گیگابایت (فقط عدد) ارسال کنید:");
        }

        elseif ($data == 'edit_test_days' && hasPermission($conn, $from_id, 'test_account')) {
            setUserStep($from_id, 'set_test_days');
            editMessageText($chat_id, $message_id, "لطفا مدت زمان اکانت تست را به روز (فقط عدد) ارسال کنید:");
        }

                elseif ($data == 'select_test_panel' && hasPermission($conn, $from_id, 'test_account')) {
            $stmt = $conn->query("SELECT id, name FROM panels ORDER BY id DESC");
            $panels = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($panels) > 0) {
                $keyboard = [];
                foreach ($panels as $panel) {
                    $keyboard[] = [['text' => $panel['name'], 'callback_data' => 'set_test_panel_' . $panel['id']]];
                }
                
                $keyboard[] = [['text' => '🔙 بازگشت', 'callback_data' => 'test_account_settings']];

                $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
                editMessageText($chat_id, $message_id, "لطفا انتخاب کنید اکانت‌های تست روی کدام سرور ساخته شوند:", $reply_markup);

            } else {
                answerCallbackQuery($update["callback_query"]["id"], "ابتدا باید حداقل یک پنل ثبت کنید.", true);
            }
        }
        
        elseif (strpos($data, 'set_test_panel_') === 0 && hasPermission($conn, $from_id, 'test_account')) {
            $panel_id = str_replace('set_test_panel_', '', $data);
            $stmt = $conn->prepare("UPDATE settings SET setting_value = :val WHERE setting_key = 'test_account_panel_id'");
            $stmt->bindParam(':val', $panel_id);
            $stmt->execute();
            answerCallbackQuery($update["callback_query"]["id"], "سرور با موفقیت انتخاب شد.");
            $data = 'test_account_settings';
        }


        elseif (strpos($data, 'confirm_tx_') === 0 || strpos($data, 'reject_tx_') === 0 && hasPermission($conn, $from_id, 'manage_users')) {
            $transaction_id = str_replace(['confirm_tx_', 'reject_tx_'], '', $data);
            
            $stmt = $conn->prepare("SELECT * FROM transactions WHERE id = :id");
            $stmt->bindParam(':id', $transaction_id);
            $stmt->execute();
            $tx_info = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tx_info || $tx_info['status'] !== 'awaiting_confirmation') {
                answerCallbackQuery($update["callback_query"]["id"], "این درخواست قبلاً توسط مدیر دیگری بررسی شده است.", true);
                editMessageText($chat_id, $message_id, "این درخواست قبلاً توسط مدیر دیگری بررسی شده است.");
                exit();
            }
            
            answerCallbackQuery($update["callback_query"]["id"]); // پاسخ اولیه به تلگرام

            $admin_who_clicked_name = htmlspecialchars($update['callback_query']['from']['first_name']);
            $is_confirm = strpos($data, 'confirm_tx_') === 0;
            
            $new_status = '';
            $user_message = '';
            $message_for_acting_admin = '';
            $message_for_other_admins = '';

            if ($is_confirm) {
                if ($tx_info['plan_id'] == 0) {
                    $conn->prepare("UPDATE users SET balance = balance + :amount WHERE user_id = :user_id")->execute([':amount' => $tx_info['price'], ':user_id' => $tx_info['user_id']]);
                    $new_status = 'confirmed';
                    $user_message = "✅ پرداخت شما با کد پیگیری {$tx_info['tracking_code']} تایید و مبلغ " . number_format($tx_info['price']) . " تومان به حساب شما اضافه شد.";
                    $message_for_acting_admin = "✅ شما شارژ حساب با کد <code>{$tx_info['tracking_code']}</code> را با موفقیت <b>تایید کردید</b>.";
                    $message_for_other_admins = "✅ شارژ حساب با کد <code>{$tx_info['tracking_code']}</code> توسط '{$admin_who_clicked_name}' <b>تایید شد</b>.";
                
                } else {
                    editMessageText($chat_id, $message_id, "⏳ در حال ساخت سرویس برای کاربر...");
                    $result = finalizePurchaseAndCreateService($conn, $tx_info['user_id'], $tx_info['plan_id']);
                    if ($result['success']) {
                        $new_status = 'confirmed';
                        $user_message = "✅ پرداخت شما با کد پیگیری {$tx_info['tracking_code']} تایید و سرویس شما ساخته شد.";
                        $message_for_acting_admin = "✅ شما رسید خرید با کد <code>{$tx_info['tracking_code']}</code> را تایید و سرویس را با موفقیت برای کاربر ساختید.";
                        $message_for_other_admins = "✅ رسید خرید با کد <code>{$tx_info['tracking_code']}</code> توسط '{$admin_who_clicked_name}' <b>تایید و سرویس ساخته شد</b>.";
                    } else {
                        $new_status = 'failed_api';
                        $user_message = "❌ پرداخت شما تایید شد اما در ساخت سرویس خطایی رخ داد. کد پیگیری: {$tx_info['tracking_code']}";
                        $message_for_acting_admin = "⚠️ شما رسید با کد <code>{$tx_info['tracking_code']}</code> را تایید کردید، اما <b>API خطا داد</b>: " . $result['error'];
                        $message_for_other_admins = "⚠️ رسید با کد <code>{$tx_info['tracking_code']}</code> توسط '{$admin_who_clicked_name}' تایید شد اما <b>API خطا داد</b>.";
                    }
                }
            } else {
                $new_status = 'rejected';
                $user_message = "❌ پرداخت شما با کد پیگیری {$tx_info['tracking_code']} رد شد.";
                $message_for_acting_admin = "❌ شما رسید با کد <code>{$tx_info['tracking_code']}</code> را <b>رد کردید</b>.";
                $message_for_other_admins = "❌ رسید با کد <code>{$tx_info['tracking_code']}</code> توسط '{$admin_who_clicked_name}' <b>رد شد</b>.";
            }

            $update_stmt = $conn->prepare("UPDATE transactions SET status = :status WHERE id = :id");
            $update_stmt->bindParam(':status', $new_status);
            $update_stmt->bindParam(':id', $transaction_id);
            $update_stmt->execute();
            
            sendMessage($tx_info['user_id'], $user_message);
            
            foreach ($admin_ids as $admin_id) {
                if ($admin_id == $from_id) {
                    sendMessage($admin_id, $message_for_acting_admin);
                } else {
                    sendMessage($admin_id, $message_for_other_admins);
                }
            }
        }

        elseif ($data == 'bot_settings' && hasPermission($conn, $from_id, 'bot_settings')) {
            $stmt = $conn->query("SELECT * FROM settings");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $join_status = ($settings['force_join_status'] == 1) ? '✅ فعال' : '❌ غیرفعال';
            $invite_status = ($settings['invitation_status'] == 1) ? '✅ فعال' : '❌ غیرفعال';

            $message = "<b>تنظیمات کلی ربات:</b>\n\n";
            $message .= "▫️ <b>جوین اجباری:</b> {$join_status}\n";
            $message .= "▫️ <b>زیرمجموعه‌گیری:</b> {$invite_status}\n";
            $message .= "▫️ <b>پاداش دعوت:</b> " . number_format($settings['invitation_bonus']) . " تومان\n";
            $message .= "▫️ <b>مهلت تمدید پس از انقضا:</b> {$settings['expiration_delete_days']} روز\n";
            
            $keyboard = [
                [['text' => "جوین اجباری: " . $join_status, 'callback_data' => 'toggle_force_join']],
                [['text' => '➕ مدیریت کانال‌های جوین', 'callback_data' => 'manage_join_channels']],
                [['text' => "زیرمجموعه‌گیری: " . $invite_status, 'callback_data' => 'toggle_invitation']],
                [['text' => '✏️ تنظیم پاداش', 'callback_data' => 'set_invitation_bonus']],
                [['text' => '✏️ تنظیم مهلت حذف', 'callback_data' => 'set_delete_days']],
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            editMessageText($chat_id, $message_id, $message, $reply_markup);
        }

        elseif (($data == 'toggle_force_join' || $data == 'toggle_invitation') && hasPermission($conn, $from_id, 'bot_settings')) {
            $key_to_toggle = ($data == 'toggle_force_join') ? 'force_join_status' : 'invitation_status';
            
            $conn->query("UPDATE settings SET setting_value = 1 - setting_value WHERE setting_key = '{$key_to_toggle}'");

            $new_status_query = $conn->query("SELECT setting_value FROM settings WHERE setting_key = '{$key_to_toggle}'")->fetchColumn();
            $toggled_item_name = ($key_to_toggle == 'force_join_status') ? 'جوین اجباری' : 'زیرمجموعه‌گیری';
            $status_text_notification = ($new_status_query == 1) ? 'فعال' : 'غیرفعال';
            answerCallbackQuery($update["callback_query"]["id"], $toggled_item_name . " " . $status_text_notification . " شد.");

            $stmt = $conn->query("SELECT * FROM settings");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $join_status = ($settings['force_join_status'] == 1) ? '✅ فعال' : '❌ غیرفعال';
            $invite_status = ($settings['invitation_status'] == 1) ? '✅ فعال' : '❌ غیرفعال';
            
            $message = "<b>تنظیمات کلی ربات:</b>\n\n";
            $message .= "▫️ <b>جوین اجباری:</b> {$join_status}\n";
            $message .= "▫️ <b>زیرمجموعه‌گیری:</b> {$invite_status}\n";
            $message .= "▫️ <b>پاداش دعوت:</b> " . number_format($settings['invitation_bonus']) . " تومان\n";
            $message .= "▫️ <b>مهلت تمدید پس از انقضا:</b> {$settings['expiration_delete_days']} روز\n";
            
            $keyboard = [
                [['text' => "جوین اجباری: " . $join_status, 'callback_data' => 'toggle_force_join']],
                [['text' => '➕ مدیریت کانال‌های جوین', 'callback_data' => 'manage_join_channels']],
                [['text' => "زیرمجموعه‌گیری: " . $invite_status, 'callback_data' => 'toggle_invitation']],
                [['text' => '✏️ تنظیم پاداش', 'callback_data' => 'set_invitation_bonus']],
                [['text' => '✏️ تنظیم مهلت حذف', 'callback_data' => 'set_delete_days']],
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);

            editMessageText($chat_id, $message_id, $message, $reply_markup);
        }

        elseif ($data == 'set_delete_days' && hasPermission($conn, $from_id, 'bot_settings')) {
            setUserStep($from_id, 'set_expiration_delete_days');
            editMessageText($chat_id, $message_id, "لطفا تعداد روز مهلت پس از انقضا برای حذف سرویس را به عدد ارسال کنید (مثال: 3).\n\nاین عدد مشخص می‌کند کاربر چند روز پس از تمام شدن سرویسش برای تمدید فرصت دارد.");
        }

               elseif ($data == 'manage_join_channels' && hasPermission($conn, $from_id, 'bot_settings')) {
            $channels = $conn->query("SELECT * FROM join_channels")->fetchAll(PDO::FETCH_ASSOC);
            $message = "<b>لیست کانال‌های ثبت شده برای جوین اجباری:</b>\n\n";
            $keyboard = [];

            if (count($channels) > 0) {
                $counter = 1;
                foreach ($channels as $channel) {
                    $message .= "<b>{$counter}.</b> <b>نام:</b> {$channel['channel_name']}\n";
                    $message .= "   - <b>لینک:</b> {$channel['invite_link']}\n";
                    $keyboard[] = [['text' => "🗑 حذف " . $channel['channel_name'], 'callback_data' => 'delete_join_channel_' . $channel['id']]];
                    $counter++;
                }
            } else {
                $message .= "هیچ کانالی ثبت نشده است.";
            }

            $keyboard[] = [['text' => '➕ افزودن کانال جدید', 'callback_data' => 'add_join_channel']];
            $keyboard[] = [['text' => '🔙 بازگشت', 'callback_data' => 'bot_settings']];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            editMessageText($chat_id, $message_id, $message, $reply_markup);
        }

        elseif ($data == 'add_join_channel' && hasPermission($conn, $from_id, 'bot_settings')) {
            setUserStep($from_id, 'add_join_channel_id');
            editMessageText($chat_id, $message_id, "لطفا یوزرنیم کانال (با @) یا شناسه عددی آن را ارسال کنید.\n\n**مهم:** ربات باید با دسترسی کامل در کانال مورد نظر ادمین باشد.");
        }

        elseif (strpos($data, 'delete_join_channel_') === 0 && hasPermission($conn, $from_id, 'bot_settings')) {
            $channel_id_to_delete = str_replace('delete_join_channel_', '', $data);
            $conn->prepare("DELETE FROM join_channels WHERE id = ?")->execute([$channel_id_to_delete]);
            answerCallbackQuery($update["callback_query"]["id"], "کانال با موفقیت حذف شد.");
            
            $channels = $conn->query("SELECT * FROM join_channels")->fetchAll(PDO::FETCH_ASSOC);
            $message = "<b>لیست کانال‌های ثبت شده برای جوین اجباری:</b>\n\n";
            $keyboard = [];
            if (count($channels) > 0) {
                $counter = 1;
                foreach ($channels as $channel) {
                    $message .= "<b>{$counter}.</b> <b>نام:</b> {$channel['channel_name']}\n";
                    $message .= "   - <b>لینک:</b> {$channel['invite_link']}\n";
                    $keyboard[] = [['text' => "🗑 حذف " . $channel['channel_name'], 'callback_data' => 'delete_join_channel_' . $channel['id']]];
                    $counter++;
                }
            } else { $message .= "هیچ کانالی ثبت نشده است."; }
            $keyboard[] = [['text' => '➕ افزودن کانال جدید', 'callback_data' => 'add_join_channel']];
            $keyboard[] = [['text' => '🔙 بازگشت', 'callback_data' => 'bot_settings']];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            editMessageText($chat_id, $message_id, $message, $reply_markup);
        }
        
        elseif ($data == 'set_invitation_bonus' && hasPermission($conn, $from_id, 'bot_settings')) {
            setUserStep($from_id, 'set_invitation_bonus');
            editMessageText($chat_id, $message_id, "لطفا مبلغ پاداش دعوت را به تومان (فقط عدد) ارسال کنید:");
        }

elseif ($data == 'broadcast_menu' && hasPermission($conn, $from_id, 'broadcast')) {
            $keyboard = [
                [['text' => '✍️ ارسال پیام متنی', 'callback_data' => 'send_text_broadcast']],
                [['text' => '➡️ فوروارد پیام', 'callback_data' => 'forward_broadcast']],
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            editMessageText($chat_id, $message_id, "لطفا نوع ارسال همگانی را انتخاب کنید:", $reply_markup);
        }

        elseif ($data == 'send_text_broadcast' && hasPermission($conn, $from_id, 'broadcast')) {
            setUserStep($from_id, 'get_text_broadcast');
            editMessageText($chat_id, $message_id, "لطفا پیام متنی خود را برای ارسال به همه کاربران، ارسال کنید:");
        }

        elseif ($data == 'forward_broadcast' && hasPermission($conn, $from_id, 'broadcast')) {
            setUserStep($from_id, 'get_forward_broadcast');
            editMessageText($chat_id, $message_id, "لطفا پیامی که می‌خواهید به همه کاربران فوروارد شود را به ربات فوروارد کنید:");
        }


        elseif (strpos($data, 'reply_to_ticket_') === 0) {
            if (!hasPermission($conn, $from_id, 'support')) {
                answerCallbackQuery($update["callback_query"]["id"], "شما به این بخش دسترسی ندارید.", true);
                exit();
            }

            $ticket_id = str_replace('reply_to_ticket_', '', $data);
            
            $ticket_stmt = $conn->prepare("SELECT t.*, u.first_name as user_name FROM tickets t JOIN users u ON t.user_id = u.user_id WHERE t.id = ?");
            $ticket_stmt->execute([$ticket_id]);
            $ticket = $ticket_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ticket) {
                answerCallbackQuery($update["callback_query"]["id"], "تیکت یافت نشد.", true);
                exit();
            }

            $replies_stmt = $conn->prepare("SELECT r.*, u.first_name FROM ticket_replies r JOIN users u ON r.sender_id = u.user_id WHERE r.ticket_id = ? ORDER BY r.sent_date ASC");
            $replies_stmt->execute([$ticket_id]);
            $replies = $replies_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $message = "<b>مکالمات تیکت #{$ticket_id} (کاربر: {$ticket['user_name']})</b>\n\n";
            foreach ($replies as $reply) {
                $sender_name = isAdmin($conn, $reply['sender_id']) ? "پشتیبانی (شما)" : $reply['first_name'];
                $message .= "<b>{$sender_name}:</b>\n" . htmlspecialchars($reply['message_text']) . "\n➖➖➖➖➖➖\n";
            }

            $keyboard = [];
            if ($ticket['status'] != 'closed') {
                $keyboard[] = [['text' => '✍️ ارسال پاسخ به کاربر', 'callback_data' => 'admin_send_reply_to_' . $ticket_id]];
                $keyboard[] = [['text' => '⚫️ بستن تیکت', 'callback_data' => 'close_ticket_' . $ticket_id]];
            } else {
                 $message .= "\n<b>این تیکت بسته شده است.</b>";
            }
            $keyboard[] = [['text' => '🔙 بازگشت به لیست تیکت‌ها', 'callback_data' => 'list_open_tickets']];
            
            editMessageText($chat_id, $message_id, $message, json_encode(['inline_keyboard' => $keyboard]));
        }

      elseif (strpos($data, 'admin_send_reply_to_') === 0) {
            if (!hasPermission($conn, $from_id, 'support')) {
                answerCallbackQuery($update["callback_query"]["id"], "شما به این بخش دسترسی ندارید.", true);
                exit();
            }

            $ticket_id = str_replace('admin_send_reply_to_', '', $data);
            setUserStep($from_id, 'admin_replying_to_' . $ticket_id);
            editMessageText($chat_id, $message_id, "لطفا پاسخ خود را برای تیکت #{$ticket_id} ارسال کنید:");
        }

        elseif (strpos($data, 'close_ticket_') === 0) {
            if (!hasPermission($conn, $from_id, 'support')) {
                answerCallbackQuery($update["callback_query"]["id"], "شما اجازه بستن تیکت را ندارید.", true);
                exit();
            }

            $ticket_id = str_replace('close_ticket_', '', $data);
            $conn->prepare("UPDATE tickets SET status = 'closed' WHERE id = ?")->execute([$ticket_id]);
            
            $ticket_user_id = $conn->query("SELECT user_id FROM tickets WHERE id = $ticket_id")->fetchColumn();
            sendMessage($ticket_user_id, "تیکت پشتیبانی شما با شماره #{$ticket_id} توسط مدیریت بسته شد.");

            editMessageText($chat_id, $message_id, "✅ تیکت #{$ticket_id} با موفقیت بسته شد.");
        }

        elseif ($data == 'manage_tutorials' && hasPermission($conn, $from_id, 'manage_tutorials')) {
            $keyboard = [
                [['text' => '➕ افزودن پلتفرم جدید', 'callback_data' => 'add_tutorial']],
                [['text' => '📋 لیست راهنماها', 'callback_data' => 'list_tutorials']],
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            editMessageText($chat_id, $message_id, "بخش مدیریت راهنمای اتصال:", $reply_markup);
        }

     elseif ($data == 'list_tutorials' && hasPermission($conn, $from_id, 'manage_tutorials')) {
            $tutorials = $conn->query("SELECT id, platform_name FROM tutorials ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            $message = "<b>لیست پلتفرم‌های ثبت شده:</b>\n\n";
            $keyboard = [];
            if (count($tutorials) > 0) {
                foreach ($tutorials as $tutorial) {
                    $message .= "▫️ {$tutorial['platform_name']}\n";
                    $keyboard[] = [
                        ['text' => "✏️ ویرایش", 'callback_data' => 'edit_tutorial_' . $tutorial['id']],
                        ['text' => "🗑 حذف", 'callback_data' => 'confirm_delete_tutorial_' . $tutorial['id']]
                    ];
                }
            } else {
                $message .= "هیچ راهنمایی ثبت نشده است.";
            }
            $keyboard[] = [['text' => '🔙 بازگشت', 'callback_data' => 'manage_tutorials']];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            editMessageText($chat_id, $message_id, $message, $reply_markup);
        }

        elseif ($data == 'add_tutorial' && hasPermission($conn, $from_id, 'manage_tutorials')) {
            setUserStep($from_id, 'add_tutorial_platform');
            editMessageText($chat_id, $message_id, "لطفا نام پلتفرم را ارسال کنید (مثال: Android, iOS, Windows):");
        }
        
        elseif (strpos($data, 'confirm_delete_tutorial_') === 0 && hasPermission($conn, $from_id, 'manage_tutorials')) {
            $tutorial_id = str_replace('confirm_delete_tutorial_', '', $data);
            $keyboard = [
                [['text' => '❗️ بله، حذف کن', 'callback_data' => 'do_delete_tutorial_' . $tutorial_id]],
                [['text' => ' خیر', 'callback_data' => 'list_tutorials']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            editMessageText($chat_id, $message_id, "❓ آیا از حذف این راهنما مطمئن هستید؟", $reply_markup);
        }

        elseif (strpos($data, 'do_delete_tutorial_') === 0 && hasPermission($conn, $from_id, 'manage_tutorials')) {
            $tutorial_id = str_replace('do_delete_tutorial_', '', $data);
            $conn->prepare("DELETE FROM tutorials WHERE id = ?")->execute([$tutorial_id]);
            answerCallbackQuery($update["callback_query"]["id"], "راهنما با موفقیت حذف شد.");
            $data = 'list_tutorials';
        }

      elseif ($data == 'manage_admins' && $from_id == OWNER_ID) {
            $keyboard = [
                [['text' => '➕ افزودن ادمین', 'callback_data' => 'add_admin']],
                [['text' => '📋 لیست ادمین‌ها', 'callback_data' => 'list_admins']],
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            editMessageText($chat_id, $message_id, "بخش مدیریت ادمین‌ها:", $reply_markup);
        }
        
        elseif ($data == 'list_admins' && $from_id == OWNER_ID) {
            $admins = $conn->query("SELECT * FROM admins WHERE user_id != " . OWNER_ID)->fetchAll(PDO::FETCH_ASSOC);
            $message = "<b>لیست ادمین‌ها:</b>\n\n";
            $keyboard = [];
            if (count($admins) > 0) {
                foreach ($admins as $admin) {
                    $user_info = apiRequest('getChat', ['chat_id' => $admin['user_id']]);
                    $admin_name = $user_info['first_name'] ?? $admin['user_id'];
                    $message .= "▫️ <a href='tg://user?id={$admin['user_id']}'>{$admin_name}</a>\n";
                    $keyboard[] = [
                        ['text' => "✏️ ویرایش دسترسی", 'callback_data' => 'edit_admin_perms_' . $admin['user_id']],
                        ['text' => "🗑 حذف", 'callback_data' => 'confirm_delete_admin_' . $admin['user_id']]
                    ];
                }
            } else {
                $message .= "هیچ ادمینی (به جز شما) ثبت نشده است.";
            }
            $keyboard[] = [['text' => '🔙 بازگشت', 'callback_data' => 'manage_admins']];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            editMessageText($chat_id, $message_id, $message, $reply_markup);
        }

        elseif ($data == 'add_admin' && $from_id == OWNER_ID) {
            setUserStep($from_id, 'get_admin_id_to_add');
            editMessageText($chat_id, $message_id, "لطفا شناسه عددی کاربر مورد نظر برای ارتقا به ادمین را ارسال کنید:");
        }

        elseif (strpos($data, 'confirm_delete_admin_') === 0 && $from_id == OWNER_ID) {
            $admin_id = str_replace('confirm_delete_admin_', '', $data);
            $conn->prepare("DELETE FROM admins WHERE user_id = ?")->execute([$admin_id]);
            answerCallbackQuery($update["callback_query"]["id"], "ادمین با موفقیت حذف شد.");
            $data = 'list_admins';
        }


        elseif (strpos($data, 'edit_admin_perms_') === 0 && $from_id == OWNER_ID) {
            $admin_id = str_replace('edit_admin_perms_', '', $data);
            
            $stmt = $conn->prepare("SELECT permissions FROM admins WHERE user_id = ?");
            $stmt->execute([$admin_id]);
            $current_perms_json = $stmt->fetchColumn();
            $current_perms = json_decode($current_perms_json ?: '[]', true);
            
            $keyboard = [];
            foreach ($permissions_list as $key => $title) {
                $status_icon = (isset($current_perms[$key]) && $current_perms[$key] === true) ? '✅' : '❌';
                
                $keyboard[] = [['text' => "{$status_icon} {$title}", 'callback_data' => "toggle_perm:{$admin_id}:{$key}"]];
            }
            
            $keyboard[] = [['text' => '🔙 بازگشت به لیست', 'callback_data' => 'list_admins']];
            
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            
            editMessageText($chat_id, $message_id, "دسترسی‌های ادمین با شناسه {$admin_id} را مدیریت کنید:", $reply_markup);
        }
        
        elseif (strpos($data, 'toggle_perm:') === 0 && $from_id == OWNER_ID) {
            list(, $admin_id, $perm_key) = explode(':', $data, 3);

            $stmt = $conn->prepare("SELECT permissions FROM admins WHERE user_id = ?");
            $stmt->execute([$admin_id]);
            $current_perms = json_decode($stmt->fetchColumn() ?: '[]', true);
            
            $new_status = !(isset($current_perms[$perm_key]) && $current_perms[$perm_key]);
            $current_perms[$perm_key] = $new_status;
            
            $conn->prepare("UPDATE admins SET permissions = ? WHERE user_id = ?")->execute([json_encode($current_perms), $admin_id]);
            
            $status_text = $new_status ? 'فعال' : 'غیرفعال';
            answerCallbackQuery($update["callback_query"]["id"], "دسترسی '{$permissions_list[$perm_key]}' {$status_text} شد.");

            $new_keyboard = [];
            foreach ($permissions_list as $key => $title) {
                $status_icon = (isset($current_perms[$key]) && $current_perms[$key]) ? '✅' : '❌';
                $new_keyboard[] = [['text' => "{$status_icon} {$title}", 'callback_data' => "toggle_perm:{$admin_id}:{$key}"]];
            }
            $new_keyboard[] = [['text' => '🔙 بازگشت به لیست', 'callback_data' => 'list_admins']];
            
            $reply_markup = json_encode(['inline_keyboard' => $new_keyboard]);
            editMessageText($chat_id, $message_id, "دسترسی‌ها آپدیت شد. شناسه ادمین: {$admin_id}", $reply_markup);
        }

        elseif ($data == 'support_menu' && hasPermission($conn, $from_id, 'support')) {

            $awaiting_admin_count = $conn->query("SELECT COUNT(*) FROM tickets WHERE status = 'open' OR status = 'answered_user'")->fetchColumn();
            $awaiting_user_count = $conn->query("SELECT COUNT(*) FROM tickets WHERE status = 'answered_admin'")->fetchColumn();
            $closed_count = $conn->query("SELECT COUNT(*) FROM tickets WHERE status = 'closed'")->fetchColumn();

            $message = "به بخش مدیریت پشتیبانی خوش آمدید.\n\n";
            $message .= "لطفا دسته‌بندی تیکت‌هایی که می‌خواهید مشاهده کنید را انتخاب نمایید:";

            $keyboard = [
                [['text' => "
⚪️ منتظر پاسخ شما ({$awaiting_admin_count})", 'callback_data' => 'list_tickets_awaiting_admin']],
                [['text' => "🟢 پاسخ داده شده ({$awaiting_user_count})", 'callback_data' => 'list_tickets_awaiting_user']],
                [['text' => "⚫️ بسته شده ({$closed_count})", 'callback_data' => 'list_tickets_closed']],
                [['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            
            editMessageText($chat_id, $message_id, $message, $reply_markup);
        }

        elseif ($data == 'list_open_tickets' && hasPermission($conn, $from_id, 'support')) {
            $stmt = $conn->prepare("SELECT t.*, u.first_name FROM tickets t JOIN users u ON t.user_id = u.user_id WHERE t.status = 'open' OR t.status = 'answered_user' ORDER BY t.last_update ASC");
            $stmt->execute();
            $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($tickets) > 0) {
                $message = "<b>لیست تیکت‌های باز (منتظر پاسخ):</b>\n\n";
                $keyboard = [];
                foreach ($tickets as $ticket) {
                    $status_text = ($ticket['status'] == 'open') ? 'جدید' : 'پاسخ کاربر';
                    $message .= "▫️ تیکت #{$ticket['id']} از {$ticket['first_name']} ({$status_text})\n";
                    $keyboard[] = [['text' => "مشاهده و پاسخ به تیکت #{$ticket['id']}", 'callback_data' => 'reply_to_ticket_' . $ticket['id']]];
                }
                $keyboard[] = [['text' => '🔙 بازگشت', 'callback_data' => 'support_menu']];
                $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
                editMessageText($chat_id, $message_id, $message, $reply_markup);
            } else {
                editMessageText($chat_id, $message_id, "در حال حاضر هیچ تیکت بازی وجود ندارد.", json_encode(['inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'support_menu']]]]));
            }
        }

        elseif ($data == 'list_tickets_awaiting_admin' && hasPermission($conn, $from_id, 'support')) {
            $stmt = $conn->prepare("SELECT t.*, u.first_name FROM tickets t JOIN users u ON t.user_id = u.user_id WHERE t.status = 'open' OR t.status = 'answered_user' ORDER BY t.last_update ASC");
            $stmt->execute();
            $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($tickets) > 0) {
                $message = "<b>لیست تیکت‌های منتظر پاسخ شما:</b>\n\n";
                $keyboard = [];
                foreach ($tickets as $ticket) {
                    $status_text = ($ticket['status'] == 'open') ? 'جدید' : 'پاسخ کاربر';
                    $message .= "▫️ تیکت #{$ticket['id']} از {$ticket['first_name']} ({$status_text})\n";
                    $keyboard[] = [['text' => "مشاهده و پاسخ به تیکت #{$ticket['id']}", 'callback_data' => 'reply_to_ticket_' . $ticket['id']]];
                }
                $keyboard[] = [['text' => '🔙 بازگشت', 'callback_data' => 'support_menu']];
                $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
                editMessageText($chat_id, $message_id, $message, $reply_markup);
            } else {
                editMessageText($chat_id, $message_id, "در حال حاضر هیچ تیکتی منتظر پاسخ شما نیست.", json_encode(['inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'support_menu']]]]));
            }
        }

        elseif ($data == 'list_tickets_awaiting_user' && hasPermission($conn, $from_id, 'support')) {
            $stmt = $conn->prepare("SELECT t.*, u.first_name FROM tickets t JOIN users u ON t.user_id = u.user_id WHERE t.status = 'answered_admin' ORDER BY t.last_update DESC");
            $stmt->execute();
            $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($tickets) > 0) {
                $message = "<b>لیست تیکت‌های پاسخ داده شده (منتظر کاربر):</b>\n\n";
                $keyboard = [];
                foreach ($tickets as $ticket) {
                    $message .= "▫️ تیکت #{$ticket['id']} از {$ticket['first_name']}\n";
                    $keyboard[] = [['text' => "مشاهده تیکت #{$ticket['id']}", 'callback_data' => 'reply_to_ticket_' . $ticket['id']]];
                }
                $keyboard[] = [['text' => '🔙 بازگشت', 'callback_data' => 'support_menu']];
                $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
                editMessageText($chat_id, $message_id, $message, $reply_markup);
            } else {
                editMessageText($chat_id, $message_id, "در حال حاضر هیچ تیکتی در این دسته‌بندی وجود ندارد.", json_encode(['inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'support_menu']]]]));
            }
        }

        elseif ($data == 'list_tickets_closed' && hasPermission($conn, $from_id, 'support')) {
            $stmt = $conn->prepare("SELECT t.*, u.first_name FROM tickets t JOIN users u ON t.user_id = u.user_id WHERE t.status = 'closed' ORDER BY t.last_update DESC LIMIT 20"); // محدودیت برای جلوگیری از لیست طولانی
            $stmt->execute();
            $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($tickets) > 0) {
                $message = "<b>لیست آخرین تیکت‌های بسته شده:</b>\n\n";
                $keyboard = [];
                foreach ($tickets as $ticket) {
                    $message .= "▫️ تیکت #{$ticket['id']} از {$ticket['first_name']}\n";
                    $keyboard[] = [['text' => "مشاهده مکالمات #{$ticket['id']}", 'callback_data' => 'reply_to_ticket_' . $ticket['id']]];
                }
                $keyboard[] = [['text' => '🔙 بازگشت', 'callback_data' => 'support_menu']];
                $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
                editMessageText($chat_id, $message_id, $message, $reply_markup);
            } else {
                editMessageText($chat_id, $message_id, "در حال حاضر هیچ تیکت بسته‌ شده‌ای وجود ندارد.", json_encode(['inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'support_menu']]]]));
            }
        }


        elseif ($data == 'bot_stats' && hasPermission($conn, $from_id, 'view_stats')) {

            $total_users = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $today_users = $conn->query("SELECT COUNT(*) FROM users WHERE join_date >= CURDATE()")->fetchColumn();
            $active_services = $conn->query("SELECT COUNT(*) FROM services")->fetchColumn();
            $open_tickets = $conn->query("SELECT COUNT(*) FROM tickets WHERE status = 'open' OR status = 'answered_user'")->fetchColumn();

            $stmt = $conn->query("SELECT * FROM settings");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);


            $invitation_status = ($settings['invitation_status'] == 1) ? '✅ فعال' : '❌ غیرفعال';
            $force_join_status = ($settings['force_join_status'] == 1) ? '✅ فعال' : '❌ غیرفعال';
            $test_account_status = ($settings['test_account_status'] == 1) ? '✅ فعال' : '❌ غیرفعال';
            $balance_payment_status = ($settings['balance_payment_status'] == 1) ? '✅ فعال' : '❌ غیرفعال';
            $card_payment_status = ($settings['card_payment_status'] == 1) ? '✅ فعال' : '❌ غیرفعال';
            $charge_card_status = ($settings['charge_card_status'] == 1) ? '✅ فعال' : '❌ غیرفعال';

            $invitation_bonus = number_format($settings['invitation_bonus']) . " تومان";
            $test_traffic = $settings['test_account_traffic'] . " گیگ";
            $test_days = $settings['test_account_days'] . " روز";
            $delete_days = $settings['expiration_delete_days'] . " روز";
            
            $test_panel_name = 'انتخاب نشده';
            if (!empty($settings['test_account_panel_id'])) {
                $panel_stmt = $conn->prepare("SELECT name FROM panels WHERE id = :id");
                $panel_stmt->execute([':id' => $settings['test_account_panel_id']]);
                $panel = $panel_stmt->fetch(PDO::FETCH_ASSOC);
                if ($panel) $test_panel_name = $panel['name'];
            }
            
            $message = "📊 <b>آمار و وضعیت کلی ربات</b>\n\n";
            
            $message .= "<b>--- آمار کاربران و سرویس‌ها ---</b>\n";
            $message .= "👤 <b>کل کاربران:</b> " . number_format($total_users) . "\n";
            $message .= "📈 <b>کاربران جدید امروز:</b> " . number_format($today_users) . "\n";
            $message .= "🌐 <b>کل سرویس‌های فعال:</b> " . number_format($active_services) . "\n";
            $message .= "📞 <b>تیکت‌های باز:</b> " . number_format($open_tickets) . "\n\n";

            $message .= "<b>--- وضعیت تنظیمات ربات ---</b>\n";
            $message .= "▫️ <b>جوین اجباری:</b> {$force_join_status}\n";
            $message .= "▫️ <b>زیرمجموعه‌گیری:</b> {$invitation_status}\n";
            $message .= "▫️ <b>پاداش دعوت:</b> {$invitation_bonus}\n\n";
            
            $message .= "<b>--- وضعیت تنظیمات فروش ---</b>\n";
            $message .= "▫️ <b>خرید با موجودی:</b> {$balance_payment_status}\n";
            $message .= "▫️ <b>خرید کارت به کارت:</b> {$card_payment_status}\n";
            $message .= "▫️ <b>شارژ با کارت به کارت:</b> {$charge_card_status}\n\n";

            $message .= "<b>--- وضعیت تنظیمات اکانت تست ---</b>\n";
            $message .= "▫️ <b>اکانت تست:</b> {$test_account_status}\n";
            $message .= "▫️ <b>حجم تست:</b> {$test_traffic}\n";
            $message .= "▫️ <b>زمان تست:</b> {$test_days}\n";
            $message .= "▫️ <b>سرور تست:</b> {$test_panel_name}\n\n";
            
            $message .= "<b>--- تنظیمات دیگر ---</b>\n";
            $message .= "▫️ <b>مهلت حذف سرویس:</b> {$delete_days}\n";

            $keyboard = [
                [['text' => '🔄 بروزرسانی', 'callback_data' => 'bot_stats']],
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);

            editMessageText($chat_id, $message_id, $message, $reply_markup);
        }

        elseif (strpos($data, 'delete_panel_') === 0 && hasPermission($conn, $from_id, 'manage_panels')) {
            $panel_name_encoded = str_replace('delete_panel_', '', $data);
            $panel_name = urldecode($panel_name_encoded);

            $stmt = $conn->prepare("DELETE FROM panels WHERE name = :name");
            $stmt->bindParam(':name', $panel_name);
            if ($stmt->execute() && $stmt->rowCount() > 0) {
           
           $keyboard = [
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_to_panel_main']]
            ];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
           
                editMessageText($chat_id, $message_id, "پنل '<b>{$panel_name}</b>' با موفقیت حذف شد.", $reply_markup);
            } else {
                editMessageText($chat_id, $message_id, "خطا در حذف پنل یا پنل یافت نشد." , $reply_markup);
            }
        }
    }
}
//Unauthorized copying is strictly prohibited.
//Editing or redistributing this content without explicit permission from the Avcpanel family is forbidden.
//کپی رایت بدون اطلاع مساوی کس مادرت !!
//ادیت بدون ذکر اجازه داخل خانواده Avcpanel مساوی کس مادرت!!

?>