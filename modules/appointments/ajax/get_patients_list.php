<?php
/*
 * REVISIT PATIENT PICKER - paginated patient list.
 *
 * WHY THIS FILE WAS HARDENED
 * -------------------------
 * It previously imported includes/public_tenant.php, which writes a
 * caller-supplied tenant_id straight into $_SESSION['tenant_id']
 * (public_tenant.php:33). It performed no session check and no permission
 * check. An unauthenticated caller could therefore pass ?tenant_id=HMS1,
 * satisfy the includes/db.php tenant gate, and receive a paginated list of
 * patient records - first_name, last_name, mobile and aadhaar - for that
 * tenant. Verified live before this change.
 *
 * WHAT CHANGED, AND WHAT DID NOT
 * ------------------------------
 * Added: an authenticated staff session, the patient_registration 'view'
 * permission, and tenant resolution from the session only.
 *
 * includes/public_tenant.php is NO LONGER imported here, so a caller-supplied
 * tenant_id is ignored and the tenant comes from $_SESSION['tenant_id'] via
 * includes/db.php. That file itself is untouched - it still has other callers.
 *
 * WHY patient_registration 'view' IS THE CORRECT PERMISSION HERE
 * --------------------------------------------------------------
 * This endpoint backs the existing-patient / Revisit picker on two pages:
 *   modules/registration/register.php:2595
 *   modules/appointments/appointment_form_internal.php:2702
 * The first page already requires patient_registration 'view'. The second is
 * reachable only through modules/appointments/appointment.php, which enforces
 * checkPermission('appointments','create') at appointment.php:13 and is the
 * only file that links to it (appointment.php:492 and :1116).
 *
 * In this installation 'appointments' and 'patient_registration' are granted to
 * the SAME role - rights_type_id 2 (Admin) - while rights_type_id 1 (Super
 * Admin) is bypassed in permissions.php:27. rights_type_id 3 (Manager) holds
 * no module_permissions rows at all, so it cannot reach either page through the
 * application. Requiring patient_registration 'view' therefore locks out no
 * legitimate user of this picker.
 *
 * includes/db.php is loaded BEFORE the permission check on purpose:
 * checkPermission() resolves can_view through the global $conn, so it must be
 * able to run its lookup for non-super-admin roles.
 *
 * NO CSRF TOKEN IS REQUIRED. This endpoint performs two SELECTs and writes
 * nothing, so it is a read-only request and cannot cause a state change. This
 * follows the project convention for authenticated read-only AJAX - see
 * modules/appointments/ajax/get_patient_details.php.
 *
 * UNCHANGED CONTRACT: HTTP method stays GET; the SELECT column list
 * (id, first_name, last_name, mobile, aadhaar) is unchanged; the success
 * payload is still {data, total, page, limit, total_pages}. Both callers read
 * exactly those row keys and envelope keys, so neither had to change.
 */

/* Suppress PHP diagnostics. Without this, a failure before the guards run
   would return HTTP 200 containing an absolute filesystem path. */
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
function patientListRefuse($httpCode, $message)
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => $message
    ]);
    exit;
}

/* 1. Authenticated staff session */
if (empty($_SESSION['user_id'])) {
    patientListRefuse(401, 'Authentication required.');
}

/* 2. Tenant + connection come from the session via includes/db.php only.
      A caller-supplied tenant_id is deliberately ignored. */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';

if (!($conn instanceof mysqli)) {
    patientListRefuse(403, 'Tenant context unavailable.');
}

/* 3. Permission - read operation, same permission register.php itself requires. */
if (!checkPermission('patient_registration', 'view')) {
    patientListRefuse(403, 'Access denied.');
}

header('Content-Type: application/json; charset=utf-8');

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;
$search = trim($_GET['search'] ?? '');

$where = "WHERE NOT EXISTS (
    SELECT 1
    FROM ipd_admissions ia
    WHERE ia.patient_id = patient_master.id
    AND ia.status = 'Admitted'
)";

$params = [];
$types = '';

if ($search !== '') {
    $where .= " AND (
        CONCAT_WS(' ', first_name, last_name) LIKE ?
        OR mobile LIKE ?
        OR aadhaar LIKE ?
    )";

    $searchLike = '%' . $search . '%';
    $params = [$searchLike, $searchLike, $searchLike];
    $types = 'sss';
}

$countSql = "SELECT COUNT(*) AS total FROM patient_master $where";
$countStmt = $conn->prepare($countSql);

if ($search !== '') {
    $countStmt->bind_param($types, ...$params);
}

$countStmt->execute();
$countResult = $countStmt->get_result();
$total = (int)($countResult->fetch_assoc()['total'] ?? 0);
$countStmt->close();

$dataSql = "
    SELECT
        id,
        first_name,
        last_name,
        mobile,
        aadhaar
    FROM patient_master
    $where
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
";

$dataStmt = $conn->prepare($dataSql);

if ($search !== '') {
    $dataTypes = $types . 'ii';
    $dataParams = array_merge($params, [$limit, $offset]);
    $dataStmt->bind_param($dataTypes, ...$dataParams);
} else {
    $dataStmt->bind_param('ii', $limit, $offset);
}

$dataStmt->execute();
$res = $dataStmt->get_result();

$data = [];

while ($row = $res->fetch_assoc()) {
    $data[] = $row;
}

$dataStmt->close();

$totalPages = $total > 0 ? (int)ceil($total / $limit) : 1;

echo json_encode([
    'data' => $data,
    'total' => $total,
    'page' => $page,
    'limit' => $limit,
    'total_pages' => $totalPages
]);