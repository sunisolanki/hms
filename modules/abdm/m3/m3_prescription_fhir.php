<?php
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/m3_prescription_fhir_lib.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Kolkata');

$prescriptionId = isset($_GET['prescription_id']) ? (int) $_GET['prescription_id'] : 0;
$appointmentId = isset($_GET['appointment_id']) ? (int) $_GET['appointment_id'] : 0;

if ($prescriptionId <= 0 && $appointmentId <= 0) {
    echo json_encode(
        m3_prescription_fhir_build_bundle($conn, 0, 0),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

$result = m3_prescription_fhir_build_bundle($conn, $prescriptionId, $appointmentId);

echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
