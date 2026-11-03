<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once 'db_connect.php'; 

$json_data = file_get_contents("php://input");
$data = json_decode($json_data, true);

if ($data && isset($data['action'])) {
    $action = $data['action'];

    if ($action === 'set_mode') {
        $mode = $data['mode']; 
        
        if ($mode === 'standby') {
            $sql = "UPDATE device_status SET mode = ?, status = '0', updated_at = NOW()";
        } else {
            
            $sql = "UPDATE device_status SET mode = ?, updated_at = NOW()";
        }
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("s", $mode);
            $stmt->execute();
            echo json_encode(["status" => "success", "message" => "เปลี่ยนโหมดระบบเป็น $mode สำเร็จ"]);
            $stmt->close();
        } else {
            echo json_encode(["status" => "error", "message" => "SQL Error: " . $conn->error]);
        }
    }
    
    else if ($action === 'set_toggle') {
        $device_name = $data['device_name']; 
        $status = (string)$data['status'];   
      
        $sql = "UPDATE device_status SET status = ?, updated_at = NOW() WHERE device_name = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ss", $status, $device_name);
            $stmt->execute();
            $status_text = ($status === '1') ? 'เปิด' : 'ปิด';
            echo json_encode(["status" => "success", "message" => "สั่ง $status_text $device_name สำเร็จ"]);
            $stmt->close();
        } else {
            echo json_encode(["status" => "error", "message" => "SQL Error: " . $conn->error]);
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