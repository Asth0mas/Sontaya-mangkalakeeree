<?php
$servername = "localhost";
$username = "sonthaya";      
$password = "sonthaya@2026";          
$dbname = "sonthaya_db"; 

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("เชื่อมต่อฐานข้อมูลล้มเหลว: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");
?>