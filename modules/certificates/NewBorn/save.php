<?php
session_start();
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
checkPermission('newborn_certificate_add','add');
if($_SERVER['REQUEST_METHOD']!=='POST'){
$_SESSION['error_message']='Invalid Request.';
header('Location: newborn_certificate.php');
exit;
}
/* ===========================================================
   HELPERS
=========================================================== */
function postText($key){
return trim((string)($_POST[$key]??''));
}
function nullableText($key){
$value=trim((string)($_POST[$key]??''));
return $value===''?null:$value;
}
function nullableInt($key){
if(!isset($_POST[$key]) || $_POST[$key]===''){
return null;
}
$value=(int)$_POST[$key];
return $value>0?$value:null;
}
function nullableDecimal($key){
if(!isset($_POST[$key]) || trim((string)$_POST[$key])===''){
return null;
}
return (float)$_POST[$key];
}
function failSave($message){
$_SESSION['error_message']=$message;
header('Location: newborn_certificate.php');
exit;
}
/* ===========================================================
   SESSION
=========================================================== */
$user_id=(int)($_SESSION['user_id']??0);
$branch_id=(int)($_SESSION['branch_id']??0);
if($user_id<=0){
failSave('Invalid User Session.');
}
if($branch_id<=0){
failSave('Branch not found in session.');
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
if(!$stmt){
failSave('Unable to verify Branch: '.$conn->error);
}
$stmt->bind_param("i",$branch_id);
$stmt->execute();
$branch=$stmt->get_result()->fetch_assoc();
$stmt->close();
if(!$branch || (int)$branch['hospital_id']<=0){
failSave('Hospital not found for selected Branch.');
}
$hospital_id=(int)$branch['hospital_id'];
/* ===========================================================
   FORM VALUES
=========================================================== */
$certificate_no=postText('certificate_no');
$certificate_date=postText('certificate_date');
$ipd_admission_id=nullableInt('ipd_admission_id');
$delivery_type=postText('delivery_type');
$patient_status=nullableText('patient_status');
$last_child_age=nullableText('last_child_age');
$first_pregnancy=isset($_POST['first_pregnancy'])?(int)$_POST['first_pregnancy']:0;
$anaesthesia=isset($_POST['anaesthesia'])?(int)$_POST['anaesthesia']:0;
$first_pregnancy=$first_pregnancy===1?1:0;
$anaesthesia=$anaesthesia===1?1:0;
$lead_surgeon_id=nullableInt('lead_surgeon_id');
$second_surgeon_id=nullableInt('second_surgeon_id');
$assistant_id=nullableInt('assistant_id');
$born_child=isset($_POST['born_child'])?(int)$_POST['born_child']:0;
$alive_child=isset($_POST['alive_child'])?(int)$_POST['alive_child']:0;
$still_born_count=isset($_POST['still_born_count'])?(int)$_POST['still_born_count']:0;
$baby_gender=postText('baby_gender');
$baby_weight=nullableDecimal('baby_weight');
$baby_length=nullableDecimal('baby_length');
$delivery_date=postText('delivery_date');
$delivery_time=postText('delivery_time');
$remarks=nullableText('remarks');
/* ===========================================================
   CONDITION AT BIRTH
=========================================================== */
$oxygen_required=isset($_POST['oxygen_required'])?1:0;
$jaundice=isset($_POST['jaundice'])?1:0;
$oxygen_duration=$oxygen_required===1?nullableText('oxygen_duration'):null;
$apgar_1_min=nullableText('apgar_1_min');
$apgar_5_min=nullableText('apgar_5_min');
$head_shape=nullableText('head_shape');
/* ===========================================================
   VALIDATION
=========================================================== */
if($certificate_no===''){
failSave('Certificate Number is required.');
}
if($certificate_date===''){
failSave('Certificate Date is required.');
}
if($ipd_admission_id===null){
failSave('Please select a Female Admitted IPD Patient.');
}
if($delivery_type===''){
failSave('Delivery Type is required.');
}
if($patient_status===null){
failSave('Patient Status is required.');
}
if($born_child<=0){
failSave('Born Child must be at least 1.');
}
if($alive_child<0){
failSave('Alive Child cannot be negative.');
}
if($still_born_count<0){
failSave('Still Born / Intrapartum Death cannot be negative.');
}
if(($alive_child+$still_born_count)>$born_child){
failSave('Alive Child + Still Born cannot be greater than Born Child.');
}
if(!in_array($baby_gender,['Male','Female','Other'],true)){
failSave('Please select Baby Gender.');
}
if($baby_weight===null || $baby_weight<=0){
failSave('Baby Weight is required.');
}
if($baby_length===null || $baby_length<=0){
failSave('Baby Length is required.');
}
if($delivery_date===''){
failSave('Delivery Date is required.');
}
if($delivery_time===''){
failSave('Delivery Time is required.');
}
if($oxygen_required===1 && $oxygen_duration===null){
failSave('Please enter how long Oxygen was required.');
}
/* ===========================================================
   APGAR VALIDATION
=========================================================== */
if($apgar_1_min!==null){
if(!is_numeric($apgar_1_min) || (float)$apgar_1_min<0 || (float)$apgar_1_min>10){
failSave('1 Minute APGAR must be between 0 and 10.');
}
}
if($apgar_5_min!==null){
if(!is_numeric($apgar_5_min) || (float)$apgar_5_min<0 || (float)$apgar_5_min>10){
failSave('5 Minute APGAR must be between 0 and 10.');
}
}
/* ===========================================================
   VERIFY CERTIFICATE NUMBER
=========================================================== */
$stmt=$conn->prepare("
SELECT id
FROM newborn_certificates
WHERE certificate_no=?
LIMIT 1
");
$stmt->bind_param("s",$certificate_no);
$stmt->execute();
$duplicate=$stmt->get_result()->fetch_assoc();
$stmt->close();
if($duplicate){
failSave('Certificate Number already exists. Please reopen the form.');
}
/* ===========================================================
   VERIFY FEMALE + CURRENTLY ADMITTED IPD PATIENT
   AUTHORITATIVE PATIENT / IPD DETAILS
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
ira.room_id,
ira.bed_id,
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
failSave('Unable to verify IPD Patient: '.$conn->error);
}
$stmt->bind_param(
"iii",
$ipd_admission_id,
$hospital_id,
$branch_id
);
$stmt->execute();
$mother=$stmt->get_result()->fetch_assoc();
$stmt->close();
if(!$mother){
failSave('Selected patient is not a Female currently Admitted IPD Patient.');
}
/* ===========================================================
   AUTHORITATIVE MOTHER DETAILS
=========================================================== */
$patient_id=(int)$mother['patient_id'];
$ipd_no=trim((string)$mother['ipd_no']);
$uhid=$mother['uhid']!==null?(int)$mother['uhid']:null;
$mother_name=trim(
(string)($mother['title']??'').' '.
(string)($mother['first_name']??'').' '.
(string)($mother['last_name']??'')
);
$guardian_name=trim((string)($mother['guardian_name']??''));
$guardian_name=$guardian_name===''?null:$guardian_name;
$mobile=trim((string)($mother['mobile']??''));
$mobile=$mobile===''?null:$mobile;
$dob=trim((string)($mother['dob']??''));
$dob=$dob===''?null:$dob;
$age_y=$mother['age_y']!==null?(int)$mother['age_y']:null;
$age_m=$mother['age_m']!==null?(int)$mother['age_m']:null;
$age_d=$mother['age_d']!==null?(int)$mother['age_d']:null;
$age_parts=[];
if($age_y!==null){
$age_parts[]=$age_y.'Y';
}
if($age_m!==null){
$age_parts[]=$age_m.'M';
}
if($age_d!==null){
$age_parts[]=$age_d.'D';
}
$age=count($age_parts)?implode(' ',$age_parts):null;
$sex=trim((string)($mother['sex']??''));
$sex=$sex===''?null:$sex;
$marital_status=trim((string)($mother['marital_status']??''));
$marital_status=$marital_status===''?null:$marital_status;
$address=trim((string)($mother['address']??''));
$address=$address===''?null:$address;
$admission_date=trim((string)($mother['admission_date']??''));
$admission_date=$admission_date===''?null:$admission_date;
$admission_time=trim((string)($mother['admission_time']??''));
$admission_time=$admission_time===''?null:$admission_time;
$department_id=$mother['department_id']!==null?(int)$mother['department_id']:null;
$department_name=trim((string)($mother['department_name']??''));
$department_name=$department_name===''?null:$department_name;
/*
IPD stores users.id in doctor_id.
newborn_certificates.doctor_id will therefore keep the same
doctor identifier used by this IPD admission.
*/
$doctor_id=$mother['doctor_id']!==null?(int)$mother['doctor_id']:null;
$doctor_name=trim((string)($mother['doctor_name']??''));
$doctor_name=$doctor_name===''?null:$doctor_name;
$doctor_registration_no=trim((string)($mother['doctor_registration_no']??''));
$doctor_registration_no=$doctor_registration_no===''?null:$doctor_registration_no;
/* ===========================================================
   ROOM / WARD
=========================================================== */
$room_parts=[];
if(!empty($mother['room_type'])){
$room_parts[]=trim((string)$mother['room_type']);
}
if(!empty($mother['room_name'])){
$room_parts[]=trim((string)$mother['room_name']);
}
if(!empty($mother['room_number'])){
$room_parts[]='Room '.trim((string)$mother['room_number']);
}
$room_ward_type=count($room_parts)?implode(' - ',$room_parts):null;
/* ===========================================================
   BED
=========================================================== */
$bed_parts=[];
if(!empty($mother['bed_number'])){
$bed_parts[]='Bed '.trim((string)$mother['bed_number']);
}
if(!empty($mother['bed_type'])){
$bed_parts[]=trim((string)$mother['bed_type']);
}
$bed_detail=count($bed_parts)?implode(' - ',$bed_parts):null;
/* ===========================================================
   GET SURGEON / ASSISTANT NAMES
   These IDs come from doctor_master.id
=========================================================== */
function getDoctorNameByMasterId($conn,$doctorMasterId){
if($doctorMasterId===null){
return null;
}
$stmt=$conn->prepare("
SELECT u.full_name
FROM doctor_master dm
INNER JOIN users u
ON u.id=dm.user_id
WHERE dm.id=?
LIMIT 1
");
$stmt->bind_param("i",$doctorMasterId);
$stmt->execute();
$row=$stmt->get_result()->fetch_assoc();
$stmt->close();
if(!$row){
return null;
}
$name=trim((string)($row['full_name']??''));
return $name===''?null:$name;
}
$lead_surgeon_name=getDoctorNameByMasterId($conn,$lead_surgeon_id);
$second_surgeon_name=getDoctorNameByMasterId($conn,$second_surgeon_id);
$assistant_name=getDoctorNameByMasterId($conn,$assistant_id);
if($lead_surgeon_id!==null && $lead_surgeon_name===null){
failSave('Selected Lead Surgeon not found.');
}
if($second_surgeon_id!==null && $second_surgeon_name===null){
failSave('Selected Second Surgeon not found.');
}
if($assistant_id!==null && $assistant_name===null){
failSave('Selected Assistant not found.');
}
/* ===========================================================
   OBSERVATION / VACCINATION ROWS
=========================================================== */
$vitalRows=$_POST['vital_observation']??[];
$vaccineRows=$_POST['vaccination_given']??[];
if(!is_array($vitalRows)){
$vitalRows=[];
}
if(!is_array($vaccineRows)){
$vaccineRows=[];
}
$observations=[];
$maxRows=max(count($vitalRows),count($vaccineRows));
for($i=0;$i<$maxRows;$i++){
$vital=trim((string)($vitalRows[$i]??''));
$vaccine=trim((string)($vaccineRows[$i]??''));
if($vital==='' && $vaccine===''){
continue;
}
if($vital===''){
failSave('Vital Observation is required in row '.($i+1).'.');
}
if($vaccine===''){
failSave('Vaccination Given is required in row '.($i+1).'.');
}
$observations[]=[
'vital'=>$vital,
'vaccine'=>$vaccine
];
}
if(count($observations)===0){
failSave('Please enter at least one Vital Observation and Vaccination.');
}
/* ===========================================================
   QR / STATUS
=========================================================== */
try{
$qr_token=bin2hex(random_bytes(32));
}catch(Throwable $e){
$qr_token=hash(
'sha256',
$certificate_no.'|'.microtime(true).'|'.$user_id
);
}
$status='Done';
/* ===========================================================
   SAVE TRANSACTION
=========================================================== */
try{
$conn->begin_transaction();
$sql="
INSERT INTO newborn_certificates(
certificate_no,
certificate_date,
patient_id,
ipd_admission_id,
ipd_no,
uhid,
mother_name,
guardian_name,
mobile,
dob,
age_y,
age_m,
age_d,
age,
sex,
marital_status,
address,
admission_date,
admission_time,
department_id,
department_name,
doctor_id,
doctor_name,
doctor_registration_no,
room_ward_type,
bed_detail,
first_pregnancy,
anaesthesia,
lead_surgeon_id,
lead_surgeon_name,
second_surgeon_id,
second_surgeon_name,
assistant_id,
assistant_name,
delivery_type,
patient_status,
last_child_age,
born_child,
alive_child,
still_born_count,
baby_gender,
baby_weight,
baby_length,
delivery_date,
delivery_time,
remarks,
oxygen_required,
jaundice,
oxygen_duration,
apgar_1_min,
apgar_5_min,
head_shape,
hospital_id,
branch_id,
qr_token,
status,
created_by,
updated_by
) VALUES (
?,?,?,?,?,?,?,?,?,?,
?,?,?,?,?,?,?,?,?,?,
?,?,?,?,?,?,?,?,?,?,
?,?,?,?,?,?,?,?,?,?,
?,?,?,?,?,?,?,?,?,?,
?,?,?,?,?,?,?,?
)
";
$stmt=$conn->prepare($sql);
if(!$stmt){
throw new Exception('Certificate prepare failed: '.$conn->error);
}
/*
58 columns
58 placeholders
*/
$types=
'ss'.      // certificate_no, certificate_date
'ii'.      // patient_id, ipd_admission_id
'si'.      // ipd_no, uhid
'ssss'.    // mother_name, guardian_name, mobile, dob
'iii'.     // age_y, age_m, age_d
'ssss'.    // age, sex, marital_status, address
'ss'.      // admission_date, admission_time
'is'.      // department_id, department_name
'iss'.     // doctor_id, doctor_name, doctor_registration_no
'ss'.      // room_ward_type, bed_detail
'ii'.      // first_pregnancy, anaesthesia
'is'.      // lead_surgeon_id, lead_surgeon_name
'is'.      // second_surgeon_id, second_surgeon_name
'is'.      // assistant_id, assistant_name
'sss'.     // delivery_type, patient_status, last_child_age
'iii'.     // born_child, alive_child, still_born_count
'sdd'.     // baby_gender, baby_weight, baby_length
'sss'.     // delivery_date, delivery_time, remarks
'ii'.      // oxygen_required, jaundice
'ssss'.    // oxygen_duration, apgar_1_min, apgar_5_min, head_shape
'ii'.      // hospital_id, branch_id
'ss'.      // qr_token, status
'ii';      // created_by, updated_by
$stmt->bind_param(
$types,
$certificate_no,
$certificate_date,
$patient_id,
$ipd_admission_id,
$ipd_no,
$uhid,
$mother_name,
$guardian_name,
$mobile,
$dob,
$age_y,
$age_m,
$age_d,
$age,
$sex,
$marital_status,
$address,
$admission_date,
$admission_time,
$department_id,
$department_name,
$doctor_id,
$doctor_name,
$doctor_registration_no,
$room_ward_type,
$bed_detail,
$first_pregnancy,
$anaesthesia,
$lead_surgeon_id,
$lead_surgeon_name,
$second_surgeon_id,
$second_surgeon_name,
$assistant_id,
$assistant_name,
$delivery_type,
$patient_status,
$last_child_age,
$born_child,
$alive_child,
$still_born_count,
$baby_gender,
$baby_weight,
$baby_length,
$delivery_date,
$delivery_time,
$remarks,
$oxygen_required,
$jaundice,
$oxygen_duration,
$apgar_1_min,
$apgar_5_min,
$head_shape,
$hospital_id,
$branch_id,
$qr_token,
$status,
$user_id,
$user_id
);
if(!$stmt->execute()){
throw new Exception('Certificate insert failed: '.$stmt->error);
}
$newborn_certificate_id=(int)$conn->insert_id;
$stmt->close();
/* ===========================================================
   SAVE OBSERVATIONS
=========================================================== */
$obsStmt=$conn->prepare("
INSERT INTO newborn_certificate_observations(
newborn_certificate_id,
sr_no,
vital_observation,
vaccination_given
) VALUES (?,?,?,?)
");
if(!$obsStmt){
throw new Exception('Observation prepare failed: '.$conn->error);
}
$sr_no=0;
$vital_observation='';
$vaccination_given='';
$obsStmt->bind_param(
"iiss",
$newborn_certificate_id,
$sr_no,
$vital_observation,
$vaccination_given
);
foreach($observations as $index=>$observation){
$sr_no=$index+1;
$vital_observation=$observation['vital'];
$vaccination_given=$observation['vaccine'];
if(!$obsStmt->execute()){
throw new Exception(
'Observation row '.$sr_no.' failed: '.$obsStmt->error
);
}
}
$obsStmt->close();
$conn->commit();
/* ===========================================================
   SUCCESS
=========================================================== */
$_SESSION['success_message']='New Born Certificate saved successfully.';
/* ===========================================================
   SAVE & PRINT
=========================================================== */
if(isset($_POST['save_print']) && $_POST['save_print']=='1'){
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>New Born Certificate Saved</title>
</head>
<body>
<script>
if(window.parent && window.parent!==window){
window.parent.location.href="print.php?id=<?= $newborn_certificate_id ?>";
}else{
window.location.href="print.php?id=<?= $newborn_certificate_id ?>";
}
</script>
</body>
</html>
<?php
exit;
}
/* ===========================================================
   NORMAL SAVE
=========================================================== */
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>New Born Certificate Saved</title>
</head>
<body>
<script>
if(window.parent && window.parent!==window){
window.parent.postMessage("newborn_certificate_saved","*");
}else{
window.location.href="newborn_certificate.php";
}
</script>
</body>
</html>
<?php
exit;
}catch(Throwable $e){
$conn->rollback();
error_log(
'New Born Certificate Save Error: '.$e->getMessage()
);
$_SESSION['error_message']='Unable to save New Born Certificate: '.$e->getMessage();
header('Location: newborn_certificate.php');
exit;
}
?>