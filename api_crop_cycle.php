<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once 'db_connect.php';

$json_data = file_get_contents("php://input");
$data = json_decode($json_data, true);

if ($data && isset($data['action'])) {
    $action = $data['action'];
    
    $admin_id = isset($_SESSION['admin_id']) ? $_SESSION['admin_id'] : 1; 

    if ($action === 'start') {
        $start_date = $data['start_date'];
        
        $check_sql = "SELECT cycle_id FROM crop_cycle WHERE status = '1'";
        $check_result = $conn->query($check_sql);
        
        if ($check_result && $check_result->num_rows > 0) {
            echo json_encode(["status" => "error", "message" => "ไม่สามารถเริ่มรอบใหม่ได้ กรุณากด 'จบรอบการปลูก' ปัจจุบันก่อน"]);
        } else {
            $sql = "INSERT INTO crop_cycle (admin_id, start_date, status, gdd_acc) VALUES (?, ?, '1', 0)";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("is", $admin_id, $start_date);
                if ($stmt->execute()) {
                    echo json_encode(["status" => "success", "message" => "บันทึกรอบการปลูกลงฐานข้อมูลเรียบร้อย"]);
                } else {
                    echo json_encode(["status" => "error", "message" => "บันทึกล้มเหลว: " . $stmt->error]);
                }
                $stmt->close();
            } else {
                echo json_encode(["status" => "error", "message" => "SQL Error: " . $conn->error]);
            }
        }
    } 
    
    else if ($action === 'end') {
        $sql = "UPDATE crop_cycle SET status = '0' WHERE status = '1'";
        if ($conn->query($sql) === TRUE) {
            echo json_encode(["status" => "success", "message" => "จบรอบการปลูกในฐานข้อมูลเรียบร้อย"]);
        } else {
            echo json_encode(["status" => "error", "message" => "SQL Error: " . $conn->error]);
        }
    }
    
    else if ($action === 'get_active') {
        $sql = "SELECT cycle_id, start_date FROM crop_cycle WHERE status = '1' ORDER BY cycle_id DESC LIMIT 1";
        $result = $conn->query($sql);
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $current_cycle_id = $row['cycle_id'];
            $start_date = $row['start_date'];
            
            $gdd_sql = "
                SELECT SUM(GREATEST(((LEAST(max_temp, 24.0) + LEAST(min_temp, 24.0)) / 2) - 4.0, 0)) as total_gdd
                FROM (
                    SELECT DATE(record_time) as log_date, 
                           MAX(temp) as max_temp, 
                           MIN(temp) as min_temp
                    FROM sensor_log 
                    WHERE cycle_id = ? AND temp != -1
                    GROUP BY DATE(record_time)
                ) as daily_temps
            ";
            
            $stmt = $conn->prepare($gdd_sql);
            $total_gdd = 0;
            
            if ($stmt) {
                $stmt->bind_param("i", $current_cycle_id);
                $stmt->execute();
                $gdd_result = $stmt->get_result();
                if ($gdd_result && $gdd_result->num_rows > 0) {
                    $gdd_row = $gdd_result->fetch_assoc();
                    $total_gdd = $gdd_row['total_gdd'] ? (float)$gdd_row['total_gdd'] : 0;
                }
                $stmt->close();
            }

            $update_sql = "UPDATE crop_cycle SET gdd_acc = ? WHERE cycle_id = ?";
            $stmt_update = $conn->prepare($update_sql);
            if ($stmt_update) {
                $stmt_update->bind_param("di", $total_gdd, $current_cycle_id);
                $stmt_update->execute();
                $stmt_update->close();
            }

            $wur_sql = "SELECT record_time, soil_moisture FROM sensor_log WHERE cycle_id = ? AND soil_moisture != -1 ORDER BY record_time ASC";
            $stmt_wur = $conn->prepare($wur_sql);
            $max_wur = 0;
            
            if ($stmt_wur) {
                $stmt_wur->bind_param("i", $current_cycle_id);
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

                    if ($jump < -4.0) {
                        continue; 
                    }
                    
                    if ($jump > 0 && $jump < 8.0) {
                        if ($curr_soil > $mc_start_soil) {
                            $mc_start_time = $curr_time; 
                            $mc_start_soil = $curr_soil;
                        }
                        $prev_time = $curr_time;
                        $prev_soil = $curr_soil;
                        continue;
                    }

                    if ($jump >= 8.0) {
                        $t_minutes = ($prev_time - $mc_start_time) / 60;
                        $mc_end = $prev_soil; 
                        
                        if ($t_minutes > 30 && ($mc_start_soil - $mc_end) > 0) {
                            $current_wur = ($mc_start_soil - $mc_end) / $t_minutes;
                            if ($current_wur > $max_wur) {
                                $max_wur = $current_wur;
                            }
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
                        if ($current_wur > $max_wur) {
                            $max_wur = $current_wur;
                        }
                    }
                }
                $stmt_wur->close();
            }

            $wur_percent = min(100, round(($max_wur / 0.5) * 100));

            echo json_encode([
                "status" => "success", 
                "start_date" => $start_date,
                "gdd_acc" => round($total_gdd, 3),
                "wur_percent" => $wur_percent
            ]);
            
        } else {
            echo json_encode(["status" => "not_found", "message" => "ไม่มีรอบปลูกที่กำลังทำงาน"]);
        }
    }
    
    else {
        echo json_encode(["status" => "error", "message" => "Action ไม่ถูกต้อง"]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "ไม่มีข้อมูลส่งมา"]);
}

$conn->close();
?>