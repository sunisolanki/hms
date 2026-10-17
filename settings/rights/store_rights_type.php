<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if (!checkPermission('rights','create')) {
    die('Access Denied');
}
$name = trim($_POST['name'] ?? '');
if ($name === '') {
    die('Rights Type name is required');
}
/* CHECK DUPLICATE */
$check = $conn->prepare("
    SELECT id
    FROM rights_types
    WHERE name = ?
    LIMIT 1
");
$check->bind_param("s", $name);
$check->execute();
$check->store_result();
if ($check->num_rows > 0) {
    $check->close();
    die('Rights Type already exists');
}
$check->close();
/* INSERT */
$createdBy = $_SESSION['user_id'] ?? null;
$stmt = $conn->prepare("
    INSERT INTO rights_types (name, created_by)
    VALUES (?, ?)
");
$stmt->bind_param("si", $name, $createdBy);
if ($stmt->execute()) {
    $newValues = [
        'Rights Type' => $name
    ];
    addAuditLog(
        'Rights Type',
        'Create',
        'Rights Type Created : '.$name,
        $stmt->insert_id,
        '',
        json_encode($newValues),
        'SUCCESS'
    );
}
$stmt->close();
/* REDIRECT */
header("Location: rights.php");
exit;
?>