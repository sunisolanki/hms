<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/public_tenant.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$patient_id = (int)($_GET['patient_id'] ?? 0);
if ($patient_id <= 0) {
    echo json_encode(['count' => 0]);
    exit;
}

$stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM appointments 
    WHERE patient_id = ?
");
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$stmt->bind_result($count);
$stmt->fetch();
$stmt->close();

echo json_encode(['count' => (int)$count]);
