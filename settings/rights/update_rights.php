<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if (!checkPermission('rights', 'update')) {
    header('Location: /access_denied.php');
    exit;
}
$id = (int)$_POST['id'];
$name = trim($_POST['name']);
/* GET OLD DATA */
$stmt = $conn->prepare("
    SELECT name
    FROM rights_types
    WHERE id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$oldData = $result->fetch_assoc();
$result->free();
$stmt->close();

/* CHECK DUPLICATE */
$check = $conn->prepare("
    SELECT id
    FROM rights_types
    WHERE name = ?
    AND id != ?
    LIMIT 1
");
$check->bind_param("si", $name, $id);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    $check->close();
    die('Rights Type already exists');
}

$check->close();

/* UPDATE */
$stmt = $conn->prepare("
    UPDATE rights_types
    SET name = ?
    WHERE id = ?
");
$stmt->bind_param(
    "si",
    $name,
    $id
);
if ($stmt->execute()) {
    if (($oldData['name'] ?? '') != $name) {
        $oldValues = [
            'Rights Type' => $oldData['name'] ?? ''
        ];
        $newValues = [
            'Rights Type' => $name
        ];
        addAuditLog(
            'Rights Type',
            'Update',
            'Rights Type Updated : '.$name,
            $id,
            json_encode($oldValues),
            json_encode($newValues),
            'SUCCESS'
        );
    }
}
$stmt->close();
header('Location: rights.php');
exit;
?>