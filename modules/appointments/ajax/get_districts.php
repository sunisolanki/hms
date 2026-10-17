<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/public_tenant.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$state = (int)($_GET['state_id'] ?? 0);

$res = $conn->query("SELECT id,name FROM districts WHERE state_id=$state ORDER BY name");
$data = [];

while($r = $res->fetch_assoc()){
    $data[] = $r;
}

echo json_encode($data);
