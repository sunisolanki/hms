<?php
session_start();
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if(!isset($_SESSION['user_id'])){
    header("Location:/index.php");
    exit;
}
if(!checkPermission('medical_certificate_list','view')){
    header("Location:/access_denied.php");
    exit;
}
$id=isset($_GET['id']) ? (int)$_GET['id'] : 0;
if($id<=0){
    die("Invalid Medical Certificate ID");
}
$stmt=$conn->prepare("
SELECT id,status
FROM medical_certificates
WHERE id=?
LIMIT 1
");
$stmt->bind_param("i",$id);
$stmt->execute();
$result=$stmt->get_result();
$certificate=$result->fetch_assoc();
$stmt->close();
if(!$certificate){
    die("Medical Certificate not found");
}
if($certificate['status']==='Cancelled'){
    $_SESSION['error_message']='Medical Certificate is already cancelled.';
    header('Location: medical_certificate.php');
    exit;
}
$updated_by=(int)$_SESSION['user_id'];
$status='Cancelled';
$stmt=$conn->prepare("
UPDATE medical_certificates
SET
status=?,
updated_by=?
WHERE id=?
");
$stmt->bind_param(
"sii",
$status,
$updated_by,
$id
);
$stmt->execute();
if($stmt->affected_rows!==1){
    die("Certificate status could not be updated.");
}
$stmt->close();
$_SESSION['success_message']='Medical Certificate cancelled successfully.';
header('Location: medical_certificate.php');
exit;
?>