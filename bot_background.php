<?php
set_time_limit(0); 
date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/db_connect.php';

$botToken = "####botToken####"; 

$offsetFile = '/tmp/telegram_offset.txt'; 

echo "✅ Telegram Bot is Running in Background...\n";
echo "⚠️ Do not close this window! (Press Ctrl+C to stop)\n";
echo "--------------------------------------------------\n";

while (true) {
    $offset = file_exists($offsetFile) ? (int)file_get_contents($offsetFile) : 0;
    
    $url = "https://api.telegram.org/bot{$botToken}/getUpdates?offset={$offset}&timeout=3"; 

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $updates = json_decode($response, true);
        
        if (isset($updates['ok']) && $updates['ok'] && !empty($updates['result'])) {
            $highestId = $offset;
            
            foreach ($updates['result'] as $update) {
                $highestId = $update['update_id'] + 1;
                
                if (isset($update['message']['text'])) {
                    $text = $update['message']['text'];
                    $chatId = $update['message']['chat']['id'];
                    
                    if (strpos($text, '/status') !== false || strpos($text, 'เช็คความพร้อม') !== false) {
                        echo "[" . date("H:i:s") . "] 📩 Received command. Fetching realtime data...\n";
                        
                        $readiness = 0; $gddScore = 0; $heightScore = 0; $wurScore = 0;
                 
                        $cache_file = __DIR__ . '/sensor_realtime_cache.json';
                        if (file_exists($cache_file)) {
                            $cache_data = json_decode(file_get_contents($cache_file), true);
                            if (isset($cache_data['harvest_data'])) {
                                $readiness = (int)$cache_data['harvest_data']['readiness'];
                                $gddScore = (int)$cache_data['harvest_data']['gdd_percent'];
                                $heightScore = (int)$cache_data['harvest_data']['height_percent'];
                                $wurScore = (int)$cache_data['harvest_data']['wur_percent'];
                            }
                        }

                        $reply  = "<b>รายงานดัชนีความพร้อมเก็บเกี่ยว</b>\n\n";
                        $reply .= "🎯 <b>ดัชนีความพร้อมรวม:</b> <b>{$readiness}%</b>\n";
                        $reply .= "----------------------------------\n";
                        $reply .= "🌡 ดัชนีความร้อนสะสม (GDD): <b>{$gddScore}%</b>\n";
                        $reply .= "📏 ความสูงทรงพุ่ม: <b>{$heightScore}%</b>\n";
                        $reply .= "💧 อัตราการใช้น้ำ (WUR): <b>{$wurScore}%</b>";

                        $keyboard = ["keyboard" => [[["text" => "เช็คความพร้อม"]]], "resize_keyboard" => true, "is_persistent" => true];

                        $sendUrl = "https://api.telegram.org/bot{$botToken}/sendMessage";
                        $ch2 = curl_init();
                        curl_setopt($ch2, CURLOPT_URL, $sendUrl);
                        curl_setopt($ch2, CURLOPT_POST, true);
                        curl_setopt($ch2, CURLOPT_POSTFIELDS, ['chat_id' => $chatId, 'text' => $reply, 'parse_mode' => 'HTML', 'reply_markup' => json_encode($keyboard)]);
                        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
                        curl_exec($ch2);
                        curl_close($ch2);
                    }
                }
            }
            file_put_contents($offsetFile, $highestId); 
        }
    }
    sleep(2); 
}
?>