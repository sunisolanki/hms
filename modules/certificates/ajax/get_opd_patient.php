<?php
/*
 * OPD PATIENT DETAIL - populates the certificate form from one appointment.
 *
 * WHY THIS FILE WAS HARDENED
 * -------------------------
 * No session check and no permission check. Unauthenticated it CRASHED rather
 * than refusing: includes/db.php returns $conn = null without
 * $_SESSION['tenant_id'], and display_errors was on, so the fatal - including
 * the absolute filesystem path - was returned in the HTTP body. A crash is not
 * an access control.
 *
 * Added: an authenticated staff session and the certificates 'view' permission,
 * checked before any database access. See search_opd_patients.php for the full
 * rationale, for why 'certificates' is the correct module, and for the recorded
 * defect that the host certificate pages discard their own checkPermission()
 * return value.
 *
 * UNCHANGED CONTRACT: HTTP method stays GET; the parameter is still ?id=; the
 * success envelope is still {success:true, ...} with the same keys, and the
 * not-found path still returns {success:false,message:...}. The caller
 * (Medical/add.php:782, Death/add.php:779) reads exactly those keys, so it did
 * not change. No CSRF token: this is a single SELECT that writes nothing.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/permissions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Emit a refusal and stop. The reason is deliberately coarse so a caller
 * cannot use it to probe for valid sessions, roles or tenants.
 */
function opdPatientRefuse($httpCode, $message)
{
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode([
        "success" => false,
        "message" => $message
    ]);
    exit;
}

/* 1. Authenticated staff session */
if (empty($_SESSION['user_id'])) {
    opdPatientRefuse(401, 'Authentication required.');
}

/* 2. Tenant + connection come from the session via includes/db.php only. */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';

if (!($conn instanceof mysqli)) {
    opdPatientRefuse(403, 'Tenant context unavailable.');
}

/* 3. Permission - read operation supporting the certificate pages. */
if (!checkPermission('certificates', 'view')) {
    opdPatientRefuse(403, 'Access denied.');
}

header('Content-Type: application/json');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if($id<=0){
    echo json_encode([
        "success"=>false,
        "message"=>"Invalid Appointment ID"
    ]);
    exit;
}

$sql="SELECT
a.id,
a.patient_id,
a.opd_no,
a.appointment_date,
a.entity_id AS doctor_id,

p.uhid,
p.title,
p.first_name,
p.last_name,
p.guardian_name,
p.mobile,
p.sex,
p.marital_status,
p.address,
p.age_y,
p.age_m,
p.age_d,
p.dob,

u.full_name AS doctor_name,
dm.license_no

FROM appointments a

INNER JOIN patient_master p
ON p.id=a.patient_id

LEFT JOIN doctor_master dm
ON dm.id=a.entity_id

LEFT JOIN users u
ON u.id=dm.user_id

WHERE a.id=?
LIMIT 1";

$stmt=$conn->prepare($sql);

if(!$stmt){
    /* Do not echo the driver error to the client. */
    error_log('get_opd_patient: prepare failed | ' . $conn->error);
    echo json_encode([
        "success"=>false,
        "message"=>"Patient lookup failed."
    ]);
    exit;
}

$stmt->bind_param("i",$id);

if(!$stmt->execute()){
    error_log('get_opd_patient: execute failed | ' . $stmt->error);
    echo json_encode([
        "success"=>false,
        "message"=>"Patient lookup failed."
    ]);
    exit;
}

$result=$stmt->get_result();

if($result->num_rows==0){
    echo json_encode([
        "success"=>false,
        "message"=>"Record not found"
    ]);
    exit;
}
$row=$result->fetch_assoc();
$name=trim(
    ($row['title'] ?? '').' '.
    ($row['first_name'] ?? '').' '.
    ($row['last_name'] ?? '')
);
$age='';
if(!empty($row['age_y'])){
    $age.=$row['age_y'].' Year ';
}
if(!empty($row['age_m'])){
    $age.=$row['age_m'].' Month ';
}
if(!empty($row['age_d'])){
    $age.=$row['age_d'].' Day';
}
echo json_encode([
    "success"=>true,
    "appointment_id"=>$row['id'],
    "patient_id"=>$row['patient_id'],
    "opd_no"=>$row['opd_no'],
    "uhid"=>$row['uhid'],
    "patient_name"=>$name,
    "guardian_name"=>$row['guardian_name'],
    "mobile"=>$row['mobile'],
    "sex"=>$row['sex'],
    "marital_status" => ($row['marital_status'] == 'Single')
    ? 'Unmarried'
    : $row['marital_status'],
    "address"=>$row['address'],
    "age"=>trim($age),
    "age_y"=>$row['age_y'],
    "age_m"=>$row['age_m'],
    "age_d"=>$row['age_d'],
    "dob"=>$row['dob'],
    "doctor_id"=>$row['doctor_id'],
    "doctor_name"=>$row['doctor_name'],
    "doctor_registration_no"=>$row['license_no']
]);
$stmt->close();
exit;