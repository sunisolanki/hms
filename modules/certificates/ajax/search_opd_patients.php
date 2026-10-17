<?php
/*
 * OPD PATIENT SEARCH - typeahead for the certificate pages.
 *
 * WHY THIS FILE WAS HARDENED
 * -------------------------
 * It performed no session check and no permission check. Unauthenticated it
 * did not so much refuse as CRASH: includes/db.php returns $conn = null
 * without $_SESSION['tenant_id'], so $conn->prepare() threw a fatal error that
 * was rendered into the HTTP body because display_errors was on - disclosing
 * the absolute filesystem path. No patient data was returned, but a crash is
 * not an access control.
 *
 * WHAT CHANGED, AND WHAT DID NOT
 * ------------------------------
 * Added: an authenticated staff session and the certificates 'view'
 * permission, checked before any database access.
 *
 * WHY certificates 'view' IS THE CORRECT PERMISSION
 * ---------------------------------------------------
 * This endpoint serves modules/certificates/Medical/add.php and
 * modules/certificates/Death/add.php. The module catalogue holds
 * 'certificates' (granted to rights_type_id 2; rights_type_id 1 is bypassed in
 * permissions.php:27), which covers both certificate workflows with one
 * permission.
 *
 * NOTE ON THE HOST PAGES: Medical/add.php:8 and Death/add.php:9 call
 * checkPermission('medical_certificate_add'|'death_certificate_add','add')
 * but DISCARD the return value, and neither slug exists in the modules table.
 * Those pages therefore have no effective permission gate of their own. This
 * endpoint now enforces the check that they do not. Fixing the host pages is
 * recorded separately and is out of scope here.
 *
 * UNCHANGED CONTRACT: HTTP method stays GET; the parameter is still ?q=; the
 * response is still {results:[{id,text}]}. Both callers pass the payload
 * straight back to select2, so no caller had to change.
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
function opdPatientSearchRefuse($httpCode, $message)
{
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

/* 1. Authenticated staff session */
if (empty($_SESSION['user_id'])) {
    opdPatientSearchRefuse(401, 'Authentication required.');
}

/* 2. Tenant + connection come from the session via includes/db.php only. */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';

if (!($conn instanceof mysqli)) {
    opdPatientSearchRefuse(403, 'Tenant context unavailable.');
}

/* 3. Permission - read operation supporting the certificate pages. */
if (!checkPermission('certificates', 'view')) {
    opdPatientSearchRefuse(403, 'Access denied.');
}

header('Content-Type: application/json');

$response = [
    "results" => []
];

$term = trim($_GET['q'] ?? '');

if($term==''){
    echo json_encode($response);
    exit;
}

$sql = "SELECT
a.id,
a.patient_id,
a.opd_no,
p.uhid,
p.first_name,
p.last_name,
p.mobile
FROM appointments a
INNER JOIN patient_master p ON p.id = a.patient_id
WHERE a.type='doctor'
AND a.status<>'Cancelled'
AND (
    a.opd_no LIKE ?
    OR p.uhid LIKE ?
    OR CONCAT(IFNULL(p.first_name,''),' ',IFNULL(p.last_name,'')) LIKE ?
    OR p.mobile LIKE ?
)
ORDER BY a.id DESC
LIMIT 20";

$search = "%".$term."%";

$stmt = $conn->prepare($sql);

if(!$stmt){
    /* Do not echo the SQL or the driver error to the client. */
    error_log('search_opd_patients: prepare failed | ' . $conn->error);
    echo json_encode(["results"=>[]]);
    exit;
}

$stmt->bind_param(
    "ssss",
    $search,
    $search,
    $search,
    $search
);

if(!$stmt->execute()){
    error_log('search_opd_patients: execute failed | ' . $stmt->error);
    echo json_encode(["results"=>[]]);
    exit;
}

$result = $stmt->get_result();

while($row = $result->fetch_assoc()){

    $name = trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? ''));

    $response['results'][] = [
        "id"   => $row['id'],
        "text" => $row['opd_no']." | ".$row['uhid']." | ".$name." | ".$row['mobile']
    ];
}

$stmt->close();

echo json_encode($response);
exit;