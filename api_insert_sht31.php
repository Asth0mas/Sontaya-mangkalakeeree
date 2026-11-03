<?php
require_once 'db_connect.php'; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $temp = isset($_POST['temp']) ? $_POST['temp'] : 0;
    $humidity = isset($_POST['humidity']) ? $_POST['humidity'] : 0;

    $soil_moisture = 0;
    $light_intensity = 0;
    $plant_height = 0;

    $cycle_id = 1; // 
    $get_cycle_sql = "SELECT cycle_id FROM crop_cycle ORDER BY cycle_id DESC LIMIT 1";
    $result = $conn->query($get_cycle_sql);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $cycle_id = $row['cycle_id']; 
    }

    $sql = "INSERT INTO sensor_log (cycle_id, temp, humidity, soil_moisture, light_intensity, plant_height) 
            VALUES ('$cycle_id', '$temp', '$humidity', '$soil_moisture', '$light_intensity', '$plant_height')";

    if ($conn->query($sql) === TRUE) {
        echo "Record saved successfully for Cycle ID: " . $cycle_id;
    } else {
        echo "Error: " . $sql . "<br>" . $conn->error;
    }
} else {
    echo "Error: Invalid Request Method";
}

$conn->close();
?>