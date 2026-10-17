<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/public_tenant.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

header('Content-Type: application/json');

$user_id    = (int)($_GET['doctor_id'] ?? 0);
$patient_id = (int)($_GET['patient_id'] ?? 0);

/*
 * Existing pages may not send appointment_date.
 * If supplied, use it.
 * Otherwise use today's date.
 */
$appointment_date = trim($_GET['appointment_date'] ?? '');

if ($appointment_date === '') {
    $appointment_date = date('Y-m-d');
}

if (!$user_id || !$patient_id) {
    echo json_encode([
        'status' => 'INVALID_INPUT'
    ]);
    exit;
}

/* =========================
   VALIDATE DATE
========================= */

$dateCheck = DateTime::createFromFormat(
    'Y-m-d',
    $appointment_date
);

if (
    !$dateCheck ||
    $dateCheck->format('Y-m-d') !== $appointment_date
) {
    echo json_encode([
        'status' => 'INVALID_DATE'
    ]);
    exit;
}

/* =========================
   STEP 1
   USER → DOCTOR MASTER
========================= */

$stmt = $conn->prepare("
    SELECT id
    FROM doctor_master
    WHERE user_id = ?
    LIMIT 1
");

if (!$stmt) {
    echo json_encode([
        'status' => 'DB_ERROR',
        'message' => $conn->error
    ]);
    exit;
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($doctor_id);

if (!$stmt->fetch()) {
    $stmt->close();

    echo json_encode([
        'status' => 'DOCTOR_NOT_FOUND'
    ]);
    exit;
}

$stmt->close();

/* =========================
   STEP 2
   FIND PREVIOUS VISIT
   SAME PATIENT + SAME DOCTOR
========================= */

$stmt = $conn->prepare("
    SELECT
        appointment_date
    FROM appointments
    WHERE patient_id = ?
      AND entity_id = ?
      AND type = 'doctor'
      AND appointment_date < ?
      AND status NOT IN ('Cancelled')
    ORDER BY appointment_date DESC, id DESC
    LIMIT 1
");

if (!$stmt) {
    echo json_encode([
        'status' => 'DB_ERROR',
        'message' => $conn->error
    ]);
    exit;
}

$stmt->bind_param(
    "iis",
    $patient_id,
    $user_id,
    $appointment_date
);

$stmt->execute();
$stmt->bind_result($previous_visit_date);

if (!$stmt->fetch()) {
    $stmt->close();

    echo json_encode([
        'status' => 'NO_PREVIOUS_VISIT',
        'fee' => '0.00',
        'days' => null,
        'previous_visit_date' => null,
        'appointment_date' => $appointment_date
    ]);
    exit;
}

$stmt->close();

/* =========================
   STEP 3
   CALCULATE DAYS
========================= */

$previousDate = new DateTime($previous_visit_date);
$currentDate  = new DateTime($appointment_date);

$days = (int)$previousDate->diff($currentDate)->days;

/* =========================
   STEP 4
   FIND MATCHING REVISIT SLAB
========================= */

$stmt = $conn->prepare("
    SELECT
        id,
        charge_amount,
        valid_from_day,
        valid_to_day
    FROM doctor_charges
    WHERE doctor_id = ?
      AND visit_type = 'REVISIT'
      AND is_active = 1
      AND valid_from_day <= ?
      AND valid_to_day >= ?
    ORDER BY valid_from_day ASC
    LIMIT 1
");

if (!$stmt) {
    echo json_encode([
        'status' => 'DB_ERROR',
        'message' => $conn->error
    ]);
    exit;
}

$stmt->bind_param(
    "iii",
    $doctor_id,
    $days,
    $days
);

$stmt->execute();

$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {

    $stmt->close();

    echo json_encode([
        'status' => 'FOUND',
        'fee' => $row['charge_amount'],
        'days' => $days,
        'previous_visit_date' => $previous_visit_date,
        'appointment_date' => $appointment_date,
        'valid_from_day' => $row['valid_from_day'],
        'valid_to_day' => $row['valid_to_day'],
        'charge_id' => $row['id']
    ]);

    exit;
}

$stmt->close();

/* =========================
   NO MATCHING SLAB
========================= */

echo json_encode([
    'status' => 'NOT_FOUND',
    'fee' => '0.00',
    'days' => $days,
    'previous_visit_date' => $previous_visit_date,
    'appointment_date' => $appointment_date
]);

exit;
?>