<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

ini_set('display_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if(!isset($_SESSION['user_id'])){
    header("Location:/login/login.php");
    exit;
}

if(!checkPermission('ipd_admission','add')){
    header("Location:/access_denied.php");
    exit;
}

$user_id=(int)$_SESSION['user_id'];

if($_SERVER['REQUEST_METHOD']!='POST'){
    die("Invalid Request");
}

/*=========================================================
START DATABASE TRANSACTION
=========================================================*/

mysqli_begin_transaction($conn);

try{

/*=========================================================
LOAD POST DATA
=========================================================*/

$appointment_id=(int)($_POST['appointment_id'] ?? 0);
$patient_id=(int)($_POST['patient_id'] ?? 0);
$hospital_id=(int)($_POST['hospital_id'] ?? 0);
$branch_id=(int)($_POST['branch_id'] ?? 0);

$doctor_id=(int)($_POST['doctor_id'] ?? 0);
$department_id=(int)($_POST['department_id'] ?? 0);

$admission_date=trim($_POST['admission_date'] ?? '');
$admission_time=trim($_POST['admission_time'] ?? '');

$admission_type=trim($_POST['admission_type'] ?? 'Routine');

$file_no=trim($_POST['file_no'] ?? '');

$referral_type=trim($_POST['referral_type'] ?? '');

$referral_by=
!empty($_POST['referral_by'])
?
(int)$_POST['referral_by']
:
NULL;


$payment_type=trim($_POST['payment_type'] ?? 'Cash');

$credit_limit=(float)($_POST['credit_limit'] ?? 0);

$is_emergency=isset($_POST['is_emergency']) ? 1 : 0;
$is_vip=isset($_POST['is_vip']) ? 1 : 0;
$is_mlc=isset($_POST['is_mlc']) ? 1 : 0;

$mlc_type=trim($_POST['mlc_type'] ?? '');
$police_station=trim($_POST['police_station'] ?? '');
$accident_place=trim($_POST['accident_place'] ?? '');
$fir_no=trim($_POST['fir_no'] ?? '');
$mlc_remarks=trim($_POST['mlc_remarks'] ?? '');

/*=========================================================
ROOM DETAILS
=========================================================*/

$building_id=(int)($_POST['building_id'] ?? 0);
$floor_id=(int)($_POST['floor_id'] ?? 0);
$room_id=(int)($_POST['room_id'] ?? 0);
$bed_id=(int)($_POST['bed_id'] ?? 0);

/*=========================================================
ROOM ALLOCATION DETAILS
=========================================================*/

$expected_stay=(int)($_POST['expected_stay'] ?? 1);

$primary_diagnosis=
trim($_POST['primary_diagnosis'] ?? '');

$admission_reason=
trim($_POST['admission_reason'] ?? '');

$attendant_name=
trim($_POST['attendant_name'] ?? '');

$attendant_mobile=
trim($_POST['attendant_mobile'] ?? '');

$remarks=
trim($_POST['remarks'] ?? '');

/*=========================================================
OTHER DOCTORS
=========================================================*/

$other_doctors=
$_POST['other_doctors'] ?? [];

/*=========================================================
BASIC VALIDATION
=========================================================*/

if(
$appointment_id<=0 ||
$patient_id<=0 ||
$doctor_id<=0 ||
$room_id<=0 ||
$bed_id<=0
){
    throw new Exception("Required information is missing.");
}

if($payment_type!='Cash' && $payment_type!='Credit'){
    throw new Exception("Invalid Payment Type.");
}
if($payment_type=='Cash'){
    $credit_limit=0;
}
/*=========================================================
REFERRAL VALIDATION
=========================================================*/

if(
in_array($referral_type,['Internal','External']) &&
empty($referral_by)
){
    throw new Exception("Please select Referral By.");
}

if(
$referral_type!='' &&
!in_array($referral_type,['Internal','External'])
){
    throw new Exception("Invalid Referral Type.");
}
if(
$admission_date=='' ||
$admission_time==''
){
    throw new Exception("Admission Date/Time is required.");
}
/*=========================================================
VERIFY APPOINTMENT
=========================================================*/
$stmt=$conn->prepare("
SELECT
id,
patient_id,
opd_no,
ipd_no,
status
FROM appointments
WHERE id=?
LIMIT 1
");
$stmt->bind_param("i",$appointment_id);
$stmt->execute();
$appointment=$stmt->get_result()->fetch_assoc();

if(!$appointment){
    throw new Exception("Appointment not found.");
}
/*=========================================================
GENERATE IPD NUMBER IF NOT AVAILABLE
=========================================================*/

if(empty($appointment['ipd_no'])){

    $resSeq=$conn->query("
    SELECT COALESCE(MAX(CAST(SUBSTRING(ipd_no,5) AS UNSIGNED)),0) AS last_ipd
    FROM appointments
    WHERE type='ipd'
    FOR UPDATE
    ");

    $rowSeq=$resSeq->fetch_assoc();

    $ipd_seq=$rowSeq['last_ipd']+1;

    $ipd_no='IPD-'.str_pad($ipd_seq,6,'0',STR_PAD_LEFT);

    $stmt=$conn->prepare("
    UPDATE appointments
    SET ipd_no=?
    WHERE id=?
    ");

    $stmt->bind_param(
    "si",
    $ipd_no,
    $appointment_id
    );

    $stmt->execute();

}else{

    $ipd_no=$appointment['ipd_no'];

}

$opd_no=$appointment['opd_no'];
/*=========================================================
CHECK DUPLICATE ADMISSION
=========================================================*/

$stmt=$conn->prepare("
SELECT id
FROM ipd_admissions
WHERE appointment_id=?
LIMIT 1
");
$stmt->bind_param("i",$appointment_id);
$stmt->execute();
$stmt->store_result();

if($stmt->num_rows>0){
    throw new Exception("Patient is already admitted.");
}

/*=========================================================
VERIFY ROOM
=========================================================*/
$stmt=$conn->prepare("
SELECT
r.id,
r.floor_id,
f.building_id
FROM room_master r
INNER JOIN floor_master f
ON f.id=r.floor_id
WHERE
r.id=?
AND r.floor_id=?
LIMIT 1
");
$stmt->bind_param(
"ii",
$room_id,
$floor_id
);
$stmt->execute();
$room=$stmt->get_result()->fetch_assoc();

if(!$room){
    throw new Exception("Invalid Room Selected.");
}
if($room['building_id'] != $building_id){
    throw new Exception("Selected room does not belong to the selected building.");
}
$building_id=$room['building_id'];
$floor_id=$room['floor_id'];

/*=========================================================
VERIFY BED
=========================================================*/
$stmt=$conn->prepare("
SELECT
id,
room_id,
status
FROM bed_master
WHERE id=?
LIMIT 1
");
$stmt->bind_param("i",$bed_id);
$stmt->execute();
$bed=$stmt->get_result()->fetch_assoc();
if(!$bed){
    throw new Exception("Bed not found.");
}

if($bed['room_id'] != $room_id){
    throw new Exception("Selected bed does not belong to the selected room.");
}

if($bed['status'] != 'Available'){
    throw new Exception("Selected bed is not available.");
}
/*=========================================================
INSERT INTO IPD ADMISSIONS
=========================================================*/

$status='Admitted';
$stmt=$conn->prepare("
INSERT INTO ipd_admissions
(
patient_id,
appointment_id,
opd_id,
ipd_no,
file_no,
hospital_id,
branch_id,
doctor_id,
department_id,
admission_date,
admission_time,
admission_type,
referral_type,
referral_by,
status,
created_by,
credit_limit,
payment_type,
is_emergency,
is_vip,
is_mlc,
mlc_type,
police_station,
accident_place,
fir_no,
mlc_remarks
)
VALUES
(
?,?,?,?,?,
?,?,?,?,?,
?,?,?,?,?,
?,?,?,?,?,
?,?,?,?,?,
?
)
");

$stmt->bind_param(
"iiissiiiissssisidsiiisssss",
$patient_id,
$appointment_id,
$appointment_id,
$ipd_no,
$file_no,
$hospital_id,
$branch_id,
$doctor_id,
$department_id,
$admission_date,
$admission_time,
$admission_type,
$referral_type,
$referral_by,
$status,
$user_id,
$credit_limit,
$payment_type,
$is_emergency,
$is_vip,
$is_mlc,
$mlc_type,
$police_station,
$accident_place,
$fir_no,
$mlc_remarks
);
if(!$stmt->execute()){
    throw new Exception($stmt->error);
}
$admission_id=$conn->insert_id;
/*=========================================================
INSERT ROOM ALLOCATION
=========================================================*/
$allocation_status='Occupied';
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
remarks,
status,
created_by
)
VALUES
(
?,?,?,?,?,
?,?,?,?,?,
?,?,
?
)
");
$stmt->bind_param(
"iiiisssssissi",
$admission_id,
$building_id,
$floor_id,
$room_id,
$bed_id,
$admission_reason,
$primary_diagnosis,
$attendant_name,
$attendant_mobile,
$expected_stay,
$remarks,
$allocation_status,
$user_id
);
if(!$stmt->execute()){
    throw new Exception($stmt->error);
}
/*=========================================================
SAVE OTHER DOCTORS
=========================================================*/
if(!empty($other_doctors)){
    $stmtDoctor=$conn->prepare("
    INSERT INTO ipd_admission_doctors
    (
    admission_id,
    doctor_id
    )
    VALUES
    (?,?)
    ");
    foreach($other_doctors as $doctor){
        $doctor=(int)$doctor;
        if($doctor<=0){
            continue;
        }
        if($doctor==$doctor_id){
            continue;
        }
        $stmtDoctor->bind_param(
        "ii",
        $admission_id,
        $doctor
        );
        if(!$stmtDoctor->execute()){
            throw new Exception($stmtDoctor->error);
        }
    }
}
/*=========================================================
UPDATE BED STATUS
=========================================================*/
$stmt=$conn->prepare("
UPDATE bed_master
SET
status='Occupied'
WHERE id=?
");
$stmt->bind_param(
"i",
$bed_id
);
if(!$stmt->execute()){
    throw new Exception($stmt->error);
}
if($stmt->affected_rows==0){
    throw new Exception("Unable to update bed status.");
}
/*=========================================================
UPDATE APPOINTMENT STATUS
=========================================================*/
$appointment_status='Admitted';
$stmt=$conn->prepare("
UPDATE appointments
SET
status=?,
type='ipd'
WHERE id=?
");
$stmt->bind_param(
"si",
$appointment_status,
$appointment_id
);
if(!$stmt->execute()){
    throw new Exception($stmt->error);
}
/*=========================================================
VERIFY APPOINTMENT UPDATED
=========================================================*/
if($stmt->affected_rows==0){
    throw new Exception("Unable to update appointment status.");
}
/*=========================================================
COMMIT TRANSACTION
=========================================================*/
mysqli_commit($conn);
/*=========================================================
SUCCESS
=========================================================*/
$_SESSION['success_message']="Patient admitted successfully.";
header("Location:/modules/ipd/ipd_list.php");
exit;
}
catch(Exception $e){
/*=========================================================
ROLLBACK
=========================================================*/
mysqli_rollback($conn);
$_SESSION['error_message']=$e->getMessage();
header(
"Location:admission.php?appointment_id=".$appointment_id
);
exit;
}
?>