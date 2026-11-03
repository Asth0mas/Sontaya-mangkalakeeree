<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Bangkok');
require_once 'db_connect.php';

$data = json_decode(file_get_contents("php://input"), true);

$botToken = "####botToken####"; 
$chatID = "####chatID####";

$keyboard = [
    "keyboard" => [[["text" => "เช็คความพร้อม"]]],
    "resize_keyboard" => true,
    "is_persistent" => true
];
$encodedKeyboard = json_encode($keyboard);

if ($data && isset($data['readiness'])) {
    $readiness = intval($data['readiness']);

    if ($readiness >= 90) {
        $sql = "SELECT cycle_id FROM crop_cycle WHERE status = '1' ORDER BY cycle_id DESC LIMIT 1";
        $result = $conn->query($sql);

        if ($result && $result->num_rows > 0) {
            $cycle_id = $result->fetch_assoc()['cycle_id'];

            $level_text = ($readiness >= 99) ? "99%" : "90%";
            $message_text = ($readiness >= 99) ? "พร้อมเก็บเกี่ยวแล้ว " : "สามารถประเมินเก็บเกี่ยวได้แล้ว ";
            
            $search_term = "%" . $level_text . "%";
            $check_sql = "SELECT notification_id FROM telegram_notification_log WHERE cycle_id = ? AND send_status = 'success' AND message LIKE ?";
            $stmt = $conn->prepare($check_sql);
            $stmt->bind_param("is", $cycle_id, $search_term);
            $stmt->execute();
            $check_result = $stmt->get_result();

            if ($check_result->num_rows == 0) {
                $message = "แจ้งเตือนจาก Asthomas!\n\nสลัดเรดโอ๊คของคุณ (รอบที่ $cycle_id) มีความพร้อม $level_text\n\nสถานะ: $message_text";

                $url = "https://api.telegram.org/bot" . $botToken . "/sendMessage";
                $post_fields = [
                    'chat_id' => $chatID, 
                    'text' => $message,
                    'reply_markup' => $encodedKeyboard 
                ];

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
                
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $send_status = ($http_code == 200) ? 'success' : 'failed';
                $sent_at = date('Y-m-d H:i:s');

                $log_sql = "INSERT INTO telegram_notification_log (cycle_id, message, send_status, sent_at) VALUES (?, ?, ?, ?)";
                $stmt_log = $conn->prepare($log_sql);
                $stmt_log->bind_param("isss", $cycle_id, $message, $send_status, $sent_at);
                $stmt_log->execute();

                echo json_encode(["status" => "success", "message" => "ส่งแจ้งเตือน $level_text เรียบร้อย"]);
            } else {
                echo json_encode(["status" => "ignored", "message" => "เคยส่งแจ้งเตือน $level_text ของรอบนี้ไปแล้ว"]);
            }
        } else { echo json_encode(["status" => "error", "message" => "ไม่มีรอบปลูกที่กำลังทำงาน"]); }
    } else { echo json_encode(["status" => "ignored", "message" => "ความพร้อมยังไม่ถึงเกณฑ์ 90%"]); }
} 
elseif ($data && isset($data['sensor_error'])) {
    $error_msg = $data['sensor_error'];
    $message = " แจ้งเตือนฉุกเฉิน (Sensor Error)\n\nระบบพบความผิดปกติของเซ็นเซอร์ ดังนี้:\n$error_msg\n\nระบบระงับอุปกรณ์ชั่วคราวเพื่อความปลอดภัย";
    
    $url = "https://api.telegram.org/bot" . $botToken . "/sendMessage";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'chat_id' => $chatID, 
        'text' => $message,
        'reply_markup' => $encodedKeyboard 
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    curl_exec($ch);
    curl_close($ch);
    
    echo json_encode(["status" => "success", "message" => "ส่งแจ้งเตือนเซ็นเซอร์เสียเรียบร้อย"]);
}
else { echo json_encode(["status" => "error", "message" => "ข้อมูลไม่ครบถ้วน"]); }
$conn->close();
?>