<?php
session_start();
ini_set('display_errors',0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if(!isset($_SESSION['user_id'])){
echo json_encode([
'success'=>false,
'message'=>'Session expired.'
]);
exit;
}
$doctor_id=isset($_GET['id'])?(int)$_GET['id']:0;
if($doctor_id<=0){
echo json_encode([
'success'=>false,
'message'=>'Invalid Doctor ID.'
]);
exit;
}
$sql="
SELECT
dm.id,
dm.user_id,
dm.license_no,
u.full_name
FROM doctor_master dm
INNER JOIN users u
ON u.id=dm.user_id
WHERE dm.id=?
LIMIT 1
";
$stmt=$conn->prepare($sql);
if(!$stmt){
echo json_encode([
'success'=>false,
'message'=>'Unable to prepare doctor query.'
]);
exit;
}
$stmt->bind_param("i",$doctor_id);
$stmt->execute();
$result=$stmt->get_result();
$doctor=$result->fetch_assoc();
$stmt->close();
if(!$doctor){
echo json_encode([
'success'=>false,
'message'=>'Doctor not found.'
]);
exit;
}
echo json_encode([
'success'=>true,
'doctor'=>[
'id'=>(int)$doctor['id'],
'doctor_name'=>(string)($doctor['full_name']??''),
'full_name'=>(string)($doctor['full_name']??''),
'doctor_registration_no'=>(string)($doctor['license_no']??''),
'registration_no'=>(string)($doctor['license_no']??''),
'license_no'=>(string)($doctor['license_no']??'')
]
]);
exit;
?>