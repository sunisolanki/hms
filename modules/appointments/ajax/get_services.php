<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

require $_SERVER['DOCUMENT_ROOT'].'/includes/public_tenant.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

header('Content-Type: application/json');

$category_type = trim($_GET['category_id'] ?? '');

if ($category_type === '') {
    echo json_encode([]);
    exit;
}

$sql = "
    SELECT
        id,
        service_name
    FROM service_master
    WHERE
        status = 1
        AND category_type = ?
    ORDER BY service_name
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $category_type);
$stmt->execute();
$res = $stmt->get_result();

$data = [];
while ($row = $res->fetch_assoc()) {
    $data[] = [
        'id'   => $row['id'],
        'name' => $row['service_name']
    ];
}

echo json_encode($data);
exit;
