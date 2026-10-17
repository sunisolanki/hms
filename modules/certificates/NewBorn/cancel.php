<?php
session_start();
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
/* ===========================================================
   PERMISSION
=========================================================== */
checkPermission('newborn_certificate_cancel','edit');
/* ===========================================================
   REQUEST
=========================================================== */
if($_SERVER['REQUEST_METHOD']!=='POST'){
$_SESSION['error_message']='Invalid Request.';
header('Location: newborn_certificate.php');
exit;
}
/* ===========================================================
   SESSION
=========================================================== */
$user_id=(int)($_SESSION['user_id']??0);
$branch_id=(int)($_SESSION['branch_id']??0);
if($user_id<=0){
$_SESSION['error_message']='Invalid User Session.';
header('Location: newborn_certificate.php');
exit;
}
if($branch_id<=0){
$_SESSION['error_message']='Branch not found in session.';
header('Location: newborn_certificate.php');
exit;
}
/* ===========================================================
   CERTIFICATE ID
=========================================================== */
$certificate_id=isset($_POST['id'])
?(int)$_POST['id']
:0;
if($certificate_id<=0){
$_SESSION['error_message']='Invalid New Born Certificate.';
header('Location: newborn_certificate.php');
exit;
}
try{
$conn->begin_transaction();
/* ===========================================================
   GET HOSPITAL FROM BRANCH
=========================================================== */
$stmt=$conn->prepare("
SELECT hospital_id
FROM branch_master
WHERE id=?
LIMIT 1
");
$stmt->bind_param(
"i",
$branch_id
);
$stmt->execute();
$result=$stmt->get_result();
$branch=$result->fetch_assoc();
$stmt->close();
if(!$branch || empty($branch['hospital_id'])){
throw new Exception('Hospital not found for selected Branch.');
}
$hospital_id=(int)$branch['hospital_id'];
/* ===========================================================
   VERIFY CERTIFICATE
=========================================================== */
$stmt=$conn->prepare("
SELECT
id,
certificate_no,
status
FROM newborn_certificates
WHERE id=?
AND hospital_id=?
AND branch_id=?
LIMIT 1
FOR UPDATE
");
$stmt->bind_param(
"iii",
$certificate_id,
$hospital_id,
$branch_id
);
$stmt->execute();
$result=$stmt->get_result();
$certificate=$result->fetch_assoc();
$stmt->close();
if(!$certificate){
throw new Exception('New Born Certificate not found.');
}
/* ===========================================================
   ALREADY CANCELLED
=========================================================== */
if($certificate['status']==='Cancelled'){
throw new Exception('New Born Certificate is already cancelled.');
}
/* ===========================================================
   CANCEL CERTIFICATE
=========================================================== */
$status='Cancelled';
$stmt=$conn->prepare("
UPDATE newborn_certificates
SET
status=?,
updated_by=?,
updated_at=NOW()
WHERE id=?
AND hospital_id=?
AND branch_id=?
LIMIT 1
");
$stmt->bind_param(
"siiii",
$status,
$user_id,
$certificate_id,
$hospital_id,
$branch_id
);
$stmt->execute();
if($stmt->affected_rows!==1){
$stmt->close();
throw new Exception('Unable to cancel New Born Certificate.');
}
$stmt->close();
/* ===========================================================
   COMMIT
=========================================================== */
$conn->commit();
/* ===========================================================
   SUCCESS
=========================================================== */
$_SESSION['success_message']=
'New Born Certificate '.$certificate['certificate_no'].' cancelled successfully.';
header('Location: newborn_certificate.php');
exit;
}
catch(mysqli_sql_exception $e){
try{
$conn->rollback();
}catch(Throwable $rollbackError){
}
$_SESSION['error_message']='Database Error: '.$e->getMessage();
header('Location: newborn_certificate.php');
exit;
}
catch(Throwable $e){
try{
$conn->rollback();
}catch(Throwable $rollbackError){
}
$_SESSION['error_message']=$e->getMessage();
header('Location: newborn_certificate.php');
exit;
}
?>