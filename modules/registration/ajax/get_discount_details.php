<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$id = intval($_GET['discount_id'] ?? 0);

$stmt = $conn->prepare("
    SELECT consultation_type,
           consultation_value
    FROM discount_master
    WHERE id = ?
      AND status = 'Active'
    LIMIT 1
");

$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();

if ($row = $res->fetch_assoc()) {
    echo json_encode([
        'status' => 'FOUND',
        'type'   => $row['consultation_type'],  // Percentage / Flat
        'value'  => $row['consultation_value']
    ]);
} else {
    echo json_encode(['status' => 'NOT_FOUND']);
}