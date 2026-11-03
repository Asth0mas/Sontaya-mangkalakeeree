<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'db_connect.php';

$start = isset($_GET['start']) ? $_GET['start'] : date('Y-m-d');
$end = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d');

$start_date = new DateTime($start);
$end_date = new DateTime($end);
$diff_days = $start_date->diff($end_date)->days;


if ($diff_days <= 1) {
    $group_sql = "DATE_FORMAT(record_time, '%Y-%m-%d %H:00:00')";
} elseif ($diff_days <= 7) {
    $group_sql = "CONCAT(DATE(record_time), ' ', LPAD(FLOOR(HOUR(record_time) / 6) * 6, 2, '0'), ':00:00')";
} else {
    $group_sql = "DATE_FORMAT(record_time, '%Y-%m-%d 00:00:00')";
}

$sql = "SELECT 
            $group_sql AS bucket_time,
            ROUND(AVG(temp), 1) as avg_temp,
            ROUND(AVG(humidity), 1) as avg_hum,
            ROUND(AVG(soil_moisture), 1) as avg_soil,
            ROUND(AVG(light_intensity), 0) as avg_light,
            ROUND(AVG(plant_height), 1) as avg_height
        FROM sensor_log 
        WHERE DATE(record_time) BETWEEN ? AND ?
        GROUP BY bucket_time 
        ORDER BY bucket_time ASC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $end_inclusive = $end . " 23:59:59";
    $stmt->bind_param("ss", $start, $end_inclusive);
    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'record_time' => $row['bucket_time'],
                'temp' => $row['avg_temp'],
                'humidity' => $row['avg_hum'],
                'soil_moisture' => $row['avg_soil'],
                'light_intensity' => $row['avg_light'],
                'plant_height' => $row['avg_height']
            ];
        }
    }
    echo json_encode(["status" => "success", "data" => $data, "days_diff" => $diff_days]);
    $stmt->close();
} else {
    echo json_encode(["status" => "error", "message" => "Database Error"]);
}
$conn->close();
?>