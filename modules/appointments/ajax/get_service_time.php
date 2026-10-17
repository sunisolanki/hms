<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

require $_SERVER['DOCUMENT_ROOT'].'/includes/public_tenant.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$serviceId = (int)($_GET['service_id'] ?? 0);

$res = mysqli_query($conn,"
    SELECT 
        sample_collection_time,
        service_start_time,
        service_close_time
    FROM service_master
    WHERE id = $serviceId
    LIMIT 1
");

$row = mysqli_fetch_assoc($res);

echo json_encode([
    'slot_minutes' => (int)($row['sample_collection_time'] ?? 0),
    'start_time'   => $row['service_start_time'],
    'close_time'   => $row['service_close_time']
]);
