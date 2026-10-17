<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
header('Content-Type: application/json');

$data = [];

/* status = 1 means Active */
$query = "SELECT id, name FROM referral_master WHERE status = 1 ORDER BY name ASC";

$result = mysqli_query($conn, $query);

if (!$result) {
    echo json_encode([
        'error' => mysqli_error($conn)
    ]);
    exit;
}

while ($row = mysqli_fetch_assoc($result)) {
    $data[] = $row;
}

echo json_encode($data);
