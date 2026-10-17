<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/public_tenant.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$country = (int)($_GET['country_id'] ?? 0);

$res = $conn->query("SELECT id,name FROM states WHERE country_id=$country ORDER BY name");
$data = [];

while($r = $res->fetch_assoc()){
    $data[] = $r;
}

echo json_encode($data);
