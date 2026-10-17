<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/public_tenant.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

header('Content-Type: application/json');

$user_id = (int)($_GET['doctor_id'] ?? 0);

if (!$user_id) {
    echo json_encode([
        'status' => 'INVALID_USER_ID'
    ]);
    exit;
}

/* USER → DOCTOR */
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
    echo json_encode([
        'status' => 'DOCTOR_NOT_FOUND'
    ]);
    exit;
}

$stmt->close();

/* EMERGENCY CHARGE */
$stmt = $conn->prepare("
    SELECT charge_amount
    FROM doctor_charges
    WHERE doctor_id = ?
      AND visit_type = 'EMERGENCY'
      AND is_active = 1
    LIMIT 1
");

$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$stmt->bind_result($fee);

if ($stmt->fetch()) {

    echo json_encode([
        'status' => 'FOUND',
        'fee' => $fee
    ]);

} else {

    echo json_encode([
        'status' => 'NOT_FOUND'
    ]);
}

$stmt->close();
exit;