<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$emp_id = intval($_GET['empanelment_id'] ?? 0);

if (!$emp_id) {
    echo json_encode(['status' => 'INVALID']);
    exit;
}

$stmt = $conn->prepare("
    SELECT consultation_discount_type,
           consultation_discount_value
    FROM empanelment_master
    WHERE id = ?
      AND status = 1
    LIMIT 1
");

$stmt->bind_param("i", $emp_id);
$stmt->execute();
$res = $stmt->get_result();

if ($row = $res->fetch_assoc()) {
    echo json_encode([
        'status' => 'FOUND',
        'type'   => $row['consultation_discount_type'],
        'value'  => $row['consultation_discount_value']
    ]);
} else {
    echo json_encode(['status' => 'NOT_FOUND']);
}