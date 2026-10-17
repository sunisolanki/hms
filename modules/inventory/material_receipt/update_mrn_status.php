<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if(!isset($_SESSION['user_id'])){
header('Location:/index.php');
exit;
}
$mrn_no=$_POST['mrn_no'];
$mrn_status=$_POST['mrn_status'];
$conn->query("
UPDATE inventory_batches
SET mrn_status='$mrn_status'
WHERE mrn_no='$mrn_no'
");
header('Location:index.php');
exit;
?>