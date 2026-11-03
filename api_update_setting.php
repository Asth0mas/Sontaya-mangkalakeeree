<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once 'db_connect.php'; 

$json_data = file_get_contents("php://input");
$data = json_decode($json_data, true);

if ($data) {
    $temp_min = (float)$data['temp_min'];
    $temp_max = (float)$data['temp_max'];
    $humidity_min = (float)$data['humidity_min'];
    $humidity_max = (float)$data['humidity_max'];
    $soil_min = (float)$data['soil_moisture_min'];
    $soil_max = (float)$data['soil_moisture_max'];
    
    $seed_on = (int)$data['seedling_light_on_hour'];
    $seed_off = (int)$data['seedling_light_off_hour'];
    $grow_on = (int)$data['growth_light_on_hour'];
    $grow_off = (int)$data['growth_light_off_hour'];

    $sql = "UPDATE system_setting SET 
            temp_min = ?, 
            temp_max = ?, 
            humidity_min = ?, 
            humidity_max = ?, 
            soil_moisture_min = ?, 
            soil_moisture_max = ?, 
            seedling_light_on_hour = ?, 
            seedling_light_off_hour = ?,
            growth_light_on_hour = ?,
            growth_light_off_hour = ?,
            updated_at = CURRENT_TIMESTAMP
            WHERE setting_id = 1";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ddddddiiii", 
            $temp_min, $temp_max, $humidity_min, $humidity_max, 
            $soil_min, $soil_max, $seed_on, $seed_off, $grow_on, $grow_off
        );
        
        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "บันทึกการตั้งค่าสำเร็จ"]);
        } else {
            echo json_encode(["status" => "error", "message" => "บันทึกข้อมูลล้มเหลว: " . $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(["status" => "error", "message" => "คำสั่ง SQL ผิดพลาด: " . $conn->error]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "ไม่มีข้อมูลส่งมาที่ API"]);
}

$conn->close();
?>