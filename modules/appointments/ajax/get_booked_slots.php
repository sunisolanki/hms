<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require $_SERVER['DOCUMENT_ROOT'].'/includes/public_tenant.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

header('Content-Type: application/json');

/*
DB RULE:
appointments.entity_id = doctor_master.user_id
*/

$type      = $_GET['type'] ?? '';
$entity_id = (int)($_GET['entity_id'] ?? 0);
$date      = $_GET['date'] ?? '';

if ($type !== 'doctor' || !$entity_id || !$date) {
    echo json_encode([]);
    exit;
}

$sql = "
    SELECT appointment_time
    FROM appointments
    WHERE type = 'doctor'
      AND entity_id = ?
      AND appointment_date = ?
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode([
        'error' => 'Database prepare failed'
    ]);
    exit;
}

$stmt->bind_param("is", $entity_id, $date);
$stmt->execute();

$res = $stmt->get_result();

$bookedSlots = [];

while ($row = $res->fetch_assoc()) {

    $time = trim($row['appointment_time']);

    if ($time === '') {
        continue;
    }

    $time = preg_replace('/\s+/', ' ', $time);

    $bookedSlots[$time] = 'booked';
}

$stmt->close();

echo json_encode($bookedSlots);
exit;