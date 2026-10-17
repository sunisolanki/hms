<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
/* CHECK DELETE PERMISSION */
if (!checkPermission('rights', 'delete')) {
    header('Location: /access_denied.php');
    exit;
}
/* VALIDATE ID */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: rights.php');
    exit;
}
/* GET RIGHTS TYPE DETAILS FOR AUDIT */
$stmt = $conn->prepare("
    SELECT *
    FROM rights_types
    WHERE id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();

$result = $stmt->get_result();
$rights = $result->fetch_assoc();
$result->free();

$stmt->close();
if (!$rights) {
    header('Location: rights.php');
    exit;
}
$oldValues = [
    'Rights Type' => $rights['name'] ?? ''
];
/* DELETE MODULE PERMISSIONS FIRST */
$stmt = $conn->prepare("
    DELETE FROM module_permissions
    WHERE rights_type_id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->close();
/* DELETE RIGHTS TYPE */
$stmt = $conn->prepare("
    DELETE FROM rights_types
    WHERE id = ?
");
$stmt->bind_param("i", $id);

$deleted = $stmt->execute();
$stmt->close();

if ($deleted) {

    addAuditLog(
        'Rights Type',
        'Delete',
        'Rights Type Deleted : '.($rights['name'] ?? ''),
        $id,
        json_encode($oldValues),
        '',
        'SUCCESS'
    );
}
/* REDIRECT */
header('Location: rights.php?deleted=1');
exit;
?>