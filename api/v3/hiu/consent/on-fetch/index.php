<?php
function debugOnFetch($message)
{
    file_put_contents(
        __DIR__ . '/on_fetch_index_debug.log',
        date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL,
        FILE_APPEND
    );
}
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['tenant_id'] = 'HMS1';
require_once __DIR__ . '/../../../../../includes/db.php';
debugOnFetch('1A - db include completed');

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

function updateCallbackProcessing($conn, $callbackId, $status, $message)
{
    $stmt = $conn->prepare("
        UPDATE abdm_m3_callbacks
        SET
            processing_status = ?,
            processing_message = ?,
            processed_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        'ssi',
        $status,
        $message,
        $callbackId
    );

    $result = $stmt->execute();

    $stmt->close();

    return $result;
}

/*
 * ---------------------------------------------------------
 * METHOD
 * ---------------------------------------------------------
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    debugOnFetch('ERROR - method is not POST');
    responseJson(false, 'Only POST method is allowed.', [], 405);
}

debugOnFetch('1B - POST method validated');

/*
 * ---------------------------------------------------------
 * DATABASE
 * ---------------------------------------------------------
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    debugOnFetch('ERROR - database connection unavailable');
    responseJson(false, 'Tenant database connection is not available.', [], 500);
}

debugOnFetch('1C - database connection available');

/*
 * ---------------------------------------------------------
 * REQUEST BODY
 * ---------------------------------------------------------
 */

$rawBody = file_get_contents('php://input');

debugOnFetch('1D - raw body received, length=' . strlen($rawBody));

$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    debugOnFetch('ERROR - invalid JSON');
    responseJson(false, 'Invalid JSON request body.', [], 400);
}

debugOnFetch('2 - JSON parsed');
debugOnFetch('2B - parsed payload=' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
/*
 * ---------------------------------------------------------
 * HEADERS
 * ---------------------------------------------------------
 */

$requestIdHeader = trim(
    $_SERVER['HTTP_REQUEST_ID'] ?? ''
);

$timestamp = trim(
    $_SERVER['HTTP_TIMESTAMP'] ?? ''
);

$hiuId = trim(
    $_SERVER['HTTP_X_HIU_ID'] ?? ''
);

$authorization = trim(
    $_SERVER['HTTP_AUTHORIZATION'] ?? ''
);

debugOnFetch(
    '2A - headers read, request_id=' .
    $requestIdHeader .
    ', hiu_id=' .
    $hiuId
);

if ($requestIdHeader === '') {
    debugOnFetch('ERROR - REQUEST-ID missing');
    responseJson(false, 'REQUEST-ID header is missing.', [], 400);
}

if ($timestamp === '') {
    debugOnFetch('ERROR - TIMESTAMP missing');
    responseJson(false, 'TIMESTAMP header is missing.', [], 400);
}

if ($hiuId === '') {
    debugOnFetch('ERROR - X-HIU-ID missing');
    responseJson(false, 'X-HIU-ID header is missing.', [], 400);
}

debugOnFetch('3 - headers validated');

/*
 * ---------------------------------------------------------
 * ON-FETCH PAYLOAD
 * ---------------------------------------------------------
 *
 * Actual ABDM structure:
 *
 * {
 *   "consent": {
 *       "status": "GRANTED",
 *       "consentDetail": {
 *           "consentId": "...",
 *           ...
 *       },
 *       "signature": "..."
 *   },
 *   "error": null,
 *   "response": {
 *       "requestId": "..."
 *   }
 * }
 *
 */

$consent = [];

if (
    isset($payload['consent']) &&
    is_array($payload['consent'])
) {
    $consent = $payload['consent'];
}

$status = strtoupper(
    trim((string)($consent['status'] ?? ''))
);

$consentDetail = [];

if (
    isset($consent['consentDetail']) &&
    is_array($consent['consentDetail'])
) {
    $consentDetail = $consent['consentDetail'];
}

$consentId = trim(
    (string)($consentDetail['consentId'] ?? '')
);

$signature = trim(
    (string)($consent['signature'] ?? '')
);

$errorData = $payload['error'] ?? null;

$responseData = $payload['response'] ?? null;

if ($status === '') {
    debugOnFetch('ERROR - consent status missing');
    responseJson(false, 'Consent status is missing.', [], 400);
}

if ($consentId === '') {
    debugOnFetch('ERROR - consent ID missing');
    responseJson(false, 'Consent ID is missing.', [], 400);
}

debugOnFetch(
    '4 - consent parsed: ' .
    $consentId .
    ', status=' .
    $status
);

$allowedStatuses = [
    'GRANTED',
    'REVOKED',
    'DENIED'
];

if (!in_array($status, $allowedStatuses, true)) {
    debugOnFetch(
        'ERROR - invalid consent status: ' .
        $status
    );

    responseJson(false, 'Invalid consent fetch status.', [
        'status' => $status,
        'allowed_statuses' => $allowedStatuses
    ], 400);
}

debugOnFetch('4A - consent status validated');

/*
 * ---------------------------------------------------------
 * M3 SETTINGS
 * ---------------------------------------------------------
 */

debugOnFetch('5 - loading M3 settings');

$settingsStmt = $conn->prepare("
    SELECT
        id,
        hospital_id,
        environment,
        hiu_id,
        facility_id,
        callback_url,
        status
    FROM abdm_m3_settings
    WHERE hiu_id = ?
      AND status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if (!$settingsStmt) {
    debugOnFetch(
        'ERROR - settings prepare failed: ' .
        $conn->error
    );

    responseJson(false, 'Unable to prepare M3 settings query.', [
        'db_error' => $conn->error
    ], 500);
}

$settingsStmt->bind_param(
    's',
    $hiuId
);

$settingsStmt->execute();

$settingsResult = $settingsStmt->get_result();

$settings = $settingsResult
    ? $settingsResult->fetch_assoc()
    : null;

$settingsStmt->close();

if (!$settings) {
    debugOnFetch(
        'ERROR - settings not found for HIU=' .
        $hiuId
    );

    responseJson(false, 'Active M3 settings were not found for this HIU ID.', [
        'hiu_id' => $hiuId
    ], 404);
}

$hospitalId = (int)$settings['hospital_id'];

$environment = $settings['environment'];

debugOnFetch(
    '5 - settings found, hospital_id=' .
    $hospitalId .
    ', environment=' .
    $environment
);

/*
 * ---------------------------------------------------------
 * LOG HEADERS
 * ---------------------------------------------------------
 */

$headersForLog = [
    'REQUEST-ID' => $requestIdHeader,
    'TIMESTAMP' => $timestamp,
    'X-HIU-ID' => $hiuId,
    'Authorization' => $authorization !== ''
        ? '[REDACTED]'
        : ''
];

$headersJson = json_encode(
    $headersForLog,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

$payloadJson = json_encode(
    $payload,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

if ($payloadJson === false) {
    debugOnFetch('ERROR - payload JSON encoding failed');

    responseJson(false, 'Unable to encode callback payload.', [], 500);
}

/*
 * ---------------------------------------------------------
 * SAVE CALLBACK
 * ---------------------------------------------------------
 */

debugOnFetch('6 - preparing callback INSERT');

$callbackType = 'consent_request_on_fetch';

$callbackStmt = $conn->prepare("
    INSERT INTO abdm_m3_callbacks
    (
        hospital_id,
        callback_type,
        request_id,
        consent_request_id,
        http_method,
        headers,
        payload,
        processing_status,
        processing_message
    )
    VALUES
    (?, ?, ?, ?, 'POST', ?, ?, 'Received', NULL)
");

if (!$callbackStmt) {
    debugOnFetch(
        'ERROR - callback INSERT prepare failed: ' .
        $conn->error
    );

    responseJson(false, 'Unable to prepare callback log query.', [
        'db_error' => $conn->error
    ], 500);
}

$callbackConsentReference = $consentId;

$callbackStmt->bind_param(
    'isssss',
    $hospitalId,
    $callbackType,
    $requestIdHeader,
    $callbackConsentReference,
    $headersJson,
    $payloadJson
);

if (!$callbackStmt->execute()) {
    $callbackError = $callbackStmt->error;

    $callbackStmt->close();

    debugOnFetch(
        'ERROR - callback INSERT failed: ' .
        $callbackError
    );

    responseJson(false, 'Unable to save callback.', [
        'db_error' => $callbackError
    ], 500);
}

$callbackId = $callbackStmt->insert_id;

$callbackStmt->close();

debugOnFetch(
    '7 - callback inserted, id=' .
    $callbackId
);

/*
 * ---------------------------------------------------------
 * FIND LOCAL CONSENT REQUEST
 * ---------------------------------------------------------
 */

debugOnFetch(
    '8 - searching local consent request for consentId=' .
    $consentId
);

$matchedConsent = null;

$matchStmt = $conn->prepare("
    SELECT
        id,
        hospital_id,
        patient_id,
        request_id,
        consent_request_id,
        status,
        callback_response,
        abdm_response
    FROM abdm_m3_consent_requests
    WHERE hospital_id = ?
      AND (
            callback_response LIKE ?
            OR abdm_response LIKE ?
      )
    ORDER BY id DESC
    LIMIT 1
");

if ($matchStmt) {
    $searchPattern = '%' . $consentId . '%';

    $matchStmt->bind_param(
        'iss',
        $hospitalId,
        $searchPattern,
        $searchPattern
    );

    $matchStmt->execute();

    $matchResult = $matchStmt->get_result();

    $matchedConsent = $matchResult
        ? $matchResult->fetch_assoc()
        : null;

    $matchStmt->close();
}

if ($matchedConsent) {
    debugOnFetch(
        '8A - local consent matched, local_id=' .
        $matchedConsent['id'] .
        ', consent_request_id=' .
        $matchedConsent['consent_request_id']
    );
} else {
    debugOnFetch(
        '8A - local consent NOT matched by callback_response/abdm_response'
    );
}

/*
 * ---------------------------------------------------------
 * FALLBACK:
 * SEARCH CONSENT ARTEFACT IN CALLBACK LOG
 * ---------------------------------------------------------
 */

if (!$matchedConsent) {
    debugOnFetch(
        '9 - starting fallback search in consent_request_notify callback'
    );

    $logMatchStmt = $conn->prepare("
        SELECT
            consent_request_id
        FROM abdm_m3_callbacks
        WHERE hospital_id = ?
          AND callback_type = 'consent_request_notify'
          AND payload LIKE ?
        ORDER BY id DESC
        LIMIT 1
    ");

    if ($logMatchStmt) {
        $searchPattern = '%' . $consentId . '%';

        $logMatchStmt->bind_param(
            'is',
            $hospitalId,
            $searchPattern
        );

        $logMatchStmt->execute();

        $logResult = $logMatchStmt->get_result();

        $logRow = $logResult
            ? $logResult->fetch_assoc()
            : null;

        $logMatchStmt->close();

        if ($logRow && !empty($logRow['consent_request_id'])) {
            $consentRequestIdFromLog =
                trim((string)$logRow['consent_request_id']);

            debugOnFetch(
                '9A - notify callback matched consent_request_id=' .
                $consentRequestIdFromLog
            );

            $requestMatchStmt = $conn->prepare("
                SELECT
                    id,
                    hospital_id,
                    patient_id,
                    request_id,
                    consent_request_id,
                    status,
                    callback_response,
                    abdm_response
                FROM abdm_m3_consent_requests
                WHERE hospital_id = ?
                  AND consent_request_id = ?
                ORDER BY id DESC
                LIMIT 1
            ");

            if ($requestMatchStmt) {
                $requestMatchStmt->bind_param(
                    'is',
                    $hospitalId,
                    $consentRequestIdFromLog
                );

                $requestMatchStmt->execute();

                $requestMatchResult =
                    $requestMatchStmt->get_result();

                $matchedConsent =
                    $requestMatchResult
                        ? $requestMatchResult->fetch_assoc()
                        : null;

                $requestMatchStmt->close();

                if ($matchedConsent) {
                    debugOnFetch(
                        '9B - fallback local consent matched, local_id=' .
                        $matchedConsent['id']
                    );
                } else {
                    debugOnFetch(
                        '9B - fallback local consent NOT matched'
                    );
                }
            } else {
                debugOnFetch(
                    'ERROR - fallback request match prepare failed: ' .
                    $conn->error
                );
            }
        } else {
            debugOnFetch(
                '9A - no matching consent_request_notify callback found'
            );
        }
    } else {
        debugOnFetch(
            'ERROR - notify callback search prepare failed: ' .
            $conn->error
        );
    }
}

/*
 * ---------------------------------------------------------
 * NO LOCAL REQUEST
 * ---------------------------------------------------------
 */

if (!$matchedConsent) {
    debugOnFetch(
        '10 - no local consent request found, keeping callback Received'
    );

    updateCallbackProcessing(
        $conn,
        $callbackId,
        'Received',
        'Consent on-fetch callback received but matching local consent request was not found.'
    );

    responseJson(true, 'ABDM consent on-fetch callback received.', [
        'callback_id' => $callbackId,
        'consent_id' => $consentId,
        'status' => $status,
        'matched_local_request' => false
    ], 202);
}

debugOnFetch(
    '10A - local consent request found, continuing update'
);

/*
 * ---------------------------------------------------------
 * SAVE FETCH RESPONSE
 * ---------------------------------------------------------
 */

$fetchData = [
    'status' => $status,
    'consentId' => $consentId,
    'consentDetail' => $consentDetail,
    'signature' => $signature,
    'error' => $errorData,
    'response' => $responseData,
    'requestId' => $requestIdHeader,
    'timestamp' => $timestamp
];

$fetchJson = json_encode(
    $fetchData,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

if ($fetchJson === false) {
    debugOnFetch(
        'ERROR - fetch response JSON encoding failed'
    );

    updateCallbackProcessing(
        $conn,
        $callbackId,
        'Failed',
        'Unable to encode consent on-fetch response.'
    );

    responseJson(false, 'Unable to encode consent on-fetch response.', [
        'callback_id' => $callbackId
    ], 500);
}

debugOnFetch('11 - fetch response prepared');
/*
 * ---------------------------------------------------------
 * SAVE CONSENT ARTEFACT
 * ---------------------------------------------------------
 */

debugOnFetch(
    '11A - saving consent artefact, consent_request_id=' .
    $matchedConsent['consent_request_id']
);

$consentArtifactJson = json_encode(
    $consentDetail,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

if ($consentArtifactJson === false) {
    debugOnFetch(
        'ERROR - consent artefact JSON encoding failed'
    );

    updateCallbackProcessing(
        $conn,
        $callbackId,
        'Failed',
        'Unable to encode consent artefact.'
    );

    responseJson(
        false,
        'Unable to encode consent artefact.',
        [
            'callback_id' => $callbackId
        ],
        500
    );
}

$artefactStmt = $conn->prepare("
    INSERT INTO abdm_m3_consent_artefacts
    (
        hospital_id,
        patient_id,
        consent_request_id,
        consent_artefact_id,
        status,
        consent_artifact,
        fetched_at
    )
    VALUES
    (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ON DUPLICATE KEY UPDATE
        status = VALUES(status),
        consent_artifact = VALUES(consent_artifact),
        fetched_at = CURRENT_TIMESTAMP,
        updated_at = CURRENT_TIMESTAMP
");

if (!$artefactStmt) {
    debugOnFetch(
        'ERROR - consent artefact INSERT prepare failed: ' .
        $conn->error
    );

    updateCallbackProcessing(
        $conn,
        $callbackId,
        'Failed',
        'Unable to prepare consent artefact save.'
    );

    responseJson(
        false,
        'Unable to prepare consent artefact save.',
        [
            'callback_id' => $callbackId,
            'db_error' => $conn->error
        ],
        500
    );
}

$artefactStmt->bind_param(
    'iissss',
    $hospitalId,
    $matchedConsent['patient_id'],
    $matchedConsent['consent_request_id'],
    $consentId,
    $status,
    $consentArtifactJson
);

if (!$artefactStmt->execute()) {
    $artefactError = $artefactStmt->error;

    $artefactStmt->close();

    debugOnFetch(
        'ERROR - consent artefact save failed: ' .
        $artefactError
    );

    updateCallbackProcessing(
        $conn,
        $callbackId,
        'Failed',
        'Unable to save consent artefact.'
    );

    responseJson(
        false,
        'Unable to save consent artefact.',
        [
            'callback_id' => $callbackId,
            'db_error' => $artefactError
        ],
        500
    );
}

$artefactStmt->close();

debugOnFetch(
    '11B - consent artefact saved successfully'
);
/*
 * ---------------------------------------------------------
 * UPDATE LOCAL CONSENT REQUEST
 * ---------------------------------------------------------
 */

debugOnFetch(
    '12 - preparing local consent UPDATE, local_id=' .
    $matchedConsent['id']
);

$updateStmt = $conn->prepare("
    UPDATE abdm_m3_consent_requests
    SET
        status = ?,
        abdm_response = ?,
        abdm_status_code = 200,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = ?
");

if (!$updateStmt) {
    debugOnFetch(
        'ERROR - consent UPDATE prepare failed: ' .
        $conn->error
    );

    updateCallbackProcessing(
        $conn,
        $callbackId,
        'Failed',
        'Unable to prepare consent fetch update.'
    );

    responseJson(false, 'Unable to prepare consent fetch update.', [
        'callback_id' => $callbackId,
        'db_error' => $conn->error
    ], 500);
}

$updateStmt->bind_param(
    'ssi',
    $status,
    $fetchJson,
    $matchedConsent['id']
);

if (!$updateStmt->execute()) {
    $updateError = $updateStmt->error;

    $updateStmt->close();

    debugOnFetch(
        'ERROR - consent UPDATE failed: ' .
        $updateError
    );

    updateCallbackProcessing(
        $conn,
        $callbackId,
        'Failed',
        'Unable to update local consent request.'
    );

    responseJson(false, 'Unable to update local consent request.', [
        'callback_id' => $callbackId,
        'db_error' => $updateError
    ], 500);
}

$updateStmt->close();

debugOnFetch(
    '13 - local consent UPDATE completed'
);

/*
 * ---------------------------------------------------------
 * MARK CALLBACK PROCESSED
 * ---------------------------------------------------------
 */

$processingMessage =
    '4.3.8 consent on-fetch callback processed successfully.';

$processingMessage .=
    ' Consent ID: ' . $consentId . '.';

$processingMessage .=
    ' Status: ' . $status . '.';

debugOnFetch(
    '14 - marking callback Processed'
);

updateCallbackProcessing(
    $conn,
    $callbackId,
    'Processed',
    $processingMessage
);

debugOnFetch(
    '15 - callback processing completed'
);

/*
 * ---------------------------------------------------------
 * RESPONSE
 * ---------------------------------------------------------
 */

responseJson(true, 'ABDM consent on-fetch callback processed successfully.', [
    'callback_id' => $callbackId,
    'local_id' => (int)$matchedConsent['id'],
    'consent_request_id' => $matchedConsent['consent_request_id'],
    'consent_id' => $consentId,
    'status' => $status,
    'consent_detail_received' => is_array($consentDetail)
]);

?>