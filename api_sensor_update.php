<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Bangkok'); 
require_once 'db_connect.php';

$json_data = file_get_contents("php://input");
$data = json_decode($json_data, true);

if ($data && isset($data['temp']) && isset($data['humidity'])) {
    
    $temp = floatval($data['temp']);
    $humidity = floatval($data['humidity']);
    $raw_soil = isset($data['soil_moisture']) ? floatval($data['soil_moisture']) : 0;
    $light = isset($data['light_lux']) ? floatval($data['light_lux']) : 0;
    $height = isset($data['plant_height']) ? floatval($data['plant_height']) : 0;
    
    $cache_file = 'sensor_realtime_cache.json';
    
    $last_db_insert_time = 0;
    $pump_state = 'idle';
    $pump_timer = 0;
    $fan_state = 'idle';
    $fan_timer = 0;
    
    $soil_history = [];
    $soil = $raw_soil;

    if (file_exists($cache_file)) {
        $old_cache = json_decode(file_get_contents($cache_file), true);
        $last_db_insert_time = isset($old_cache['last_db_insert']) ? $old_cache['last_db_insert'] : 0;
        $pump_state = isset($old_cache['pump_state']) ? $old_cache['pump_state'] : 'idle';
        $pump_timer = isset($old_cache['pump_timer']) ? $old_cache['pump_timer'] : 0;
        $fan_state = isset($old_cache['fan_state']) ? $old_cache['fan_state'] : 'idle';
        $fan_timer = isset($old_cache['fan_timer']) ? $old_cache['fan_timer'] : 0;
        
        if (isset($old_cache['soil_history'])) {
            $soil_history = $old_cache['soil_history'];
        }
    }
    
    if ($raw_soil > 0) {
        array_push($soil_history, $raw_soil);
        if (count($soil_history) > 30) {
            array_shift($soil_history);
        }
        $soil = round(array_sum($soil_history) / count($soil_history), 1);
    }
    
    $current_time = time(); 
    $inserted_to_db = false;
    $message = "อัปเดตข้อมูลและตรวจสอบโหมดเรียบร้อย";

    $mode_sql = "SELECT mode FROM device_status LIMIT 1";
    $mode_result = $conn->query($mode_sql);
    $current_mode = 'manual';
    if ($mode_result && $mode_result->num_rows > 0) {
        $current_mode = $mode_result->fetch_assoc()['mode'];
    }

    if ($current_mode === 'auto') {
        $set_sql = "SELECT * FROM system_setting WHERE setting_id = 1";
        $set_result = $conn->query($set_sql);
        if ($set_result && $set_result->num_rows > 0) {
            $setting = $set_result->fetch_assoc();
            
            $cooling = ($temp > (float)$setting['temp_max'] - 1 && $temp > 0) ? '1' : '0';
            $mist    = ($humidity < (float)$setting['humidity_min'] + 4 && $humidity > 0) ? '1' : '0';
            
            $pump_target = (float)$setting['soil_moisture_min'] + 3;
            $pump = '0'; 
            if ($soil < $pump_target && $soil > 0) { 
                if ($pump_state === 'idle') {
                    $pump_state = 'running';
                    $pump_timer = $current_time;
                    $pump = '1';
                } elseif ($pump_state === 'running') {
                    if ($current_time - $pump_timer >= 10) { 
                        $pump_state = 'pausing';
                        $pump_timer = $current_time;
                        $pump = '0';
                    } else {
                        $pump = '1';
                    }
                } elseif ($pump_state === 'pausing') {
                    if ($current_time - $pump_timer >= 60) { 
                        $pump_state = 'running';
                        $pump_timer = $current_time;
                        $pump = '1';
                    } else {
                        $pump = '0';
                    }
                }
            } else {
                $pump_state = 'idle';
                $pump = '0';
            }

            $cycle_days = 0;
            $cycle_sql = "SELECT start_date FROM crop_cycle WHERE status = '1' ORDER BY cycle_id DESC LIMIT 1";
            $cycle_res = $conn->query($cycle_sql);
            if ($cycle_res && $cycle_res->num_rows > 0) {
                $c_row = $cycle_res->fetch_assoc();
                $start_timestamp = strtotime($c_row['start_date']);
                $now_timestamp = time();
                $cycle_days = floor(($now_timestamp - $start_timestamp) / 86400); 
                if ($cycle_days < 0) $cycle_days = 0;
            }

            $current_hour = (int)date('H');
            
            if ($cycle_days <= 14) {
                $led_on = (int)$setting['seedling_light_on_hour'];
                $led_off = (int)$setting['seedling_light_off_hour'];
            } else {
                $led_on = (int)$setting['growth_light_on_hour'];
                $led_off = (int)$setting['growth_light_off_hour'];
            }
            
            $led = '0';
            if ($led_on <= $led_off) {
                if ($current_hour >= $led_on && $current_hour < $led_off) $led = '1';
            } else {
                if ($current_hour >= $led_on || $current_hour < $led_off) $led = '1';
            }

            $fan_target = (float)$setting['humidity_max'] - 2;
            $fan = '0'; 
            
            if ($led === '1') {
                $current_minute_total = floor($current_time / 60); 
                if (($current_minute_total % 15 < 5) || ($humidity > $fan_target && $humidity > 0)) {
                    $fan = '1';
                    $fan_state = 'running';
                } else {
                    $fan = '0';
                    $fan_state = 'idle'; 
                }
            } else {
                if ($humidity > $fan_target && $humidity > 0) { 
                    if ($fan_state === 'idle') {
                        $fan_state = 'running';
                        $fan_timer = $current_time;
                        $fan = '1';
                    } elseif ($fan_state === 'running') {
                        if ($current_time - $fan_timer >= 60) {
                            $fan_state = 'pausing';
                            $fan_timer = $current_time;
                            $fan = '0';
                        } else {
                            $fan = '1';
                        }
                    } elseif ($fan_state === 'pausing') {
                        if ($current_time - $fan_timer >= 60) {
                            $fan_state = 'running';
                            $fan_timer = $current_time;
                            $fan = '1';
                        } else {
                            $fan = '0';
                        }
                    }
                } else {
                    $fan_state = 'idle';
                    $fan = '0';
                }
            }

            $conn->query("UPDATE device_status SET status = '$cooling' WHERE device_name = 'cooling'");
            $conn->query("UPDATE device_status SET status = '$fan' WHERE device_name = 'fan'");
            $conn->query("UPDATE device_status SET status = '$mist' WHERE device_name = 'mist'");
            $conn->query("UPDATE device_status SET status = '$led' WHERE device_name = 'led'");
            $conn->query("UPDATE device_status SET status = '$pump' WHERE device_name = 'pump'");
        }
    } elseif ($current_mode === 'standby') {
         $conn->query("UPDATE device_status SET status = '0'");
         $pump_state = 'idle';
         $fan_state = 'idle';
    }

    $check_cycle = "SELECT * FROM crop_cycle WHERE status = '1' ORDER BY cycle_id DESC LIMIT 1";
    $result_cycle = $conn->query($check_cycle);
    
    $gdd_percent = 0;
    $height_percent = 0;
    $wur_percent = 0;
    $readiness = 0;

    if ($result_cycle && $result_cycle->num_rows > 0) {
        $row_c = $result_cycle->fetch_assoc();
        $cycle_id = $row_c['cycle_id'];
        
        $gdd_target = 1100.0;
        $h_target = 20.0; 
        $wur_target = 0.5; 

        $gdd_acc = (float)$row_c['gdd_acc'];
        $gdd_percent = min(100, max(0, ($gdd_acc / $gdd_target) * 100));
        
        $wur_sql = "SELECT record_time, soil_moisture FROM sensor_log WHERE cycle_id = ? AND soil_moisture != -1 ORDER BY record_time ASC";
        $stmt_wur = $conn->prepare($wur_sql);
        $max_wur = 0;
        
        if ($stmt_wur) {
            $stmt_wur->bind_param("i", $cycle_id);
            $stmt_wur->execute();
            $wur_result = $stmt_wur->get_result();
            
            $mc_start_time = null; 
            $mc_start_soil = null; 
            $prev_time = null;
            $prev_soil = null;
            
            while ($log = $wur_result->fetch_assoc()) {
                $curr_time = strtotime($log['record_time']);
                $curr_soil = (float)$log['soil_moisture'];
                
                if ($mc_start_time === null) {
                    $mc_start_time = $curr_time;
                    $mc_start_soil = $curr_soil;
                    $prev_time = $curr_time;
                    $prev_soil = $curr_soil;
                    continue;
                }
                
                $jump = $curr_soil - $prev_soil;
                if ($jump < -3.0) continue; 
                
                if ($jump > 0 && $jump < 5.0) { 
                    if ($curr_soil > $mc_start_soil) { 
                        $mc_start_time = $curr_time; 
                        $mc_start_soil = $curr_soil;
                    }
                    $prev_time = $curr_time;
                    $prev_soil = $curr_soil;
                    continue;
                }

                if ($jump >= 5.0) {
                    $t_minutes = ($prev_time - $mc_start_time) / 60;
                    $mc_end = $prev_soil; 
                    
                    if ($t_minutes > 30 && ($mc_start_soil - $mc_end) > 0) {
                        $current_wur = ($mc_start_soil - $mc_end) / $t_minutes;
                        if ($current_wur > $max_wur) $max_wur = $current_wur;
                    }
                    
                    $mc_start_time = $curr_time;
                    $mc_start_soil = $curr_soil;
                }
                
                if (($curr_time - $prev_time) / 60 > 60) {
                    $mc_start_time = $curr_time;
                    $mc_start_soil = $curr_soil;
                }
                
                $prev_time = $curr_time;
                $prev_soil = $curr_soil;
            }
            
            if ($mc_start_time !== null && $prev_time !== null) {
                $t_minutes = ($prev_time - $mc_start_time) / 60;
                $mc_end = $prev_soil;
                if ($t_minutes > 30 && ($mc_start_soil - $mc_end) > 0) {
                    $current_wur = ($mc_start_soil - $mc_end) / $t_minutes;
                    if ($current_wur > $max_wur) $max_wur = $current_wur;
                }
            }
            $stmt_wur->close();
        }

        $wur_percent = min(100, max(0, ($max_wur / $wur_target) * 100));

        $startDate = new DateTime($row_c['start_date']);
        $startDate->setTime(0, 0, 0);
        $today = new DateTime('now');
        $today->setTime(0, 0, 0);
        $days = max(0, $today->diff($startDate)->days);
        
        $maxHeight = 0;
        if (file_exists('harvest_memory.json')) {
            $hf = json_decode(file_get_contents('harvest_memory.json'), true);
            if (isset($hf['max_height'])) $maxHeight = (float)$hf['max_height'];
        }
        $h_actual = ($maxHeight > 0) ? $maxHeight : ($days / 45) * 20;
        $height_percent = min(100, max(0, ($h_actual / $h_target) * 100));

        $readiness_score = ($gdd_percent * 0.40) + ($height_percent * 0.30) + ($wur_percent * 0.30);
        $readiness = min(100, max(0, round($readiness_score)));
        
        $botToken = "8910734185:AAFbpv_vDSlIBy0qivzh6OFYnsl7cFknL1c"; 
        $chatID = "8992780220";
        $keyboard = [
            "keyboard" => [[["text" => "เช็คความพร้อม"]]],
            "resize_keyboard" => true,
            "is_persistent" => true
        ];
        
        $notifications = [];

        if ($readiness >= 90 && $readiness < 100) {
            $notifications[] = [
                'type' => '90%',
                'msg' => "แจ้งเตือนจาก Asthomas!\n\nสลัดเรดโอ๊คของคุณ (รอบที่ $cycle_id) มีความพร้อม 90%\n\nสถานะ: สามารถเก็บเกี่ยวได้แล้ว"
            ];
        }
        
        if ($readiness == 100) {
            $notifications[] = [
                'type' => '100%',
                'msg' => "แจ้งเตือนจาก Asthomas!\n\nสลัดเรดโอ๊คของคุณ (รอบที่ $cycle_id) มีความพร้อมครบ 100% แล้ว 🥬\n\nสถานะ: โปรดเข้าหน้าเว็บเพื่อกด \"จบรอบการปลูก\""
            ];
        }

        if ($days >= 45) {
            $notifications[] = [
                'type' => '45_days',
                'msg' => "แจ้งเตือนจาก Asthomas!\n\nสลัดเรดโอ๊คของคุณ (รอบที่ $cycle_id) ปลูกครบกำหนด 45 วัน (Max limit) แล้ว \n\nสถานะ: โปรดเข้าหน้าเว็บเพื่อกด \"จบรอบการปลูก\""
            ];
        }

        foreach ($notifications as $noti) {
            $search_keyword = "";
            if ($noti['type'] == '90%') $search_keyword = "%มีความพร้อม 90%%";
            else if ($noti['type'] == '100%') $search_keyword = "%มีความพร้อมครบ 100%%";
            else if ($noti['type'] == '45_days') $search_keyword = "%ปลูกครบกำหนด 45 วัน%";

            $check_sql = "SELECT notification_id FROM telegram_notification_log WHERE cycle_id = '$cycle_id' AND message LIKE '$search_keyword' LIMIT 1";
            $check_result = $conn->query($check_sql);

            if ($check_result && $check_result->num_rows == 0) {
                $sent_at = date('Y-m-d H:i:s');
                $log_sql = "INSERT INTO telegram_notification_log (cycle_id, message, send_status, sent_at) VALUES ('$cycle_id', '{$noti['msg']}', 'success', '$sent_at')";
                $conn->query($log_sql);
                $last_insert_id = $conn->insert_id;
                
                $url = "https://api.telegram.org/bot" . $botToken . "/sendMessage";
                $post_fields = [
                    'chat_id' => $chatID, 
                    'text' => $noti['msg'],
                    'reply_markup' => json_encode($keyboard)
                ];

                $ch_alert = curl_init();
                curl_setopt($ch_alert, CURLOPT_URL, $url);
                curl_setopt($ch_alert, CURLOPT_POST, true);
                curl_setopt($ch_alert, CURLOPT_POSTFIELDS, $post_fields);
                curl_setopt($ch_alert, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch_alert, CURLOPT_TIMEOUT, 2); 
                curl_setopt($ch_alert, CURLOPT_SSL_VERIFYPEER, false); 
                
                $response = curl_exec($ch_alert);
                $http_code = curl_getinfo($ch_alert, CURLINFO_HTTP_CODE);
                curl_close($ch_alert);
                
                if ($http_code != 200) {
                    $conn->query("DELETE FROM telegram_notification_log WHERE notification_id = '$last_insert_id'");
                }
            }
        }

        if ($current_time - $last_db_insert_time >= 60) {
            $sql_log = "INSERT INTO sensor_log (cycle_id, temp, humidity, soil_moisture, light_intensity, plant_height) 
                        VALUES (?, ?, ?, ?, ?, ?)";
            $stmt_log = $conn->prepare($sql_log);
            $stmt_log->bind_param("iddddd", $cycle_id, $temp, $humidity, $soil, $light, $height);
            
            if($stmt_log->execute()){
                $inserted_to_db = true;
                $last_db_insert_time = $current_time;
            }
            $stmt_log->close();
        }
    }

    $realtime_data = [
        "temp" => $temp,
        "humidity" => $humidity,
        "soil_moisture" => $soil, 
        "light_lux" => $light,
        "plant_height" => $height,
        "last_update" => date("Y-m-d H:i:s"),
        "last_db_insert" => $last_db_insert_time,
        "pump_state" => $pump_state,
        "pump_timer" => $pump_timer,
        "fan_state" => $fan_state,
        "fan_timer" => $fan_timer,
        "soil_history" => $soil_history, 
        "harvest_data" => [
            "readiness" => round($readiness),
            "gdd_percent" => round($gdd_percent),
            "height_percent" => round($height_percent),
            "wur_percent" => round($wur_percent)
        ]
    ];
    file_put_contents($cache_file, json_encode($realtime_data));
    
    echo json_encode(["status" => "success", "message" => $message, "db_inserted" => $inserted_to_db]);
    
} else {
    echo json_encode(["status" => "error", "message" => "ข้อมูลที่ส่งมาไม่ครบถ้วน"]);
}

$conn->close();
?>