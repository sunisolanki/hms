<?php
session_start();
date_default_timezone_set('Asia/Kolkata');
ini_set('display_errors',1);
error_reporting(E_ALL);

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if(!isset($_SESSION['user_id'])){
header("Location:/login/login.php");
exit;
}

if(!checkPermission('ipd_admission','edit')){
header("Location:/access_denied.php");
exit;
}

$user_id=(int)$_SESSION['user_id'];

$appointment_id=(int)$_POST['appointment_id'];
$admission_id=(int)$_POST['admission_id'];
$patient_id=(int)$_POST['patient_id'];

$old_building_id=(int)$_POST['old_building_id'];
$old_floor_id=(int)$_POST['old_floor_id'];
$old_room_id=(int)$_POST['old_room_id'];
$old_bed_id=(int)$_POST['old_bed_id'];

$new_building_id=(int)$_POST['building_id'];
$new_floor_id=(int)$_POST['floor_id'];
$new_room_id=(int)$_POST['room_id'];
$new_bed_id=(int)$_POST['bed_id'];

$shift_date=$_POST['shift_date'].' '.$_POST['shift_time'].':00';
$shift_reason=trim($_POST['shift_reason']);

if($new_bed_id==0){
die("Please select Bed.");
}

if($old_bed_id==$new_bed_id){
die("Patient is already in this bed.");
}

$conn->begin_transaction();

try{

$stmt=$conn->prepare("
SELECT status
FROM bed_master
WHERE id=?
LIMIT 1
");
$stmt->bind_param("i",$new_bed_id);
$stmt->execute();

$bed=$stmt->get_result()->fetch_assoc();

if(!$bed){
throw new Exception("Bed not found.");
}

if($bed['status']!='Available'){
throw new Exception("Selected bed is not available.");
}
$stmt=$conn->prepare("
UPDATE bed_master
SET status='Available'
WHERE id=?
");
$stmt->bind_param("i",$old_bed_id);
if(!$stmt->execute()){
throw new Exception("Failed to release old bed.");
}

$stmt=$conn->prepare("
UPDATE bed_master
SET status='Occupied'
WHERE id=?
");
$stmt->bind_param("i",$new_bed_id);
if(!$stmt->execute()){
throw new Exception("Failed to occupy new bed.");
}

$stmt=$conn->prepare("
UPDATE ipd_room_allocation
SET
status='Released',
released_date=NOW()
WHERE
admission_id=?
AND status='Occupied'
");
$stmt->bind_param("i",$admission_id);
if(!$stmt->execute()){
throw new Exception("Failed to close previous room allocation.");
}

$stmt=$conn->prepare("
INSERT INTO ipd_room_allocation
(
admission_id,
building_id,
floor_id,
room_id,
bed_id,
admission_reason,
primary_diagnosis,
attendant_name,
attendant_mobile,
expected_stay,
initial_deposit,
remarks,
allocated_date,
status,
created_by
)
SELECT
admission_id,
?,
?,
?,
?,
admission_reason,
primary_diagnosis,
attendant_name,
attendant_mobile,
expected_stay,
initial_deposit,
remarks,
?,
'Occupied',
?
FROM ipd_room_allocation
WHERE id=
(
SELECT id FROM
(
SELECT id
FROM ipd_room_allocation
WHERE admission_id=?
ORDER BY id DESC
LIMIT 1
)t
)
");

$stmt->bind_param(
"iiiisii",
$new_building_id,
$new_floor_id,
$new_room_id,
$new_bed_id,
$shift_date,
$user_id,
$admission_id
);

if(!$stmt->execute()){
throw new Exception("Failed to create new room allocation.");
}

$stmt=$conn->prepare("
INSERT INTO ipd_room_shift_history
(
admission_id,
appointment_id,
patient_id,
old_building_id,
old_floor_id,
old_room_id,
old_bed_id,
new_building_id,
new_floor_id,
new_room_id,
new_bed_id,
shift_date,
shift_reason,
shifted_by
)
VALUES
(
?,?,?,?,?,?,?,?,?,?,?,?,?,?
)
");

$stmt->bind_param(
"iiiiiiiiiiissi",
$admission_id,
$appointment_id,
$patient_id,
$old_building_id,
$old_floor_id,
$old_room_id,
$old_bed_id,
$new_building_id,
$new_floor_id,
$new_room_id,
$new_bed_id,
$shift_date,
$shift_reason,
$user_id
);

if(!$stmt->execute()){
throw new Exception("Failed to save shift history.");
}
$conn->commit();

$_SESSION['success_message']="Patient shifted successfully.";

header("Location:/modules/ipd/ipd_list.php");

exit;

}catch(Exception $e){

$conn->rollback();

$stmt=$conn->prepare("
UPDATE bed_master
SET status='Occupied'
WHERE id=?
");
$stmt->bind_param("i",$old_bed_id);
$stmt->execute();

$stmt=$conn->prepare("
UPDATE bed_master
SET status='Available'
WHERE id=?
");
$stmt->bind_param("i",$new_bed_id);
$stmt->execute();

die("Shift Failed : ".$e->getMessage());

}
?>