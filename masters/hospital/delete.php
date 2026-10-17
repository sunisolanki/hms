<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}
if (!checkPermission('hospital_master', 'delete')) {
    header('Location: /access_denied.php');
    exit;
}
if (!isset($_GET['id'])) {
    die('Invalid request');
}
$id = (int)$_GET['id'];
if ($id <= 0) {
    die('Invalid Hospital ID');
}
$oldQry = mysqli_query(
    $conn,
    "SELECT * FROM hospital_master WHERE id = $id"
);

$oldData = mysqli_fetch_assoc($oldQry);

/* Optional: permission check (enable later)
if ($_SESSION['role'] !== 'Admin' && $_SESSION['role'] !== 'Super Admin') {
    die('Access denied');
}
*/
$sql = "UPDATE hospital_master SET status = 0 WHERE id = $id";

if (!mysqli_query($conn, $sql)) {
    die('Delete failed: ' . mysqli_error($conn));
}

addAuditLog(
    'Hospital Master',
    'DELETE',
    'Hospital Deleted : '.($oldData['hospital_name'] ?? ''),
    $id,
    json_encode([
        'hospital_code' => $oldData['hospital_code'] ?? '',
        'hospital_name' => $oldData['hospital_name'] ?? '',
        'city' => $oldData['city'] ?? '',
        'mobile' => $oldData['mobile'] ?? '',
        'email' => $oldData['email'] ?? ''
    ]),
    ''
);
header("Location: hospital_list.php");
exit;
