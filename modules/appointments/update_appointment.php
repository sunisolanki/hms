<?php

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

/* =========================
SECURITY
========================= */

if(!isset($_SESSION['user_id'])){
    header("Location:/login.php");
    exit;
}

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    die("Invalid request");
}

/* =========================
GET IDS
========================= */
$appointment_id = intval($_POST['appointment_id'] ?? 0);
$patient_id     = intval($_POST['patient_id'] ?? 0);
if($appointment_id <= 0){
    die("Invalid appointment ID");
}
/* =========================
COLLECT FORM DATA
========================= */

$type             = $_POST['type'] ?? '';
$entity_id        = intval($_POST['entity_id'] ?? 0);
$appointment_date = $_POST['appointment_date'] ?? '';
$appointment_time = $_POST['appointment_time'] ?? '';
$consultation_mode = $_POST['consultation_mode'] ?? 'PHYSICAL';

if($type !== 'doctor'){
    $consultation_mode = 'PHYSICAL';
}

if(!in_array($consultation_mode, ['PHYSICAL','TELEMEDICINE'], true)){
    $consultation_mode = 'PHYSICAL';
}

$title            = $_POST['title'] ?? '';
$first_name       = trim($_POST['first_name'] ?? '');
$last_name        = trim($_POST['last_name'] ?? '');
$marital_status   = $_POST['marital_status'] ?? '';
$sex              = $_POST['sex'] ?? '';
$guardian_name    = trim($_POST['guardian_name'] ?? '');

$aadhaar_no       = trim($_POST['aadhaar_no'] ?? '');
$mobile           = trim($_POST['mobile'] ?? '');
$phone            = trim($_POST['phone'] ?? '');
$email            = trim($_POST['email'] ?? '');

$dob              = $_POST['dob'] ?? '';
$city_id          = intval($_POST['city'] ?? 0);
$pincode          = trim($_POST['pincode'] ?? '');
$address          = trim($_POST['address'] ?? '');

$remarks          = trim($_POST['remarks'] ?? '');


/* =========================
VALIDATION
========================= */

if(!$entity_id || !$appointment_date || !$appointment_time){
    die("Appointment data missing");
}

if(!$first_name || !$mobile){
    die("Patient name and mobile required");
}


/* =========================
PREVENT SLOT CONFLICT
========================= */

$stmt = $conn->prepare("
SELECT id
FROM appointments
WHERE entity_id = ?
AND appointment_date = ?
AND appointment_time = ?
AND id != ?
AND status NOT IN ('Cancelled','Completed')
LIMIT 1
");

$stmt->bind_param("issi",
    $entity_id,
    $appointment_date,
    $appointment_time,
    $appointment_id
);

$stmt->execute();
$res = $stmt->get_result();

if($res->num_rows > 0){
    die("This time slot is already booked. Please choose another slot.");
}

$stmt->close();


/* =========================
UPDATE PATIENT MASTER
========================= */

if($patient_id > 0){

    $stmt = $conn->prepare("
    UPDATE patient_master SET
    title=?,
    first_name=?,
    last_name=?,
    marital_status=?,
    sex=?,
    guardian_name=?,
    aadhaar=?,
    mobile=?,
    phone=?,
    email=?,
    dob=?,
    city_id=?,
    pincode=?,
    address=?
    WHERE id=?
    ");

    $stmt->bind_param(
    "sssssssssssissi",
    $title,
    $first_name,
    $last_name,
    $marital_status,
    $sex,
    $guardian_name,
    $aadhaar_no,
    $mobile,
    $phone,
    $email,
    $dob,
    $city_id,
    $pincode,
    $address,
    $patient_id
    );

    $stmt->execute();
    $stmt->close();

}
/* =========================
UPDATE APPOINTMENT
========================= */
$stmt = $conn->prepare("
UPDATE appointments SET
type=?,
consultation_mode=?,
entity_id=?,
appointment_date=?,
appointment_time=?,
title=?,
first_name=?,
last_name=?,
marital_status=?,
sex=?,
guardian_name=?,
aadhaar=?,
mobile=?,
phone=?,
email=?,
dob=?,
city_id=?,
pincode=?,
address=?,
remarks=?
WHERE id=?
");
$stmt->bind_param(
"ssisssssssssssssisssi",
$type,
$consultation_mode,
$entity_id,
$appointment_date,
$appointment_time,
$title,
$first_name,
$last_name,
$marital_status,
$sex,
$guardian_name,
$aadhaar_no,
$mobile,
$phone,
$email,
$dob,
$city_id,
$pincode,
$address,
$remarks,
$appointment_id
);
$stmt->execute();
$stmt->close();
/* =========================
RECALCULATE TOKEN
========================= */

$stmt = $conn->prepare("
SELECT COUNT(*) + 1
FROM appointments
WHERE entity_id = ?
AND appointment_date = ?
AND appointment_time < ?
AND status NOT IN ('Cancelled')
AND id != ?
");

$stmt->bind_param(
"issi",
$entity_id,
$appointment_date,
$appointment_time,
$appointment_id
);

$stmt->execute();
$stmt->bind_result($token_no);
$stmt->fetch();
$stmt->close();

/* UPDATE TOKEN */

$stmt = $conn->prepare("
UPDATE appointments
SET token_no = ?
WHERE id = ?
");

$stmt->bind_param("ii",$token_no,$appointment_id);
$stmt->execute();
$stmt->close();

/* =========================
SUCCESS REDIRECT
========================= */

header("Location: /modules/appointments/appointment_list.php?msg=updated");
exit;

?>