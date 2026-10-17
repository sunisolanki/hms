<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

header('Content-Type: application/json'); // 🔥 IMPORTANT

$dept = $_GET['dept'] ?? '';

$sql = "
SELECT u.id, u.full_name 
FROM users u
WHERE u.status='Active'
";

if(!empty($dept)){
    $sql .= "
    AND u.id IN (
        SELECT user_id 
        FROM user_departments 
        WHERE department_id = ".intval($dept)."
    )";
}

$sql .= " ORDER BY u.full_name";

$res = $conn->query($sql);

$data = [];

while($row = $res->fetch_assoc()){
    $data[] = $row;
}

echo json_encode($data);