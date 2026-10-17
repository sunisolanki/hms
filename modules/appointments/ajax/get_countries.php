<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/public_tenant.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$res = $conn->query("SELECT id,name FROM countries ORDER BY name");
$data = [];

while($r = $res->fetch_assoc()){
    $data[] = $r;
}

echo json_encode($data);
