<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
header('Content-Type: application/json');

$name     = trim($_POST['name'] ?? '');
$category = trim($_POST['category'] ?? '');
$org_name = trim($_POST['org_name'] ?? '');
$mobile   = trim($_POST['mobile'] ?? '');
$city_id  = intval($_POST['city_id'] ?? 0);

if (!$name || !$category || !$city_id) {
    echo json_encode(['error' => 'Required fields missing']);
    exit;
}

$stmt = $conn->prepare("
    INSERT INTO referral_master
    (name, category, org_name, mobile, city_id)
    VALUES (?, ?, ?, ?, ?)
");

$stmt->bind_param("ssssi",
    $name,
    $category,
    $org_name,
    $mobile,
    $city_id
);

if (!$stmt->execute()) {
    echo json_encode(['error' => $stmt->error]);
    exit;
}

echo json_encode([
    'id' => $stmt->insert_id,
    'name' => $name
]);
