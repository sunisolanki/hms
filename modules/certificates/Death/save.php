<?php
// public_html/modules/certificates/Death/save.php
session_start();

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
checkPermission('death_certificate_add','add');
if($_SERVER['REQUEST_METHOD']!=='POST'){
$_SESSION['error_message']='Invalid Request.';
header('Location: death_certificate.php');
exit;
}

/* ===========================================================
   SESSION
=========================================================== */
$user_id=(int)($_SESSION['user_id']??0);
$branch_id=(int)($_SESSION['branch_id']??0);
if($user_id<=0){
$_SESSION['error_message']='Invalid User Session.';
header('Location: death_certificate.php');
exit;
}
if($branch_id<=0){
$_SESSION['error_message']='Branch not found in session.';
header('Location: death_certificate.php');
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
$result=$stmt->get_result();
$branch=$result->fetch_assoc();
$stmt->close();
if(!$branch || (int)$branch['hospital_id']<=0){
$_SESSION['error_message']='Hospital not found for this branch.';
header('Location: death_certificate.php');
exit;
}
$hospital_id=(int)$branch['hospital_id'];
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
function nullableNumber($key){
if(!isset($_POST[$key]) || $_POST[$key]===''){
return null;
}
return (int)$_POST[$key];
}
/* ===========================================================
   CERTIFICATE INFORMATION
=========================================================== */
$certificate_no=postText('certificate_no');
$certificate_date=postText('certificate_date');
$patient_type=postText('patient_type');
if(!in_array($patient_type,['Direct','OPD','IPD'],true)){
$patient_type='Direct';
}
/* ===========================================================
   PATIENT LINKING
=========================================================== */
$patient_id=nullableInt('patient_id');
$appointment_id=nullableInt('appointment_id');
$ipd_admission_id=nullableInt('ipd_admission_id');
$uhid=nullableInt('uhid');
/* ===========================================================
   PATIENT INFORMATION
=========================================================== */
$patient_name=postText('patient_name');
$guardian_name=nullableText('guardian_name');
$mobile=nullableText('mobile');
$dob=nullableText('dob');
$age_y=nullableNumber('age_y');
$age_m=nullableNumber('age_m');
$age_d=nullableNumber('age_d');
$age=null;
if($age_y!==null || $age_m!==null || $age_d!==null){
$y=$age_y??0;
$m=$age_m??0;
$d=$age_d??0;
$age=$y.'Y '.$m.'M '.$d.'D';
}
$sex=nullableText('sex');
$marital_status=nullableText('marital_status');
$address=nullableText('address');
/* ===========================================================
   DEATH DETAILS
=========================================================== */
$date_of_death=postText('date_of_death');
$time_of_death=nullableText('time_of_death');
$place_of_death=postText('place_of_death');
if(!in_array($place_of_death,['Hospital','Home','Other'],true)){
$place_of_death='Hospital';
}
$place_of_death_other=null;
if($place_of_death==='Other'){
$place_of_death_other=nullableText('place_of_death_other');
}
$immediate_cause=nullableText('immediate_cause');
$antecedent_cause=nullableText('antecedent_cause');
$underlying_cause=nullableText('underlying_cause');
$other_significant_conditions=nullableText('other_significant_conditions');
$manner_of_death=postText('manner_of_death');
$allowed_manner=[
'Natural',
'Accident',
'Suicide',
'Homicide',
'Pending Investigation',
'Other'
];
if(!in_array($manner_of_death,$allowed_manner,true)){
$manner_of_death='Natural';
}
$manner_of_death_other=null;
if($manner_of_death==='Other'){
$manner_of_death_other=nullableText('manner_of_death_other');
}
$remarks=nullableText('remarks');
/* ===========================================================
   DOCTOR
=========================================================== */
$doctor_id=nullableInt('doctor_id');
$doctor_name=nullableText('doctor_name');
$doctor_registration_no=nullableText('doctor_registration_no');
/* ===========================================================
   VALIDATION
=========================================================== */
if($certificate_no===''){
$_SESSION['error_message']='Certificate Number is required.';
header('Location: death_certificate.php');
exit;
}
if($certificate_date===''){
$_SESSION['error_message']='Certificate Date is required.';
header('Location: death_certificate.php');
exit;
}
if($patient_name===''){
$_SESSION['error_message']='Patient Name is required.';
header('Location: death_certificate.php');
exit;
}
if($date_of_death===''){
$_SESSION['error_message']='Date of Death is required.';
header('Location: death_certificate.php');
exit;
}
if($doctor_id===null){
$_SESSION['error_message']='Please select Doctor.';
header('Location: death_certificate.php');
exit;
}
/* ===========================================================
   PATIENT TYPE VALIDATION
=========================================================== */
if($patient_type==='OPD'){
if($patient_id===null || $appointment_id===null){
$_SESSION['error_message']='Please select a valid OPD Patient.';
header('Location: death_certificate.php');
exit;
}
}
if($patient_type==='IPD'){
if($patient_id===null || $ipd_admission_id===null){
$_SESSION['error_message']='Please select a valid IPD Patient.';
header('Location: death_certificate.php');
exit;
}
}
/* ===========================================================
   DIRECT MOBILE VALIDATION
=========================================================== */
if($patient_type==='Direct'){
if($mobile===null){
$_SESSION['error_message']='Mobile Number is required.';
header('Location: death_certificate.php');
exit;
}
if(!preg_match('/^[6-9][0-9]{9}$/',$mobile)){
$_SESSION['error_message']='Please enter a valid 10-digit Mobile Number.';
header('Location: death_certificate.php');
exit;
}
}
/* ===========================================================
   OTHER FIELD VALIDATION
=========================================================== */
if($place_of_death==='Other' && empty($place_of_death_other)){
$_SESSION['error_message']='Please specify Place of Death.';
header('Location: death_certificate.php');
exit;
}
if($manner_of_death==='Other' && empty($manner_of_death_other)){
$_SESSION['error_message']='Please specify Manner of Death.';
header('Location: death_certificate.php');
exit;
}
/* ===========================================================
   VERIFY DOCTOR AND GET AUTHORITATIVE DOCTOR DETAILS
=========================================================== */
$stmt=$conn->prepare("
SELECT
dm.id,
u.full_name,
dm.license_no
FROM doctor_master dm
INNER JOIN users u
ON u.id=dm.user_id
WHERE dm.id=?
LIMIT 1
");
$stmt->bind_param("i",$doctor_id);
$stmt->execute();
$doctor_result=$stmt->get_result();
$doctor_row=$doctor_result->fetch_assoc();
$stmt->close();
if(!$doctor_row){
$_SESSION['error_message']='Selected Doctor not found.';
header('Location: death_certificate.php');
exit;
}
$doctor_name=trim((string)($doctor_row['full_name']??''));
$doctor_registration_no=trim((string)($doctor_row['license_no']??''));
if($doctor_registration_no===''){
$doctor_registration_no=null;
}
/* ===========================================================
   DUPLICATE CERTIFICATE NUMBER CHECK
=========================================================== */
$stmt=$conn->prepare("
SELECT id
FROM death_certificates
WHERE certificate_no=?
LIMIT 1
");
$stmt->bind_param("s",$certificate_no);
$stmt->execute();
$duplicate=$stmt->get_result()->fetch_assoc();
$stmt->close();
if($duplicate){
$_SESSION['error_message']='Certificate Number already exists. Please reopen the form.';
header('Location: death_certificate.php');
exit;
}
/* ===========================================================
   QR TOKEN / STATUS
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
   SAVE
=========================================================== */
try{
$conn->begin_transaction();
$sql="
INSERT INTO death_certificates(
certificate_no,
certificate_date,
patient_type,
patient_id,
appointment_id,
ipd_admission_id,
uhid,
patient_name,
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
date_of_death,
time_of_death,
place_of_death,
place_of_death_other,
immediate_cause,
antecedent_cause,
underlying_cause,
other_significant_conditions,
manner_of_death,
manner_of_death_other,
remarks,
doctor_id,
doctor_name,
doctor_registration_no,
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
?,?,?,?,?,?,?,?
)
";
$stmt=$conn->prepare($sql);
if(!$stmt){
throw new Exception('Prepare failed: '.$conn->error);
}
/*
38 COLUMNS
38 PLACEHOLDERS
38 TYPES
38 VARIABLES
*/
$types =
    "sss" .                 // certificate_no, certificate_date, patient_type
    "iiii" .                // patient_id, appointment_id, ipd_admission_id, uhid
    "ssss" .                // patient_name, guardian_name, mobile, dob
    "iii" .                 // age_y, age_m, age_d
    "sssssssssssssss" .     // age, sex, marital_status, address,
                            // date_of_death, time_of_death, place_of_death,
                            // place_of_death_other, immediate_cause,
                            // antecedent_cause, underlying_cause,
                            // other_significant_conditions,
                            // manner_of_death, manner_of_death_other, remarks
    "i" .                   // doctor_id
    "ss" .                  // doctor_name, doctor_registration_no
    "ii" .                  // hospital_id, branch_id
    "ss" .                  // qr_token, status
    "ii";                   // created_by, updated_by
    
$stmt->bind_param(
$types,
$certificate_no,
$certificate_date,
$patient_type,
$patient_id,
$appointment_id,
$ipd_admission_id,
$uhid,
$patient_name,
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
$date_of_death,
$time_of_death,
$place_of_death,
$place_of_death_other,
$immediate_cause,
$antecedent_cause,
$underlying_cause,
$other_significant_conditions,
$manner_of_death,
$manner_of_death_other,
$remarks,
$doctor_id,
$doctor_name,
$doctor_registration_no,
$hospital_id,
$branch_id,
$qr_token,
$status,
$user_id,
$user_id
);
if(!$stmt->execute()){
throw new Exception('Insert failed: '.$stmt->error);
}
$certificate_id=(int)$conn->insert_id;
$stmt->close();
$conn->commit();
/* ===========================================================
   SUCCESS
=========================================================== */
$_SESSION['success_message']='Death Certificate created successfully.';
/* ===========================================================
   SAVE & PRINT
=========================================================== */
if(isset($_POST['save_print']) && $_POST['save_print']=='1'){
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Death Certificate Saved</title>
</head>
<body>
<script>
if(window.parent && window.parent!==window){
window.parent.location.href="print.php?id=<?= $certificate_id ?>";
}else{
window.location.href="print.php?id=<?= $certificate_id ?>";
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
<title>Death Certificate Saved</title>
</head>
<body>
<script>
if(window.parent && window.parent!==window){
window.parent.postMessage("death_certificate_saved","*");
}else{
window.location.href="death_certificate.php";
}
</script>
</body>
</html>
<?php
exit;
}
catch(mysqli_sql_exception $e){

    try{
        $conn->rollback();
    }catch(Throwable $rollbackError){
    }

    die(
        '<h3>Database Error</h3>'.
        '<pre>'.
        htmlspecialchars($e->getMessage()).
        '</pre>'
    );
}
catch(Throwable $e){

    try{
        $conn->rollback();
    }catch(Throwable $rollbackError){
    }

    die(
        '<h3>PHP Error</h3>'.
        '<pre>'.
        htmlspecialchars($e->getMessage()).
        '</pre>'
    );
}
?>