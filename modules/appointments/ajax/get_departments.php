<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

require $_SERVER['DOCUMENT_ROOT'].'/includes/public_tenant.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

header('Content-Type: application/json');

$type = $_GET['type'] ?? '';

if (!in_array($type, ['doctor','service'])) {
    echo json_encode([]);
    exit;
}

/*
 For now both Doctor & Service use same departments.
 If later services use separate departments, logic can be split.
*/

$sql = "SELECT id, department_name 
        FROM department_master 
        WHERE status = 1 
        ORDER BY department_name";

$res = mysqli_query($conn, $sql);

$data = [];
while ($row = mysqli_fetch_assoc($res)) {
    $data[] = $row;
}

echo json_encode($data);
