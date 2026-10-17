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
function generateUuid()
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
function getValidSessionToken($conn, $hospitalId, $environment)
{
    $stmt = $conn->prepare("
        SELECT
            access_token,
            expires_at
        FROM abdm_m3_sessions
        WHERE hospital_id = ?
          AND environment = ?
          AND status = 'Active'
          AND access_token IS NOT NULL
          AND access_token <> ''
          AND (expires_at IS NULL OR expires_at > DATE_ADD(NOW(), INTERVAL 30 SECOND))
        ORDER BY id DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return [
            'success' => false,
            'message' => 'Unable to prepare session token query.',
            'error' => $conn->error
        ];
    }
    $stmt->bind_param('is', $hospitalId, $environment);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    if (!$row || trim((string)$row['access_token']) === '') {
        return [
            'success' => false,
            'message' => 'No valid ABDM session token is available.'
        ];
    }
    return [
        'success' => true,
        'token' => trim($row['access_token'])
    ];
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
$hiuId = trim($_SERVER['HTTP_X_HIU_ID'] ?? '');
$authorization = trim($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($requestIdHeader === '') {
    responseJson(false, 'REQUEST-ID header is missing.', [], 400);
}
if ($timestamp === '') {
    responseJson(false, 'TIMESTAMP header is missing.', [], 400);
}
if ($hiuId === '') {
    responseJson(false, 'X-HIU-ID header is missing.', [], 400);
}
$notification = [];
if (isset($payload['notification']) && is_array($payload['notification'])) {
    $notification = $payload['notification'];
}
$consentRequestId = trim((string)($notification['consentRequestId'] ?? ''));
$status = strtoupper(trim((string)($notification['status'] ?? '')));
$reason = $notification['reason'] ?? null;
$consentArtefacts = [];
if (isset($notification['consentArtefacts']) && is_array($notification['consentArtefacts'])) {
    $consentArtefacts = $notification['consentArtefacts'];
}
if ($consentRequestId === '') {
    responseJson(false, 'Consent Request ID is missing.', [], 400);
}
$allowedStatuses = ['GRANTED', 'REVOKED', 'DENIED'];
if (!in_array($status, $allowedStatuses, true)) {
    responseJson(false, 'Invalid consent notification status.', [
        'status' => $status,
        'allowed_statuses' => $allowedStatuses
    ], 400);
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
$environment = $settings['environment'];
$headersForLog = [
    'REQUEST-ID' => $requestIdHeader,
    'TIMESTAMP' => $timestamp,
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
$callbackType = 'consent_request_notify';
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
if (!$callbackStmt->execute()) {
    $callbackError = $callbackStmt->error;
    $callbackStmt->close();
    responseJson(false, 'Unable to save callback.', [
        'db_error' => $callbackError
    ], 500);
}
$callbackId = $callbackStmt->insert_id;
$callbackStmt->close();
$matchedConsent = null;
$matchStmt = $conn->prepare("
    SELECT
        id,
        hospital_id,
        patient_id,
        request_id,
        consent_request_id,
        status,
        callback_response
    FROM abdm_m3_consent_requests
    WHERE consent_request_id = ?
    ORDER BY id DESC
    LIMIT 1
");
if ($matchStmt) {
    $matchStmt->bind_param('s', $consentRequestId);
    $matchStmt->execute();
    $matchResult = $matchStmt->get_result();
    $matchedConsent = $matchResult->fetch_assoc();
    $matchStmt->close();
}
if (!$matchedConsent) {
    updateCallbackProcessing(
        $conn,
        $callbackId,
        'Received',
        'Consent notification received but matching local consent request was not found.'
    );
    responseJson(true, 'ABDM consent notification received.', [
        'callback_id' => $callbackId,
        'consent_request_id' => $consentRequestId,
        'status' => $status,
        'matched_local_request' => false
    ], 202);
}
$consentId = '';
foreach ($consentArtefacts as $artefact) {
    if (is_array($artefact) && !empty($artefact['id'])) {
        $consentId = trim((string)$artefact['id']);
        break;
    }
}
if ($consentId === '' && !empty($matchedConsent['callback_response'])) {
    $previousCallback = json_decode($matchedConsent['callback_response'], true);
    if (is_array($previousCallback)) {
        if (isset($previousCallback['consentArtefacts']) && is_array($previousCallback['consentArtefacts'])) {
            foreach ($previousCallback['consentArtefacts'] as $artefact) {
                if (is_array($artefact) && !empty($artefact['id'])) {
                    $consentId = trim((string)$artefact['id']);
                    break;
                }
            }
        }
    }
}
if ($consentId === '' && $status === 'GRANTED') {
    updateCallbackProcessing(
        $conn,
        $callbackId,
        'Failed',
        'GRANTED notification did not contain a consent artefact ID.'
    );
    responseJson(false, 'Consent artefact ID is missing for GRANTED notification.', [
        'callback_id' => $callbackId,
        'consent_request_id' => $consentRequestId
    ], 500);
}
$notificationData = [
    'status' => $status,
    'reason' => $reason,
    'consentArtefacts' => $consentArtefacts,
    'requestId' => $requestIdHeader,
    'timestamp' => $timestamp
];
$notificationJson = json_encode(
    $notificationData,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
$updateStmt = $conn->prepare("
    UPDATE abdm_m3_consent_requests
    SET
        status = ?,
        callback_response = ?,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = ?
");
if (!$updateStmt) {
    updateCallbackProcessing(
        $conn,
        $callbackId,
        'Failed',
        'Unable to prepare consent notification update.'
    );
    responseJson(false, 'Unable to prepare consent notification update.', [
        'callback_id' => $callbackId,
        'db_error' => $conn->error
    ], 500);
}
$updateStmt->bind_param(
    'ssi',
    $status,
    $notificationJson,
    $matchedConsent['id']
);
if (!$updateStmt->execute()) {
    $updateError = $updateStmt->error;
    $updateStmt->close();
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
$sessionResult = getValidSessionToken(
    $conn,
    $hospitalId,
    $environment
);
if (!$sessionResult['success']) {
    updateCallbackProcessing(
        $conn,
        $callbackId,
        'Failed',
        'Consent notification saved, but no valid ABDM session token was available for 4.3.4.'
    );
    responseJson(false, 'Consent notification saved, but ABDM session token is unavailable.', [
        'callback_id' => $callbackId,
        'consent_request_id' => $consentRequestId,
        'status' => $status
    ], 500);
}
$accessToken = $sessionResult['token'];
$baseUrl = ($environment === 'Production')
    ? 'https://apis.abdm.gov.in'
    : 'https://dev.abdm.gov.in';
$cmId = ($environment === 'Production')
    ? 'abdm'
    : 'sbx';
$notifyResponseRequestId = $requestIdHeader;
$ackRequestId = generateUuid();
$ackTimestamp = gmdate('Y-m-d\TH:i:s.v\Z');
$acknowledgementStatus = 'OK';
$acknowledgement = [
    [
        'status' => $acknowledgementStatus,
        'consentId' => $consentId
    ]
];
$ackPayload = [
    'acknowledgement' => $acknowledgement,
    'error' => null,
    'response' => [
        'requestId' => $notifyResponseRequestId
    ]
];
$ackPayloadJson = json_encode(
    $ackPayload,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
$ackUrl = $baseUrl . '/api/hiecm/consent/v3/request/hiu/on-notify';
$ch = curl_init($ackUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken,
        'REQUEST-ID: ' . $ackRequestId,
        'TIMESTAMP: ' . $ackTimestamp,
        'X-CM-ID: ' . $cmId
    ],
    CURLOPT_POSTFIELDS => $ackPayloadJson
]);
$ackResponseBody = curl_exec($ch);
$ackCurlError = curl_error($ch);
$ackHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($ackCurlError !== '' || $ackHttpCode < 200 || $ackHttpCode >= 300) {
    $failureMessage = 'Consent notification processed locally, but 4.3.4 acknowledgement failed.';
    if ($ackCurlError !== '') {
        $failureMessage .= ' cURL: ' . $ackCurlError;
    }
    if ($ackHttpCode > 0) {
        $failureMessage .= ' HTTP: ' . $ackHttpCode;
    }
    updateCallbackProcessing(
        $conn,
        $callbackId,
        'Failed',
        $failureMessage
    );
    responseJson(false, $failureMessage, [
        'callback_id' => $callbackId,
        'local_id' => (int)$matchedConsent['id'],
        'consent_request_id' => $consentRequestId,
        'status' => $status,
        'ack_http_code' => $ackHttpCode
    ], 500);
}
$ackResponseDecoded = null;
if (trim((string)$ackResponseBody) !== '') {
    $ackResponseDecoded = json_decode($ackResponseBody, true);
}
$processingMessage = '4.3.3 consent notification processed and 4.3.4 HIU acknowledgement sent successfully.';
$processingMessage .= ' ACK HTTP: ' . $ackHttpCode . '.';
$processingMessage .= ' ACK REQUEST-ID: ' . $ackRequestId . '.';
if ($ackResponseDecoded !== null) {
    $processingMessage .= ' ABDM response: ' . json_encode(
        $ackResponseDecoded,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
}
updateCallbackProcessing(
    $conn,
    $callbackId,
    'Processed',
    $processingMessage
);
responseJson(true, 'ABDM consent notification processed and acknowledgement sent successfully.', [
    'callback_id' => $callbackId,
    'local_id' => (int)$matchedConsent['id'],
    'consent_request_id' => $consentRequestId,
    'status' => $status,
    'reason' => $reason,
    'consent_id' => $consentId,
    'ack_http_code' => $ackHttpCode,
    'ack_request_id' => $ackRequestId,
    'ack_response' => $ackResponseDecoded
], 202);
?>