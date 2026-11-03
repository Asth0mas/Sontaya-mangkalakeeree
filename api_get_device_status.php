<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'db_connect.php';

$sql = "SELECT device_name, mode, status FROM device_status";
$result = $conn->query($sql);

$devices = [];
$current_mode = 'auto'; 

if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $devices[$row['device_name']] = $row['status'];
        $current_mode = $row['mode'];
    }
    echo json_encode([
        "status" => "success", 
        "mode" => $current_mode, 
        "devices" => $devices
    ]);
} else {
    echo json_encode(["status" => "error", "message" => "ไม่พบข้อมูลอุปกรณ์"]);
}

$conn->close();
?>