<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if (!checkPermission('masters','edit')) {
    exit;
}

$id = (int)$_POST['id'];
$status = $_POST['status'];

$stmt = $conn->prepare("UPDATE stores SET status=? WHERE id=?");
$stmt->bind_param("si", $status, $id);
$stmt->execute();

echo "success";