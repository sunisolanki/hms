<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

require $_SERVER['DOCUMENT_ROOT'].'/includes/public_tenant.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

header('Content-Type: application/json');

if (!isset($_GET['department_id'])) {
    echo json_encode([]);
    exit;
}

$department_id = (int)$_GET['department_id'];

$sql = "
    SELECT DISTINCT
        u.id,
        u.full_name,
        u.username
    FROM users u
    INNER JOIN user_departments ud
        ON ud.user_id = u.id
    INNER JOIN doctor_master dm
        ON dm.user_id = u.id
    WHERE
        ud.department_id = ?
        AND u.employee_type_id = 2
        AND u.status = 1
    ORDER BY u.full_name
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode([]);
    exit;
}

$stmt->bind_param("i", $department_id);
$stmt->execute();

$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = [
        'id'   => $row['id'],
        'name' => $row['full_name']   // frontend expects "name"
    ];
}

echo json_encode($data);
exit;
