<?php
/*
 * IPD PATIENT DETAIL - populates the certificate form from one admission.
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
 * invalid-id / not-found paths still return {success:false,message:...}. The
 * callers (Medical/add.php:814, Death/add.php:814) read exactly those keys, so
 * they did not change. No CSRF token: this is a single SELECT that writes
 * nothing.
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
function ipdPatientRefuse($httpCode, $message)
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
    ipdPatientRefuse(401, 'Authentication required.');
}

/* 2. Tenant + connection come from the session via includes/db.php only. */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';

if (!($conn instanceof mysqli)) {
    ipdPatientRefuse(403, 'Tenant context unavailable.');
}

/* 3. Permission - read operation supporting the certificate pages. */
if (!checkPermission('certificates', 'view')) {
    ipdPatientRefuse(403, 'Access denied.');
}

header('Content-Type: application/json');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if($id<=0){
    echo json_encode([
        "success"=>false,
        "message"=>"Invalid IPD Admission ID"
    ]);
    exit;
}

$sql="SELECT
ia.id,
ia.patient_id,
ia.appointment_id,
ia.ipd_no,
ia.doctor_id,

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
dm.license_no AS doctor_registration_no

FROM ipd_admissions ia

INNER JOIN patient_master p
ON p.id=ia.patient_id

LEFT JOIN doctor_master dm
ON dm.id=ia.doctor_id

LEFT JOIN users u
ON u.id=dm.user_id

WHERE ia.id=?
LIMIT 1";

$stmt=$conn->prepare($sql);

if(!$stmt){
    /* Do not echo the SQL or the driver error to the client. */
    error_log('get_ipd_patient: prepare failed | ' . $conn->error);
    echo json_encode([
        "success"=>false,
        "message"=>"Patient lookup failed."
    ]);
    exit;
}

$stmt->bind_param("i",$id);

if(!$stmt->execute()){
    error_log('get_ipd_patient: execute failed | ' . $stmt->error);
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

$patient_name=trim(
    ($row['title'] ?? '').
    ' '.
    ($row['first_name'] ?? '').
    ' '.
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

    "ipd_admission_id"=>$row['id'],
    "appointment_id"=>$row['appointment_id'],
    "patient_id"=>$row['patient_id'],

    "ipd_no"=>$row['ipd_no'],

    "uhid"=>$row['uhid'],

    "patient_name"=>$patient_name,

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

    "doctor_registration_no"=>$row['doctor_registration_no']
]);

$stmt->close();

$conn->close();

exit;