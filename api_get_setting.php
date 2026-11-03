<?php
session_start();
header('Content-Type: application/json; charset=utf-8');


require_once 'db_connect.php'; 

try {

    $sql = "SELECT * FROM system_setting WHERE setting_id = 1";
    $result = $conn->query($sql);
    
    if($result && $result->num_rows > 0) {
   
        $setting = $result->fetch_assoc();
        
        echo json_encode([
            "status" => "success",
            "data" => $setting
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "message" => "ไม่พบข้อมูลการตั้งค่าพื้นฐาน"
        ]);
    }

} catch(Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Database error: " . $e->getMessage()
    ]);
}
?>