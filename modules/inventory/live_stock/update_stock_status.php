<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if(!isset($_SESSION['user_id'])){
    header('Location:/index.php');
    exit;
}
$id =
$_POST['id'];
$status =
$_POST['status'];
$conn->query("
UPDATE inventory_batches
SET status = '$status'
WHERE id = '$id'
");
header('Location:index.php');
exit;
?>