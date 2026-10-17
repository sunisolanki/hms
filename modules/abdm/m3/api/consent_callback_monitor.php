<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
header('Content-Type: application/json; charset=utf-8');

session_start();

if (session_status() !== PHP_SESSION_ACTIVE) {
    responseJson(false, 'Unable to start session.', [], 500);
}

/*
|--------------------------------------------------------------------------
| AUTHENTICATION (Batch 1)
|--------------------------------------------------------------------------
| Rejected before any session state is touched, so an anonymous caller can
| never influence the tenant context of this request.
*/
if (empty($_SESSION['user_id'])) {
    responseJson(false, 'Authentication required.', [], 401);
}

/* Preserve the historical single-tenant default, but never overwrite a tenant
   the signed-in user actually belongs to. */
if (empty($_SESSION['tenant_id'])) {
    $_SESSION['tenant_id'] = 'HMS1';
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';

function responseJson($success, $message, $data = [], $httpCode = 200)
{
    http_response_code($httpCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$hospitalId = isset($_GET['hospital_id']) ? (int)$_GET['hospital_id'] : 0;

if ($hospitalId <= 0) {
    responseJson(false, 'Hospital ID is required.', [], 400);
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/permissions.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    responseJson(false, 'Tenant database connection is not available.', [], 500);
}

/*
|--------------------------------------------------------------------------
| AUTHENTICATION AND HOSPITAL SCOPING (Batch 1)
|--------------------------------------------------------------------------
| This monitor previously had no authentication and no authorization at all:
| session_start() ran, a hard-coded tenant was forced, and any anonymous
| caller could read consent requests - including abha_address,
| care_context_reference and consent_request_id - for any hospital by
| supplying ?hospital_id=<n>.
|
| It is now restricted to authenticated users holding the abdm_settings
| permission, and the requested hospital must be one the signed-in user is
| actually entitled to. Response shape is unchanged for authorized callers.
*/

/* ABDM settings permission. */
if (!checkPermission('abdm_settings', 'view')) {
    responseJson(false, 'Access denied.', [], 403);
}

/**
 * Resolve the hospital ids the signed-in user is entitled to.
 * A super administrator (rights_type_id 1) is entitled to every hospital;
 * everyone else is limited to the hospital that owns their own branch.
 *
 * Batch 1 correction: the branch was resolved through users.branch_id, but that
 * column is NULL for every user in this installation. The established source is
 * user_branches.branch_id, which is what the login flow itself uses
 * (index.php:323-336) to populate $_SESSION['branch_id']. Both files that carry
 * this helper must keep byte-identical bodies, because whichever is loaded first
 * defines it.
 *
 * Returns null for "no restriction" and an array (possibly empty) otherwise. An
 * empty array denies every requested hospital, so a failure can only ever fail
 * closed.
 *
 * @return array<int>|null
 */
if (!function_exists('abdmMonitorAuthorizedHospitals')) {
    function abdmMonitorAuthorizedHospitals($conn): ?array
    {
        if ((int)($_SESSION['rights_type_id'] ?? 0) === 1) {
            return null;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);

        if ($userId <= 0) {
            return [];
        }

        $stmt = $conn->prepare("
            SELECT DISTINCT bm.hospital_id
            FROM user_branches ub
            INNER JOIN branch_master bm ON bm.id = ub.branch_id
            WHERE ub.user_id = ?
        ");

        if ($stmt === false) {
            return [];
        }

        try {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
        } catch (Throwable $e) {
            error_log('consent monitor authorisation lookup failed: ' . $e->getMessage());
            return [];
        }

        $hospitalIds = [];

        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $hospitalIds[] = (int)$row['hospital_id'];
            }
        }

        $stmt->close();

        return $hospitalIds;
    }
}

$authorizedHospitals = abdmMonitorAuthorizedHospitals($conn);

if ($authorizedHospitals !== null && !in_array($hospitalId, $authorizedHospitals, true)) {
    responseJson(false, 'Access denied for the requested hospital.', [], 403);
}

$consentRequests = [];

$stmt = $conn->prepare("
    SELECT
        id,
        hospital_id,
        patient_id,
        abha_address,
        care_context_reference,
        request_id,
        consent_request_id,
        abdm_status_code,
        status,
        created_at,
        updated_at
    FROM abdm_m3_consent_requests
    WHERE hospital_id = ?
    ORDER BY id DESC
    LIMIT 10
");

if (!$stmt) {
    error_log('consent monitor: consent request prepare failed: ' . $conn->error);
    responseJson(false, 'Unable to prepare consent request query.', [], 500);
}

$stmt->bind_param('i', $hospitalId);

try {
    $stmt->execute();
    $result = $stmt->get_result();
} catch (Throwable $e) {
    error_log('consent monitor query failed: ' . $e->getMessage());
    responseJson(false, 'Unable to read consent requests.', [], 500);
}

if (!($result instanceof mysqli_result)) {
    responseJson(false, 'Unable to read consent requests.', [], 500);
}

while ($row = $result->fetch_assoc()) {
    $consentRequests[] = $row;
}

$stmt->close();

$callbacks = [];

$stmt = $conn->prepare("
    SELECT
        id,
        hospital_id,
        callback_type,
        request_id,
        consent_request_id,
        http_method,
        processing_status,
        processing_message,
        received_at,
        processed_at
    FROM abdm_m3_callbacks
    WHERE hospital_id = ?
    ORDER BY id DESC
    LIMIT 20
");

if (!$stmt) {
    error_log('consent monitor: callback prepare failed: ' . $conn->error);
    responseJson(false, 'Unable to prepare callback query.', [], 500);
}

$stmt->bind_param('i', $hospitalId);

try {
    $stmt->execute();
    $result = $stmt->get_result();
} catch (Throwable $e) {
    error_log('consent monitor callback query failed: ' . $e->getMessage());
    responseJson(false, 'Unable to read callbacks.', [], 500);
}

if (!($result instanceof mysqli_result)) {
    responseJson(false, 'Unable to read callbacks.', [], 500);
}

while ($row = $result->fetch_assoc()) {
    $callbacks[] = $row;
}

$stmt->close();

$response = [
    'hospital_id' => $hospitalId,
    'consent_requests' => $consentRequests,
    'callbacks' => $callbacks,
    'summary' => [
        'consent_request_count' => count($consentRequests),
        'callback_count' => count($callbacks)
    ]
];

responseJson(
    true,
    'Consent requests and callback status loaded successfully.',
    $response,
    200
);
?>