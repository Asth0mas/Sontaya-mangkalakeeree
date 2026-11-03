<?php
header('Content-Type: application/json; charset=utf-8');
$file = 'harvest_memory.json';
$today = date("Y-m-d");

$default_data = [
    "max_height" => 0,
    "daily_wur" => [
        "date" => $today,
        "max_wur" => 0
    ]
];

if (!file_exists($file)) {
    file_put_contents($file, json_encode($default_data));
}

$current_data = json_decode(file_get_contents($file), true);

if ($current_data['daily_wur']['date'] !== $today) {
    $current_data['daily_wur']['date'] = $today;
    $current_data['daily_wur']['max_wur'] = 0;
    file_put_contents($file, json_encode($current_data));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(["status" => "success", "data" => $current_data]);
} 
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $updated = false;

    if (isset($input['max_height']) && $input['max_height'] > $current_data['max_height']) {
        $current_data['max_height'] = (float)$input['max_height'];
        $updated = true;
    }

    if (isset($input['max_wur']) && $input['max_wur'] > $current_data['daily_wur']['max_wur']) {
        $current_data['daily_wur']['max_wur'] = (float)$input['max_wur'];
        $updated = true;
    }

    if ($updated) {
        file_put_contents($file, json_encode($current_data));
    }
    
    echo json_encode(["status" => "success", "data" => $current_data]);
}
?>