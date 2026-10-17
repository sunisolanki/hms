<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
header('Content-Type: application/json');

$res = mysqli_query($conn,"
    SELECT id, full_name
    FROM users
    WHERE status = 'Active'
    ORDER BY full_name ASC
");

$data = [];

while($row = mysqli_fetch_assoc($res)){
    $data[] = $row;
}

echo json_encode($data);
