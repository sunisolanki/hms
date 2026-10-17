<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require $_SERVER['DOCUMENT_ROOT'].'/includes/public_tenant.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
header('Content-Type: application/json');

$user_id = intval($_GET['doctor_id'] ?? 0);

$response = [
    'user_id'   => $user_id,
    'doctor_id'=> null,
    'fee'       => 0,
    'status'    => 'INIT'
];

if ($user_id <= 0) {
    $response['status'] = 'INVALID_USER_ID';
    echo json_encode($response);
    exit;
}

/* ============================
   STEP 1: MAP USER → DOCTOR
============================ */
$stmt = $conn->prepare("
    SELECT id
    FROM doctor_master
    WHERE user_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($doctor_id);

if (!$stmt->fetch()) {
    $response['status'] = 'DOCTOR_MAPPING_NOT_FOUND';
    echo json_encode($response);
    exit;
}
$stmt->close();

$response['doctor_id'] = $doctor_id;

/* ============================
   STEP 2: FETCH CONSULTATION FEE
============================ */
$stmt = $conn->prepare("
    SELECT charge_amount
    FROM doctor_charges
    WHERE doctor_id = ?
      AND visit_type = 'NORMAL'
      AND is_active = 1
    LIMIT 1
");
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$stmt->bind_result($fee);

if ($stmt->fetch()) {
    $response['fee'] = $fee;
    $response['status'] = 'FOUND';
} else {
    $response['status'] = 'FEE_NOT_FOUND';
}
$stmt->close();

echo json_encode($response);
exit;
