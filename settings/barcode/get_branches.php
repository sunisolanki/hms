<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/permissions.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo '<option value="">Select Branch</option>';
    exit;
}

if (!checkPermission('barcode_setup', 'view')) {
    http_response_code(403);
    echo '<option value="">Select Branch</option>';
    exit;
}

$hospital_id = (int)($_GET['hospital_id'] ?? 0);

echo '<option value="">Select Branch</option>';

if ($hospital_id <= 0 || !($conn instanceof mysqli)) {
    http_response_code($hospital_id <= 0 ? 400 : 500);
    exit;
}

$exists_stmt = $conn->prepare("SELECT id FROM hospital_master WHERE id = ? AND status = 1 LIMIT 1");
$hospital_ok = false;
if ($exists_stmt) {
    $exists_stmt->bind_param('i', $hospital_id);
    $exists_stmt->execute();
    $hospital_ok = ($exists_stmt->get_result()->fetch_assoc() !== null);
    $exists_stmt->close();
}

if (!$hospital_ok) {
    http_response_code(404);
    exit;
}

$stmt = $conn->prepare("
    SELECT id, branch_name
    FROM branch_master
    WHERE hospital_id = ?
      AND status = 1
    ORDER BY branch_name ASC
");

if (!$stmt) {
    http_response_code(500);
    exit;
}

$stmt->bind_param('i', $hospital_id);

if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    exit;
}

$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    echo '<option value="'
       . htmlspecialchars((string)$row['id'], ENT_QUOTES, 'UTF-8') . '">'
       . htmlspecialchars((string)$row['branch_name'], ENT_QUOTES, 'UTF-8')
       . '</option>';
}

$stmt->close();