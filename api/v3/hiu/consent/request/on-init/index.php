<?php
error_reporting(0);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['tenant_id'] = 'HMS1';

require_once __DIR__ . '/../../../../../../includes/db.php';

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responseJson(false, 'Only POST method is allowed.', [], 405);
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    responseJson(false, 'Tenant database connection is not available.', [], 500);
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    responseJson(false, 'Invalid JSON request body.', [], 400);
}

$requestIdHeader = trim($_SERVER['HTTP_REQUEST_ID'] ?? '');
$timestamp = trim($_SERVER['HTTP_TIMESTAMP'] ?? '');
$cmId = trim($_SERVER['HTTP_X_CM_ID'] ?? '');
$hiuId = trim($_SERVER['HTTP_X_HIU_ID'] ?? '');
$authorization = trim($_SERVER['HTTP_AUTHORIZATION'] ?? '');

$consentRequestId = '';

if (isset($payload['consentRequest']['id'])) {
    $consentRequestId = trim((string)$payload['consentRequest']['id']);
}

$bodyRequestId = '';

if (isset($payload['response']['requestId'])) {
    $bodyRequestId = trim((string)$payload['response']['requestId']);
}

$errorCode = '';
$errorMessage = '';

if (isset($payload['error']) && is_array($payload['error'])) {
    $errorCode = trim((string)($payload['error']['code'] ?? ''));
    $errorMessage = trim((string)($payload['error']['message'] ?? ''));
}

if ($hiuId === '') {
    responseJson(false, 'X-HIU-ID header is missing.', [], 400);
}

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
    responseJson(false, 'Unable to prepare M3 settings query.', [
        'db_error' => $conn->error
    ], 500);
}

$settingsStmt->bind_param('s', $hiuId);
$settingsStmt->execute();

$settingsResult = $settingsStmt->get_result();
$settings = $settingsResult->fetch_assoc();

$settingsStmt->close();

if (!$settings) {
    responseJson(false, 'Active M3 settings were not found for this HIU ID.', [
        'hiu_id' => $hiuId
    ], 404);
}

$hospitalId = (int)$settings['hospital_id'];

$headersForLog = [
    'REQUEST-ID' => $requestIdHeader,
    'TIMESTAMP' => $timestamp,
    'X-CM-ID' => $cmId,
    'X-HIU-ID' => $hiuId,
    'Authorization' => $authorization !== '' ? '[REDACTED]' : ''
];

$headersJson = json_encode(
    $headersForLog,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

$payloadJson = json_encode(
    $payload,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

$callbackType = 'consent_request_on_init';

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
    responseJson(false, 'Unable to prepare callback log query.', [
        'db_error' => $conn->error
    ], 500);
}

$callbackStmt->bind_param(
    'isssss',
    $hospitalId,
    $callbackType,
    $requestIdHeader,
    $consentRequestId,
    $headersJson,
    $payloadJson
);

$callbackSaved = $callbackStmt->execute();
$callbackId = $callbackStmt->insert_id;

if (!$callbackSaved) {
    $callbackError = $callbackStmt->error;
    $callbackStmt->close();

    responseJson(false, 'Unable to save callback.', [
        'db_error' => $callbackError
    ], 500);
}

$callbackStmt->close();

$matchedConsent = null;

if ($bodyRequestId !== '') {
    $matchStmt = $conn->prepare("
        SELECT
            id,
            hospital_id,
            patient_id,
            request_id,
            consent_request_id,
            status
        FROM abdm_m3_consent_requests
        WHERE request_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");

    if ($matchStmt) {
        $matchStmt->bind_param('s', $bodyRequestId);
        $matchStmt->execute();

        $matchResult = $matchStmt->get_result();
        $matchedConsent = $matchResult->fetch_assoc();

        $matchStmt->close();
    }
}

if (!$matchedConsent && $requestIdHeader !== '') {
    $matchStmt = $conn->prepare("
        SELECT
            id,
            hospital_id,
            patient_id,
            request_id,
            consent_request_id,
            status
        FROM abdm_m3_consent_requests
        WHERE request_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");

    if ($matchStmt) {
        $matchStmt->bind_param('s', $requestIdHeader);
        $matchStmt->execute();

        $matchResult = $matchStmt->get_result();
        $matchedConsent = $matchResult->fetch_assoc();

        $matchStmt->close();
    }
}

if ($matchedConsent) {
    if ($errorCode !== '' || $errorMessage !== '') {
        $status = 'Callback Error';
        $processingMessage = trim($errorCode . ' ' . $errorMessage);

        $updateStmt = $conn->prepare("
            UPDATE abdm_m3_consent_requests
            SET
                abdm_status_code = 202,
                status = ?,
                callback_response = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");

        if ($updateStmt) {
            $updateStmt->bind_param(
                'ssi',
                $status,
                $payloadJson,
                $matchedConsent['id']
            );

            $updateStmt->execute();
            $updateStmt->close();
        }

        $callbackStatus = 'Processed';
        $callbackMessage = $processingMessage;

        $updateCallback = $conn->prepare("
            UPDATE abdm_m3_callbacks
            SET
                processing_status = ?,
                processing_message = ?,
                processed_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");

        if ($updateCallback) {
            $updateCallback->bind_param(
                'ssi',
                $callbackStatus,
                $callbackMessage,
                $callbackId
            );

            $updateCallback->execute();
            $updateCallback->close();
        }

        responseJson(true, 'ABDM consent callback received with error.', [
            'callback_id' => $callbackId,
            'local_id' => (int)$matchedConsent['id'],
            'request_id' => $bodyRequestId ?: $requestIdHeader,
            'consent_request_id' => $consentRequestId,
            'error_code' => $errorCode,
            'error_message' => $errorMessage
        ], 202);
    }

    $status = 'Callback Received';

    $updateStmt = $conn->prepare("
        UPDATE abdm_m3_consent_requests
        SET
            consent_request_id = ?,
            abdm_status_code = 202,
            status = ?,
            callback_response = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");

    if (!$updateStmt) {
        responseJson(false, 'Unable to prepare consent callback update.', [
            'db_error' => $conn->error
        ], 500);
    }

    $updateStmt->bind_param(
        'sssi',
        $consentRequestId,
        $status,
        $payloadJson,
        $matchedConsent['id']
    );

    $updateStmt->execute();
    $updateStmt->close();

    $callbackStatus = 'Processed';
    $callbackMessage = 'Consent request callback processed successfully.';

    $updateCallback = $conn->prepare("
        UPDATE abdm_m3_callbacks
        SET
            processing_status = ?,
            processing_message = ?,
            processed_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");

    if ($updateCallback) {
        $updateCallback->bind_param(
            'ssi',
            $callbackStatus,
            $callbackMessage,
            $callbackId
        );

        $updateCallback->execute();
        $updateCallback->close();
    }

    responseJson(true, 'ABDM consent callback processed successfully.', [
        'callback_id' => $callbackId,
        'local_id' => (int)$matchedConsent['id'],
        'request_id' => $bodyRequestId ?: $requestIdHeader,
        'consent_request_id' => $consentRequestId,
        'hospital_id' => $matchedConsent['hospital_id'],
        'patient_id' => $matchedConsent['patient_id']
    ], 202);
}

$callbackStatus = 'Received';
$callbackMessage = 'Callback received but matching local consent request was not found.';

$updateCallback = $conn->prepare("
    UPDATE abdm_m3_callbacks
    SET
        processing_status = ?,
        processing_message = ?,
        processed_at = CURRENT_TIMESTAMP
    WHERE id = ?
");

if ($updateCallback) {
    $updateCallback->bind_param(
        'ssi',
        $callbackStatus,
        $callbackMessage,
        $callbackId
    );

    $updateCallback->execute();
    $updateCallback->close();
}

responseJson(true, 'ABDM consent callback received.', [
    'callback_id' => $callbackId,
    'request_id' => $bodyRequestId ?: $requestIdHeader,
    'consent_request_id' => $consentRequestId,
    'hospital_id' => $hospitalId,
    'matched_local_request' => false
], 202);
?>