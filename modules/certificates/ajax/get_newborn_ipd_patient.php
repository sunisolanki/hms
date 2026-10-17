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
$branch_id=(int)($_SESSION['branch_id']??0);
$ipd_admission_id=isset($_GET['id'])?(int)$_GET['id']:0;
if($branch_id<=0){
echo json_encode([
'success'=>false,
'message'=>'Branch not found in session.'
]);
exit;
}
if($ipd_admission_id<=0){
echo json_encode([
'success'=>false,
'message'=>'Invalid IPD admission.'
]);
exit;
}
/* ===========================================================
   GET HOSPITAL FROM BRANCH
=========================================================== */
$stmt=$conn->prepare("
SELECT hospital_id
FROM branch_master
WHERE id=?
LIMIT 1
");
$stmt->bind_param("i",$branch_id);
$stmt->execute();
$branch_result=$stmt->get_result();
$branch=$branch_result->fetch_assoc();
$stmt->close();
if(!$branch || (int)$branch['hospital_id']<=0){
echo json_encode([
'success'=>false,
'message'=>'Hospital not found for this branch.'
]);
exit;
}
$hospital_id=(int)$branch['hospital_id'];
/* ===========================================================
   GET FEMALE ADMITTED IPD PATIENT
=========================================================== */
$sql="
SELECT
ia.id AS ipd_admission_id,
ia.patient_id,
ia.ipd_no,
ia.doctor_id,
ia.department_id,
ia.admission_date,
ia.admission_time,
ia.status AS admission_status,
pm.uhid,
pm.title,
pm.first_name,
pm.last_name,
pm.guardian_name,
pm.mobile,
pm.dob,
pm.age_y,
pm.age_m,
pm.age_d,
pm.sex,
pm.marital_status,
pm.address,
dep.department_name,
u.full_name AS doctor_name,
dm.license_no AS doctor_registration_no,
ira.id AS room_allocation_id,
ira.room_id,
ira.bed_id,
ira.allocated_date,
rm.room_number,
rm.room_name,
rm.room_type,
bm.bed_number,
bm.bed_type
FROM ipd_admissions ia
INNER JOIN patient_master pm
ON pm.id=ia.patient_id
LEFT JOIN department_master dep
ON dep.id=ia.department_id
LEFT JOIN users u
ON u.id=ia.doctor_id
LEFT JOIN doctor_master dm
ON dm.user_id=ia.doctor_id
LEFT JOIN ipd_room_allocation ira
ON ira.admission_id=ia.id
AND ira.status='Occupied'
LEFT JOIN room_master rm
ON rm.id=ira.room_id
LEFT JOIN bed_master bm
ON bm.id=ira.bed_id
WHERE ia.id=?
AND ia.hospital_id=?
AND ia.branch_id=?
AND ia.status='Admitted'
AND LOWER(TRIM(pm.sex))='female'
ORDER BY ira.id DESC
LIMIT 1
";
$stmt=$conn->prepare($sql);
if(!$stmt){
echo json_encode([
'success'=>false,
'message'=>'Unable to prepare IPD patient query.'
]);
exit;
}
$stmt->bind_param(
"iii",
$ipd_admission_id,
$hospital_id,
$branch_id
);
$stmt->execute();
$result=$stmt->get_result();
$row=$result->fetch_assoc();
$stmt->close();
if(!$row){
echo json_encode([
'success'=>false,
'message'=>'Female admitted IPD patient not found.'
]);
exit;
}
/* ===========================================================
   MOTHER NAME
=========================================================== */
$mother_name=trim(
(string)($row['title']??'').' '.
(string)($row['first_name']??'').' '.
(string)($row['last_name']??'')
);
/* ===========================================================
   AGE DISPLAY
=========================================================== */
$age_parts=[];
if($row['age_y']!==null){
$age_parts[]=(int)$row['age_y'].'Y';
}
if($row['age_m']!==null){
$age_parts[]=(int)$row['age_m'].'M';
}
if($row['age_d']!==null){
$age_parts[]=(int)$row['age_d'].'D';
}
$age=implode(' ',$age_parts);
/* ===========================================================
   ROOM / WARD DISPLAY
=========================================================== */
$room_parts=[];
if(!empty($row['room_type'])){
$room_parts[]=$row['room_type'];
}
if(!empty($row['room_name'])){
$room_parts[]=$row['room_name'];
}
if(!empty($row['room_number'])){
$room_parts[]='Room '.$row['room_number'];
}
$room_ward_type=implode(' - ',$room_parts);
/* ===========================================================
   BED DISPLAY
=========================================================== */
$bed_parts=[];
if(!empty($row['bed_number'])){
$bed_parts[]='Bed '.$row['bed_number'];
}
if(!empty($row['bed_type'])){
$bed_parts[]=$row['bed_type'];
}
$bed_detail=implode(' - ',$bed_parts);
/* ===========================================================
   RESPONSE
=========================================================== */
echo json_encode([
'success'=>true,
'patient'=>[
'ipd_admission_id'=>(int)$row['ipd_admission_id'],
'patient_id'=>(int)$row['patient_id'],
'ipd_no'=>(string)($row['ipd_no']??''),
'uhid'=>(string)($row['uhid']??''),
'mother_name'=>$mother_name,
'guardian_name'=>(string)($row['guardian_name']??''),
'mobile'=>(string)($row['mobile']??''),
'dob'=>(string)($row['dob']??''),
'age_y'=>$row['age_y']!==null?(int)$row['age_y']:'',
'age_m'=>$row['age_m']!==null?(int)$row['age_m']:'',
'age_d'=>$row['age_d']!==null?(int)$row['age_d']:'',
'age'=>$age,
'sex'=>(string)($row['sex']??''),
'marital_status'=>(string)($row['marital_status']??''),
'address'=>(string)($row['address']??''),
'admission_date'=>(string)($row['admission_date']??''),
'admission_time'=>(string)($row['admission_time']??''),
'admission_status'=>(string)($row['admission_status']??''),
'department_id'=>(int)($row['department_id']??0),
'department_name'=>(string)($row['department_name']??''),
'doctor_id'=>(int)($row['doctor_id']??0),
'doctor_name'=>(string)($row['doctor_name']??''),
'doctor_registration_no'=>(string)($row['doctor_registration_no']??''),
'room_allocation_id'=>(int)($row['room_allocation_id']??0),
'room_id'=>(int)($row['room_id']??0),
'bed_id'=>(int)($row['bed_id']??0),
'room_ward_type'=>$room_ward_type,
'bed_detail'=>$bed_detail
]
],JSON_UNESCAPED_UNICODE);
exit;
?>