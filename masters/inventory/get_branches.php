<?php
require $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/permissions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([]);
    exit;
}

if (!checkPermission('masters', 'view')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([]);
    exit;
}

$hospital_id = (int)($_GET['hospital_id'] ?? $_POST['hospital_id'] ?? 0);

if ($hospital_id <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([]);
    exit;
}

if (!($conn instanceof mysqli)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([]);
    exit;
}

/* Verify the hospital belongs to this tenant (hospital_master lives in the
   tenant database, so tenant scoping is implicit in the connection). */
$exists_stmt = $conn->prepare("
    SELECT id
    FROM hospital_master
    WHERE id = ?
      AND status = 1
    LIMIT 1
");
if (!$exists_stmt) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([]);
    exit;
}
$exists_stmt->bind_param('i', $hospital_id);
$exists_stmt->execute();
$exists = $exists_stmt->get_result()->fetch_assoc();
$exists_stmt->close();

if (!$exists) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([]);
    exit;
}

$data_stmt = $conn->prepare("
    SELECT id, branch_name
    FROM branch_master
    WHERE hospital_id = ?
      AND status = 1
    ORDER BY branch_name ASC
");
if (!$data_stmt) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([]);
    exit;
}
$data_stmt->bind_param('i', $hospital_id);
$data_stmt->execute();
$res = $data_stmt->get_result();

$data = [];
while ($row = $res->fetch_assoc()) {
    $data[] = $row;
}
$data_stmt->close();

header('Content-Type: application/json; charset=utf-8');
echo json_encode($data);