<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

header('Content-Type: application/json');

/* Optional search */
$search = trim($_GET['search'] ?? '');

$where = "WHERE status = 1";
if ($search !== '') {
    $safe = mysqli_real_escape_string($conn, $search);
    $where .= " AND (
        service_code LIKE '%$safe%' OR
        service_name LIKE '%$safe%'
    )";
}

$sql = "
    SELECT 
        id,
        service_code,
        service_name,
        amount
    FROM service_master
    $where
    ORDER BY service_name
";

$result = mysqli_query($conn, $sql);

$data = [];
while ($row = mysqli_fetch_assoc($result)) {
    $data[] = $row;
}

echo json_encode([
    'status' => 'success',
    'count'  => count($data),
    'data'   => $data
]);
