<?php
ini_set('display_errors', 0); 
header('Content-Type: application/json; charset=utf-8');

$response = array("status" => "error", "message" => "เกิดข้อผิดพลาดไม่ทราบสาเหตุ");

try {
    @session_start();
    
    if (!file_exists('db_connect.php')) {
        throw new Exception("หาไฟล์ db_connect.php ไม่เจอครับ");
    }
    
    require 'db_connect.php';

    if (!isset($conn) || $conn->connect_error) {
         throw new Exception("เชื่อมต่อ Database ไม่ได้: " . ($conn->connect_error ?? "ตัวแปร conn หาย"));
    }

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $input_username = $_POST['username'] ?? '';
        $input_password = $_POST['password'] ?? '';

        $sql = "SELECT admin_id, username, password FROM admin WHERE username = ?";
        $stmt = $conn->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param("s", $input_username);
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $stmt->bind_result($db_id, $db_user, $db_pass);
            
            if ($stmt->fetch()) {

                if ($input_password === $db_pass) {
                    $_SESSION['admin_logged_in'] = true;
                    $response["status"] = "success";
                    $response["message"] = "เข้าสู่ระบบสำเร็จ";
                } else { 
                    $response["message"] = "รหัสผ่านไม่ถูกต้อง"; 
                }
            } else { 
                $response["message"] = "ไม่พบชื่อผู้ใช้ในระบบ"; 
            }
            $stmt->close();
        } else {
            throw new Exception("Prepare SQL failed: " . $conn->error);
        }
    } else {
         $response["message"] = "ไม่ใช่การส่งข้อมูลแบบ POST";
    }
    
    if(isset($conn)) $conn->close();


} catch (Exception $e) {
    $response["status"] = "error";
    $response["message"] = "PHP Exception: " . $e->getMessage();
} catch (Error $e) {
    $response["status"] = "error";
    $response["message"] = "PHP Fatal Error: " . $e->getMessage();
}

echo json_encode($response);
?>