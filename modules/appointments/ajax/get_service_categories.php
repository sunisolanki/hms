<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

require $_SERVER['DOCUMENT_ROOT'].'/includes/public_tenant.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

header('Content-Type: application/json');

/*
 Service Categories are stored as DISTINCT category_type
*/

$sql = "
    SELECT DISTINCT
        category_type
    FROM service_master
    WHERE
        status = 1
        AND category_type IS NOT NULL
        AND category_type <> ''
    ORDER BY category_type
";

$result = mysqli_query($conn, $sql);

$data = [];
while ($row = mysqli_fetch_assoc($result)) {
    $data[] = [
        'id' => $row['category_type'],          // use category_type as ID
        'category_name' => $row['category_type']
    ];
}

echo json_encode($data);
exit;
