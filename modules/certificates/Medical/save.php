<?php
// public_html/modules/certificates/Medical/save.php
session_start();
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if($_SERVER['REQUEST_METHOD']!='POST'){
die('Invalid Request');
}

try{

$conn->begin_transaction();

/*
|--------------------------------------------------------------------------
| BASIC CERTIFICATE DETAILS
|--------------------------------------------------------------------------
*/

$certificate_no=trim($_POST['certificate_no'] ?? '');
$certificate_date=trim($_POST['certificate_date'] ?? '');

/*
|--------------------------------------------------------------------------
| PATIENT SOURCE
|--------------------------------------------------------------------------
*/

$patient_type=trim($_POST['patient_type'] ?? 'Direct');

$patient_id=!empty($_POST['patient_id'])
? (int)$_POST['patient_id']
: NULL;

$appointment_id=!empty($_POST['appointment_id'])
? (int)$_POST['appointment_id']
: NULL;

$ipd_admission_id=!empty($_POST['ipd_admission_id'])
? (int)$_POST['ipd_admission_id']
: NULL;

$uhid=!empty($_POST['uhid'])
? (int)$_POST['uhid']
: NULL;

/*
|--------------------------------------------------------------------------
| PATIENT DETAILS
|--------------------------------------------------------------------------
*/

$patient_name=trim($_POST['patient_name'] ?? '');
$guardian_name=trim($_POST['guardian_name'] ?? '');
$mobile=trim($_POST['mobile'] ?? '');

$dob=!empty($_POST['dob'])
? $_POST['dob']
: NULL;

$age_y=isset($_POST['age_y']) && $_POST['age_y']!==''
? (int)$_POST['age_y']
: NULL;

$age_m=isset($_POST['age_m']) && $_POST['age_m']!==''
? (int)$_POST['age_m']
: NULL;

$age_d=isset($_POST['age_d']) && $_POST['age_d']!==''
? (int)$_POST['age_d']
: NULL;

$age='';

if($age_y!==NULL){
$age.=$age_y.'Y';
}

if($age_m!==NULL){
$age.=($age!=''?' ':'').$age_m.'M';
}

if($age_d!==NULL){
$age.=($age!=''?' ':'').$age_d.'D';
}

$sex=trim($_POST['sex'] ?? '');
$marital_status=trim($_POST['marital_status'] ?? '');
$address=trim($_POST['address'] ?? '');

/*
|--------------------------------------------------------------------------
| CERTIFICATE DETAILS
|--------------------------------------------------------------------------
*/

$reference_no=trim($_POST['reference_no'] ?? '');

$purpose=trim($_POST['purpose'] ?? '');
$purpose_other=trim($_POST['purpose_other'] ?? '');

$issued_to=trim($_POST['issued_to'] ?? '');
$issued_to_other=trim($_POST['issued_to_other'] ?? '');

$suffering_from=trim($_POST['suffering_from'] ?? '');

$rest_from=!empty($_POST['rest_from'])
? $_POST['rest_from']
: NULL;

$rest_to=!empty($_POST['rest_to'])
? $_POST['rest_to']
: NULL;

$joining_date=!empty($_POST['joining_date'])
? $_POST['joining_date']
: NULL;

$fitness_status=trim($_POST['fitness_status'] ?? '');

$remarks=trim($_POST['remarks'] ?? '');

/*
|--------------------------------------------------------------------------
| DOCTOR DETAILS
|--------------------------------------------------------------------------
*/

$doctor_id=!empty($_POST['doctor_id'])
? (int)$_POST['doctor_id']
: NULL;

$doctor_name='';
$doctor_registration_no='';

if(!empty($doctor_id)){

$doctor_stmt=$conn->prepare("
SELECT
u.full_name AS doctor_name,
dm.license_no AS registration_no
FROM doctor_master dm
LEFT JOIN users u
ON u.id=dm.user_id
WHERE dm.id=?
LIMIT 1
");

$doctor_stmt->bind_param(
"i",
$doctor_id
);

$doctor_stmt->execute();

$doctor_result=$doctor_stmt->get_result();

if($doctor_row=$doctor_result->fetch_assoc()){

$doctor_name=trim(
$doctor_row['doctor_name'] ?? ''
);

$doctor_registration_no=trim(
$doctor_row['registration_no'] ?? ''
);

}

$doctor_stmt->close();

}

/*
|--------------------------------------------------------------------------
| LOGIN / HOSPITAL DETAILS
|--------------------------------------------------------------------------
*/

$hospital_id=!empty($_SESSION['hospital_id'])
? (int)$_SESSION['hospital_id']
: NULL;

$branch_id=!empty($_SESSION['branch_id'])
? (int)$_SESSION['branch_id']
: NULL;

$created_by=!empty($_SESSION['user_id'])
? (int)$_SESSION['user_id']
: NULL;

/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

$action=trim($_POST['action'] ?? 'save');

$status='Done';

/*
|--------------------------------------------------------------------------
| QR TOKEN
|--------------------------------------------------------------------------
*/

$qr_token=bin2hex(random_bytes(32));

/*
|--------------------------------------------------------------------------
| BASIC VALIDATION
|--------------------------------------------------------------------------
*/

if($certificate_no===''){
throw new Exception('Certificate Number is required.');
}

if($certificate_date===''){
throw new Exception('Certificate Date is required.');
}

if(!in_array($patient_type,['Direct','OPD','IPD'],true)){
throw new Exception('Invalid Patient Type.');
}

if($patient_name===''){
throw new Exception('Patient Name is required.');
}

if(empty($doctor_id)){
throw new Exception('Doctor is required.');
}

/*
|--------------------------------------------------------------------------
| PATIENT TYPE VALIDATION
|--------------------------------------------------------------------------
*/

if($patient_type==='OPD' && empty($appointment_id)){
throw new Exception('Please select an OPD patient.');
}

if($patient_type==='IPD' && empty($ipd_admission_id)){
throw new Exception('Please select an IPD patient.');
}

/*
|--------------------------------------------------------------------------
| DUPLICATE CERTIFICATE NUMBER CHECK
|--------------------------------------------------------------------------
*/

$check=$conn->prepare("
SELECT id
FROM medical_certificates
WHERE certificate_no=?
LIMIT 1
");

$check->bind_param(
"s",
$certificate_no
);

$check->execute();

$check_result=$check->get_result();

if($check_result->num_rows>0){

$check->close();

throw new Exception(
'Certificate Number already exists.'
);

}

$check->close();

/*
|--------------------------------------------------------------------------
| INSERT MEDICAL CERTIFICATE
|--------------------------------------------------------------------------
*/

$insert=$conn->prepare("
INSERT INTO medical_certificates
(
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
address,
marital_status,
reference_no,
purpose,
purpose_other,
issued_to,
issued_to_other,
suffering_from,
rest_from,
rest_to,
joining_date,
fitness_status,
remarks,
doctor_id,
doctor_name,
doctor_registration_no,
hospital_id,
branch_id,
qr_token,
status,
created_by
)
VALUES
(
?,?,?,?,?,?,?,?,?,?,
?,?,?,?,?,?,?,?,?,?,
?,?,?,?,?,?,?,?,?,?,
?,?,?,?,?,?,?
)
");

/*
|--------------------------------------------------------------------------
| BIND VALUES
|--------------------------------------------------------------------------
| Total columns      : 37
| Total placeholders : 37
| Total variables    : 37
|--------------------------------------------------------------------------
*/

$types=
"sss".
"iiii".
"ssss".
"iii".
"sssssssssssssss".
"iss".
"ii".
"ssi";

$insert->bind_param(
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
$address,
$marital_status,
$reference_no,
$purpose,
$purpose_other,
$issued_to,
$issued_to_other,
$suffering_from,
$rest_from,
$rest_to,
$joining_date,
$fitness_status,
$remarks,
$doctor_id,
$doctor_name,
$doctor_registration_no,
$hospital_id,
$branch_id,
$qr_token,
$status,
$created_by
);

$insert->execute();

/*
|--------------------------------------------------------------------------
| GET NEW CERTIFICATE ID
|--------------------------------------------------------------------------
*/

$certificate_id=$conn->insert_id;

if(empty($certificate_id)){
throw new Exception(
'Medical Certificate could not be saved.'
);
}

$insert->close();

/*
|--------------------------------------------------------------------------
| COMMIT TRANSACTION
|--------------------------------------------------------------------------
*/

$conn->commit();

/*
|--------------------------------------------------------------------------
| SUCCESS MESSAGE
|--------------------------------------------------------------------------
*/

$_SESSION['success_message']=
'Medical Certificate Saved Successfully.';

/*
|--------------------------------------------------------------------------
| SAVE & PRINT
|--------------------------------------------------------------------------
*/

if($action==='save_print'){

header(
'Location: print.php?id='.(int)$certificate_id
);

exit;

}

/*
|--------------------------------------------------------------------------
| NORMAL SAVE
|--------------------------------------------------------------------------
*/

header(
'Location: medical_certificate.php'
);

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