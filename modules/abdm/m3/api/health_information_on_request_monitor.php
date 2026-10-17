<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

/*
|--------------------------------------------------------------------------
| AUTHENTICATION (Batch 1)
|--------------------------------------------------------------------------
| This monitor previously never started a session and never checked a
| permission: any anonymous caller could read health-information requests -
| including data_push_url, key material and the last callback payload - for
| any hospital by supplying ?hospital_id=<n>.
|
| Rejected before any session state is touched.
*/
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    responseJson([
        'success' => false,
        'message' => 'Authentication required.'
    ], 401);
}

/* Preserve the historical single-tenant default, but never overwrite a tenant
   the signed-in user actually belongs to. */
if (empty($_SESSION['tenant_id'])) {
    $_SESSION['tenant_id'] = 'HMS1';
}

/* Batch 1: use the same document-root include convention as
   consent_callback_monitor.php. __DIR__-relative includes break whenever the
   file is reached through a rewrite or moved, and DOCUMENT_ROOT is the
   convention used by includes/permissions.php itself. */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/permissions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';

function responseJson(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    responseJson([
        'success' => false,
        'message' => 'Database connection is not available.'
    ], 500);
}

/* ABDM settings permission. */
if (!checkPermission('abdm_settings', 'view')) {
    responseJson([
        'success' => false,
        'message' => 'Access denied.'
    ], 403);
}

$hospitalId = (int)($_GET['hospital_id'] ?? 0);
$localRequestId = (int)($_GET['local_request_id'] ?? 0);

if ($hospitalId <= 0) {
    responseJson([
        'success' => false,
        'message' => 'Hospital ID is required.'
    ], 400);
}

if ($localRequestId <= 0) {
    responseJson([
        'success' => false,
        'message' => 'Local Health Information Request ID is required.'
    ], 400);
}

/**
 * Resolve the hospital ids the signed-in user is entitled to.
 * A super administrator is entitled to every hospital; everyone else is limited
 * to the hospital that owns their own branch.
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
    responseJson([
        'success' => false,
        'message' => 'Access denied for the requested hospital.'
    ], 403);
}

$stmt = $conn->prepare("
    SELECT
        id,
        hospital_id,
        patient_id,
        consent_request_id,
        consent_id,
        care_context_reference,
        date_from,
        date_to,
        data_push_url,
        crypto_alg,
        curve,
        transaction_id,
        request_id,
        status,
        abdm_status_code,
        abdm_response,
        callback_response,
        data_response,
        created_at,
        updated_at
    FROM abdm_m3_health_information_requests
    WHERE id = ?
      AND hospital_id = ?
    LIMIT 1
");

if (!$stmt) {
    error_log('health monitor: prepare failed: ' . $conn->error);
    responseJson([
        'success' => false,
        'message' => 'Failed to prepare health request query.'
    ], 500);
}

$stmt->bind_param(
    'ii',
    $localRequestId,
    $hospitalId
);

try {
    $stmt->execute();
} catch (Throwable $e) {
    error_log('health monitor query failed: ' . $e->getMessage());
    responseJson([
        'success' => false,
        'message' => 'Unable to read the health request.'
    ], 500);
}

$result = $stmt->get_result();

if (!($result instanceof mysqli_result)) {
    responseJson([
        'success' => false,
        'message' => 'Unable to read the health request.'
    ], 500);
}

$healthRequest = $result->fetch_assoc();

$stmt->close();

if (!$healthRequest) {
    responseJson([
        'success' => false,
        'message' => 'Health Information Request not found.'
    ], 404);
}

$callback = null;

$stmt = $conn->prepare("
    SELECT
        id,
        callback_type,
        request_id,
        consent_request_id,
        http_method,
        processing_status,
        processing_message,
        received_at,
        processed_at,
        payload
    FROM abdm_m3_callbacks
    WHERE hospital_id = ?
      AND callback_type = 'health_information_on_request'
      AND request_id = ?
    ORDER BY id DESC
    LIMIT 1
");

if ($stmt) {
    $requestId = (string)($healthRequest['request_id'] ?? '');

    $stmt->bind_param(
        'is',
        $hospitalId,
        $requestId
    );

    try {
        $stmt->execute();
        $result = $stmt->get_result();
    } catch (Throwable $e) {
        error_log('health monitor callback query failed: ' . $e->getMessage());
        $result = null;
    }

    if ($result instanceof mysqli_result) {
        if ($row = $result->fetch_assoc()) {
            $callback = $row;
        }
    }

    $stmt->close();
}

$callbackReceived = false;

if ($callback !== null) {
    $callbackReceived = true;
}

if (
    !$callbackReceived &&
    !empty($healthRequest['callback_response'])
) {
    $callbackReceived = true;
}

$callbackData = null;

if (!empty($healthRequest['callback_response'])) {
    $decodedCallback = json_decode(
        $healthRequest['callback_response'],
        true
    );

    if (is_array($decodedCallback)) {
        $callbackData = $decodedCallback;
    }
}

responseJson([
    'success' => true,
    'message' => $callbackReceived
        ? 'Health Information on-request callback received.'
        : 'No on-request callback has been received yet.',
    'health_request' => $healthRequest,
    'callback_received' => $callbackReceived,
    'callback_source' => $callback !== null
        ? 'abdm_m3_callbacks'
        : (!empty($healthRequest['callback_response'])
            ? 'abdm_m3_health_information_requests'
            : null),
    'callback' => $callback,
    'callback_data' => $callbackData
]);