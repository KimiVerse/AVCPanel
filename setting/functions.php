<?php
//--------------------------------------------------------------------------------
// By KiMia
// Github AVCPANEL 
// Adminstration @amirmasoud_rsli
//--------------------------------------------------------------------------------

require_once __DIR__ . '/jdf.php';
require_once __DIR__ . '/config.php';

function apiRequest($method, $parameters) {
    if (!is_string($method)) {
        error_log("Method name must be a string\n");
        return false;
    }

    if (!$parameters) {
        $parameters = array();
    } else if (!is_array($parameters)) {
        error_log("Parameters must be an array\n");
        return false;
    }

    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;

    $handle = curl_init($url);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($handle, CURLOPT_TIMEOUT, 60);
    curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($parameters));
    $response = curl_exec($handle);

    if ($response === false) {
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        error_log("Curl returned error $errno: $error\n");
        curl_close($handle);
        return false;
    }

    $http_code = intval(curl_getinfo($handle, CURLINFO_HTTP_CODE));
    curl_close($handle);

    if ($http_code >= 500) {
        sleep(10);
        return false;
    } else if ($http_code != 200) {
        $response = json_decode($response, true);
        error_log("Request has failed with error {$response['error_code']}: {$response['description']}\n");
        if ($http_code == 401) {
            throw new Exception('Invalid access token provided');
        }
        return false;
    } else {
        $response = json_decode($response, true);
        if (isset($response['result'])) {
            return $response['result'];
        } else {
            return $response;
        }
    }
}

function sendMessage($chat_id, $text, $reply_markup = null) {
    $parameters = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    if ($reply_markup) {
        $parameters['reply_markup'] = $reply_markup;
    }
    return apiRequest('sendMessage', $parameters);
}

function getDbConnection() {
    try {
        $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->exec("set names utf8mb4");
        return $conn;
    } catch(PDOException $e) {
        error_log("Connection failed: " . $e->getMessage());
        return null;
    }
}

function editMessageText($chat_id, $message_id, $text, $reply_markup = null) {
    $parameters = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    if ($reply_markup) {
        $parameters['reply_markup'] = $reply_markup;
    }
    return apiRequest('editMessageText', $parameters);
}

function answerCallbackQuery($callback_query_id, $text = null, $show_alert = false) {
    $parameters = [
        'callback_query_id' => $callback_query_id,
    ];
    if ($text) {
        $parameters['text'] = $text;
    }
    if ($show_alert) {
        $parameters['show_alert'] = $show_alert;
    }
    return apiRequest('answerCallbackQuery', $parameters);
}

function encrypt_data($data) {
    $cipher = "AES-256-CBC";
    $ivlen = openssl_cipher_iv_length($cipher);
    $iv = openssl_random_pseudo_bytes($ivlen);
    $ciphertext = openssl_encrypt($data, $cipher, ENCRYPTION_KEY, 0, $iv);
    return base64_encode($iv . $ciphertext);
}

function decrypt_data($data) {
    $data = base64_decode($data);
    $cipher = "AES-256-CBC";
    $ivlen = openssl_cipher_iv_length($cipher);
    $iv = substr($data, 0, $ivlen);
    $ciphertext = substr($data, $ivlen);
    return openssl_decrypt($ciphertext, $cipher, ENCRYPTION_KEY, 0, $iv);
}


function checkPanelStatus($panel_base_url, $username, $password) {
    $panel_base_url = rtrim($panel_base_url, '/');
    $login_url = $panel_base_url . '/login';

    $post_data = http_build_query([
        'username' => $username,
        'password' => $password
    ]);

    $cookie_file = tempnam(sys_get_temp_dir(), 'blitz_cookie');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $login_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    curl_exec($ch);

    if (curl_errno($ch)) {
        $error_msg = 'Curl error: ' . curl_error($ch);
        curl_close($ch);
        unlink($cookie_file);
        return ['success' => false, 'error' => $error_msg];
    }

    $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if (strpos($final_url, '/login') === false) {
        $cookie_content = file_get_contents($cookie_file);
        unlink($cookie_file); 
        return ['success' => true, 'cookie' => $cookie_content];
    } else {
        unlink($cookie_file);
        return ['success' => false, 'error' => 'نام کاربری یا رمز عبور اشتباه است.'];
    }
}

function createVpnUser($panel_full_url, $api_token, $username, $traffic_gb, $days) {
    $api_url = rtrim($panel_full_url, '/') . '/api/v1/users/';
    
    $post_data = json_encode([
        "username" => $username,
        "traffic_limit" => (float)$traffic_gb,
        "unlimited" => (float)$traffic_gb == 0,
        "expiration_days" => (int)$days
    ]);

    $headers = [
        'accept: application/json',
        'Authorization: ' . $api_token,
        'Content-Type: application/json'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['success' => false, 'error' => "cURL Error: " . $curl_error];
    }

    if ($http_code == 201) {
        return ['success' => true, 'username' => $username];
    } else {
        $error_details = json_decode($response, true);
        $api_error_msg = $error_details['detail'] ?? 'خطای نامشخص از API';
        return ['success' => false, 'error' => $api_error_msg, 'code' => $http_code];
    }
}


function validateApiToken($panel_full_url, $api_token) {
    $api_url = rtrim($panel_full_url, '/') . '/api/v1/users/';

    $headers = [
        'accept: application/json',
        'Authorization: ' . $api_token,
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_HTTPGET, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200) {
        return ['success' => true];
    } else {
        $error_details = json_decode($response, true);
        return ['success' => false, 'error' => $error_details['detail'] ?? 'توکن نامعتبر یا پنل در دسترس نیست'];
    }
}

function setUserStep($user_id, $step = null, $data = null) {
    $conn = getDbConnection();
    if ($conn) {
        $stmt = $conn->prepare("UPDATE users SET step = :step, temp_data = :data WHERE user_id = :user_id");
        $stmt->bindParam(':step', $step);
        $stmt->bindParam(':data', $data);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
    }
}

function formatBytes($bytes) {
    if ($bytes == 0) return '0 B';
    $k = 1024;
    $dm = 2;
    $sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, $k));
    return sprintf("%.{$dm}f %s", $bytes / pow($k, $i), $sizes[$i]);
}


function getUserDetails($panel_full_url, $api_token, $username) {
    $api_url = rtrim($panel_full_url, '/') . '/api/v1/users/' . urlencode($username);
    $headers = ['accept: application/json', 'Authorization: ' . $api_token];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_HTTPGET, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        return ['success' => false, 'error' => "cURL Error: " . $error_msg];
    }
    
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200) {
        return ['success' => true, 'data' => json_decode($response, true)];
    } else {
        $error_details = json_decode($response, true);
        $api_error_msg = $error_details['detail'] ?? "HTTP Status Code: {$http_code}";
        return ['success' => false, 'error' => $api_error_msg];
    }
}

function getUserUri($panel_full_url, $api_token, $username) {
    $api_url = rtrim($panel_full_url, '/') . '/api/v1/users/' . urlencode($username) . '/uri';

    $headers = [ 'accept: application/json', 'Authorization: ' . $api_token ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_HTTPGET, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200) {
        return ['success' => true, 'data' => json_decode($response, true)];
    } else {
        return ['success' => false, 'error' => "خطا در دریافت لینک‌ها (Code: {$http_code})"];
    }
}


function editVpnUser($panel_full_url, $api_token, $username, $new_traffic_gb, $new_expiration_days) {
    $api_url = rtrim($panel_full_url, '/') . '/api/v1/users/' . urlencode($username);
    
    $patch_data = json_encode([
        "new_traffic_limit" => (float)$new_traffic_gb,
        "new_expiration_days" => (int)$new_expiration_days
    ]);

    $headers = [
        'accept: application/json',
        'Authorization: ' . $api_token,
        'Content-Type: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $patch_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['success' => false, 'error' => "cURL Error: " . $curl_error];
    }

    if ($http_code == 200) {
        return ['success' => true];
    } else {
        $error_details = json_decode($response, true);
        $api_error_msg = '';
        if (json_last_error() === JSON_ERROR_NONE && isset($error_details['detail'])) {
            if (is_array($error_details['detail'])) {
                foreach ($error_details['detail'] as $err) {
                    $location = implode(' -> ', $err['loc']);
                    $message = $err['msg'];
                    $api_error_msg .= "خطا در فیلد '{$location}': {$message}. ";
                }
            } else {
                $api_error_msg = $error_details['detail'];
            }
        } else {
            $api_error_msg = strip_tags($response);
        }
        if (empty(trim($api_error_msg))) {
            $api_error_msg = "پاسخ خالی با کد HTTP {$http_code}";
        }
        return ['success' => false, 'error' => trim($api_error_msg), 'code' => $http_code];
    }
}


function deleteVpnUser($panel_full_url, $api_token, $username) {
    $api_url = rtrim($panel_full_url, '/') . '/api/v1/users/' . urlencode($username);

    $headers = [
        'accept: application/json',
        'Authorization: ' . $api_token,
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['success' => false, 'error' => "cURL Error: " . $curl_error];
    }

    if ($http_code == 200) {
        return ['success' => true];
    } else {
        $error_details = json_decode($response, true);
        $error_message = $error_details['detail'] ?? strip_tags($response);
        if(empty(trim($error_message))) $error_message = "پاسخ خالی با کد HTTP {$http_code}";
        return ['success' => false, 'error' => $error_message];
    }
}



function finalizePurchaseAndCreateService($conn, $user_id, $plan_id) {
    $stmt = $conn->prepare("SELECT p.*, pn.url as panel_url, pn.api_token FROM plans p JOIN panels pn ON p.panel_id = pn.id WHERE p.id = :plan_id");
    $stmt->bindParam(':plan_id', $plan_id);
    $stmt->execute();
    $plan_info = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan_info || empty($plan_info['api_token'])) {
        return ['success' => false, 'error' => 'اطلاعات پنل یا توکن API یافت نشد.'];
    }

    $random_chars = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 5);
    $vpn_username = $user_id . $random_chars;

    $api_result = createVpnUser(
        $plan_info['panel_url'],
        $plan_info['api_token'],
        $vpn_username,
        $plan_info['traffic'],
        $plan_info['days']
    );

    if ($api_result['success']) {
        $expire_timestamp = time() + ($plan_info['days'] * 86400);
        $expiration_date_sql = date("Y-m-d H:i:s", $expire_timestamp);

        $insert_stmt = $conn->prepare("INSERT INTO services (user_id, plan_id, panel_id, vpn_username, expiration_date) VALUES (:user_id, :plan_id, :panel_id, :vpn_username, :exp_date)");
        $insert_stmt->execute([
            ':user_id' => $user_id,
            ':plan_id' => $plan_id,
            ':panel_id' => $plan_info['panel_id'],
            ':vpn_username' => $vpn_username,
            ':exp_date' => $expiration_date_sql
        ]);
        
        return ['success' => true, 'vpn_username' => $vpn_username];
    } else {
        return ['success' => false, 'error' => $api_result['error']];
    }
}






function convertToJalali($gregorian_date_string) {
    if (empty($gregorian_date_string)) {
        return 'نامشخص';
    }
    $timestamp = strtotime($gregorian_date_string);
    if ($timestamp === false) {
        return 'نامشخص';
    }
    return jdate('Y/m/d H:i', $timestamp);
}

function isUserMember($user_id, $channel_id) {
    $result = apiRequest('getChatMember', [
        'chat_id' => $channel_id,
        'user_id' => $user_id
    ]);
    
    if ($result && isset($result['status']) && in_array($result['status'], ['member', 'administrator', 'creator'])) {
        return true;
    }
    return false;
}


function getChat($chat_id) {
    return apiRequest('getChat', ['chat_id' => $chat_id]);
}

function exportChatInviteLink($chat_id) {
    return apiRequest('exportChatInviteLink', ['chat_id' => $chat_id]);
}


function isAdmin($conn, $user_id) {
    $stmt = $conn->prepare("SELECT id FROM admins WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn() ? true : false;
}

function hasPermission($conn, $user_id, $permission_key) {
    if ($user_id == OWNER_ID) return true;

    $stmt = $conn->prepare("SELECT permissions FROM admins WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $permissions_json = $stmt->fetchColumn();
    
    if ($permissions_json) {
        $permissions = json_decode($permissions_json, true);
        if (isset($permissions['all']) && $permissions['all'] === true) return true;
        if (isset($permissions[$permission_key]) && $permissions[$permission_key] === true) return true;
    }
    return false;
}

function handleStartCommand($conn, $from_id, $chat_id, $text, $first_name, $last_name, $username, $user_step) {
    $user_stmt = $conn->prepare("SELECT * FROM users WHERE user_id = :user_id");
    $user_stmt->bindParam(':user_id', $from_id);
    $user_stmt->execute();
    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $inviter_id = null;
        $parts = explode(' ', $text);
        if (count($parts) > 1 && is_numeric($parts[1])) {
            $inviter_id = $parts[1];
        }
        $insert_stmt = $conn->prepare("INSERT INTO users (user_id, first_name, last_name, username, inviter_id) VALUES (:user_id, :first_name, :last_name, :username, :inviter_id)");
        $insert_stmt->execute([':user_id' => $from_id, ':first_name' => $first_name, ':last_name' => $last_name, ':username' => $username, ':inviter_id' => $inviter_id]);
        $user_stmt->execute();
        $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    $settings_stmt = $conn->query("SELECT * FROM settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $give_invitation_bonus = function() use ($conn, $user, $settings) {
        if (!empty($user['inviter_id']) && $settings['invitation_status'] == 1) {
            $inviter_user_id = $user['inviter_id'];
            $bonus = $settings['invitation_bonus'];
            $conn->prepare("UPDATE users SET balance = balance + :bonus WHERE user_id = :user_id")->execute([':bonus' => $bonus, ':user_id' => $inviter_user_id]);
            sendMessage($inviter_user_id, "🎉 تبریک! یک کاربر جدید از طریق لینک شما عضو ربات و کانال شد و شما مبلغ " . number_format($bonus) . " تومان هدیه دریافت کردید.");
            $conn->prepare("UPDATE users SET inviter_id = NULL WHERE user_id = :user_id")->execute([':user_id' => $user['user_id']]);
        }
    };
    
    if ($settings['force_join_status'] == 1) {
        $all_channels = $conn->query("SELECT * FROM join_channels")->fetchAll(PDO::FETCH_ASSOC);
        $channels_to_join = [];
        if (!empty($all_channels)) {
            foreach ($all_channels as $channel) {
                if (!isUserMember($from_id, $channel['channel_id'])) {
                    $channels_to_join[] = $channel;
                }
            }
        }
        
        if (!empty($channels_to_join)) {
            setUserStep($from_id, 'awaiting_join');
            $message = "کاربر گرامی، برای ادامه فعالیت در ربات باید در کانال(های) زیر عضو شوید:";
            $keyboard = [];
            foreach ($channels_to_join as $channel) {
                $keyboard[] = [['text' => "عضویت در کانال {$channel['channel_name']}", 'url' => $channel['invite_link']]];
            }
            $keyboard[] = [['text' => '✅ عضویت را بررسی کن', 'callback_data' => 'check_join']];
            $reply_markup = json_encode(['inline_keyboard' => $keyboard]);
            sendMessage($chat_id, $message, $reply_markup);
            
            return;
        } else {
            if ($user['join_status'] == 0) {
                $conn->prepare("UPDATE users SET join_status = 1 WHERE user_id = :user_id")->execute([':user_id' => $from_id]);
                $give_invitation_bonus();
            }
        }
    } 
    else {
        $give_invitation_bonus();
    }
    
    if ($user_step == 'awaiting_join') {
        setUserStep($from_id, null, null);
    }
    
    $keyboard = [['خرید سرویس', 'سرویس های من'], ['حساب من', 'پشتیبانی'],['راهنما'], ['🧪 دریافت اکانت تست 🧪']];
    $reply_markup = json_encode(['keyboard' => $keyboard, 'resize_keyboard' => true, 'one_time_keyboard' => false]);
    sendMessage($chat_id, "خوش آمدید! لطفا یکی از گزینه‌های زیر را انتخاب کنید:", $reply_markup);
}









?>