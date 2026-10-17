<?php
/*
 * IPD PATIENT SEARCH - typeahead for the certificate pages.
 *
 * WHY THIS FILE WAS HARDENED
 * -------------------------
 * It performed no session check and no permission check. Unauthenticated it did
 * not refuse so much as CRASH: includes/db.php returns $conn = null without
 * $_SESSION['tenant_id'], so the fatal was rendered into the HTTP body because
 * display_errors was on, disclosing the absolute filesystem path. The
 * hospital_id / branch_id scoping below already reads the session only.
 *
 * Added: an authenticated staff session and the certificates 'view' permission,
 * checked before any database access. See search_opd_patients.php for the full
 * rationale and for why 'certificates' is the correct module - and for the
 * recorded defect that the host certificate pages discard their own
 * checkPermission() return value.
 *
 * UNCHANGED CONTRACT: HTTP method stays GET; the parameter is still ?q=; the
 * response is still {results:[{id,text}]}. The existing hospital/branch
 * scoping for non-Super-Admin users is unchanged.
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
function ipdPatientSearchRefuse($httpCode, $message)
{
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

/* 1. Authenticated staff session */
if (empty($_SESSION['user_id'])) {
    ipdPatientSearchRefuse(401, 'Authentication required.');
}

/* 2. Tenant + connection come from the session via includes/db.php only. */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';

if (!($conn instanceof mysqli)) {
    ipdPatientSearchRefuse(403, 'Tenant context unavailable.');
}

/* 3. Permission - read operation supporting the certificate pages. */
if (!checkPermission('certificates', 'view')) {
    ipdPatientSearchRefuse(403, 'Access denied.');
}

header('Content-Type: application/json');

$response=[
    "results"=>[]
];

$term=trim($_GET['q'] ?? '');

if($term==''){
    echo json_encode($response);
    exit;
}

$hospital_id=$_SESSION['hospital_id'] ?? 0;
$branch_id=$_SESSION['branch_id'] ?? 0;

$sql="SELECT
ia.id,
ia.patient_id,
ia.ipd_no,
p.uhid,
p.first_name,
p.last_name,
p.mobile
FROM ipd_admissions ia
INNER JOIN patient_master p
ON p.id=ia.patient_id
WHERE 1=1";

$params=[];
$types="";

if($hospital_id>0 && ($_SESSION['user_type'] ?? '')!='Super Admin'){
    $sql.=" AND ia.hospital_id=?";
    $types.="i";
    $params[]=$hospital_id;
}

if($branch_id>0 && ($_SESSION['user_type'] ?? '')!='Super Admin'){
    $sql.=" AND ia.branch_id=?";
    $types.="i";
    $params[]=$branch_id;
}

$sql.=" AND (
ia.ipd_no LIKE ?
OR p.uhid LIKE ?
OR CONCAT(IFNULL(p.first_name,''),' ',IFNULL(p.last_name,'')) LIKE ?
OR p.mobile LIKE ?
)
ORDER BY ia.id DESC
LIMIT 20";

$search="%".$term."%";

$types.="ssss";
$params[]=$search;
$params[]=$search;
$params[]=$search;
$params[]=$search;

$stmt=$conn->prepare($sql);

if(!$stmt){
    /* Do not echo the SQL or the driver error to the client. */
    error_log('search_ipd_patients: prepare failed | ' . $conn->error);
    echo json_encode([
        "results"=>[]
    ]);
    exit;
}

$stmt->bind_param($types,...$params);

if(!$stmt->execute()){
    error_log('search_ipd_patients: execute failed | ' . $stmt->error);
    echo json_encode([
        "results"=>[]
    ]);
    exit;
}

$result=$stmt->get_result();

while($row=$result->fetch_assoc()){

    $name=trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? ''));

    $response['results'][]=[
        "id"=>$row['id'],
        "text"=>$row['ipd_no']." | ".$row['uhid']." | ".$name." | ".$row['mobile']
    ];
}

$stmt->close();

echo json_encode($response);
exit;