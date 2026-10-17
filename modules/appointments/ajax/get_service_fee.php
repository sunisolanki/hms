<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/public_tenant.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$service_id = (int)($_GET['service_id'] ?? 0);

$res = mysqli_query($conn,"
    SELECT amount 
    FROM service_master 
    WHERE id = $service_id 
    AND status = 1 
    LIMIT 1
");

if ($row = mysqli_fetch_assoc($res)) {
    echo json_encode([
        'status' => 'FOUND',
        'fee' => $row['amount']
    ]);
} else {
    echo json_encode([
        'status' => 'NOT_FOUND',
        'fee' => 0
    ]);
}
