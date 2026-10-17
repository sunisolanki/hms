<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../../../../includes/db.php';

function responseJson($success, $message, $data = [])
{
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

function generateRequestId()
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

if (!isset($_SESSION['user_id'])) {
    responseJson(false, 'Unauthorized access.');
}

$hospitalId = isset($_GET['hospital_id']) ? (int)$_GET['hospital_id'] : 0;
$consentRequestId = isset($_GET['consent_request_id'])
    ? trim($_GET['consent_request_id'])
    : '';

if ($hospitalId <= 0) {
    responseJson(false, 'Hospital ID is required.');
}

if ($consentRequestId === '') {
    responseJson(false, 'Consent Request ID is required.');
}

/*
 * ---------------------------------------------------------
 * M3 SETTINGS
 * ---------------------------------------------------------
 */

$settingsStmt = $conn->prepare("
    SELECT *
    FROM abdm_m3_settings
    WHERE hospital_id = ?
      AND environment = 'Sandbox'
      AND status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if (!$settingsStmt) {
    responseJson(false, 'Database prepare failed: ' . $conn->error);
}

$settingsStmt->bind_param("i", $hospitalId);
$settingsStmt->execute();

$settingsResult = $settingsStmt->get_result();
$settings = $settingsResult ? $settingsResult->fetch_assoc() : null;

$settingsStmt->close();

if (!$settings) {
    responseJson(false, 'Active Sandbox M3 settings not found for this hospital.');
}

$hiuId = trim((string)$settings['hiu_id']);

if ($hiuId === '') {
    responseJson(false, 'HIU ID is missing in M3 settings.');
}

/*
 * ---------------------------------------------------------
 * LOCAL CONSENT REQUEST
 * ---------------------------------------------------------
 */

$requestStmt = $conn->prepare("
    SELECT
        id,
        hospital_id,
        patient_id,
        request_id,
        consent_request_id,
        status,
        abdm_status_code,
        abdm_response,
        callback_response
    FROM abdm_m3_consent_requests
    WHERE hospital_id = ?
      AND consent_request_id = ?
    ORDER BY id DESC
    LIMIT 1
");

if (!$requestStmt) {
    responseJson(false, 'Consent request query prepare failed: ' . $conn->error);
}

$requestStmt->bind_param("is", $hospitalId, $consentRequestId);
$requestStmt->execute();

$requestResult = $requestStmt->get_result();
$localRequest = $requestResult ? $requestResult->fetch_assoc() : null;

$requestStmt->close();

if (!$localRequest) {
    responseJson(false, 'Local consent request was not found for this Consent Request ID.');
}

/*
 * ---------------------------------------------------------
 * ACTIVE M3 SESSION
 * ---------------------------------------------------------
 */

$sessionStmt = $conn->prepare("
    SELECT
        id,
        access_token,
        token_type,
        expires_at
    FROM abdm_m3_sessions
    WHERE hospital_id = ?
      AND environment = 'Sandbox'
      AND status = 'Active'
      AND expires_at > NOW()
    ORDER BY id DESC
    LIMIT 1
");

if (!$sessionStmt) {
    responseJson(false, 'Session query prepare failed: ' . $conn->error);
}

$sessionStmt->bind_param("i", $hospitalId);
$sessionStmt->execute();

$sessionResult = $sessionStmt->get_result();
$sessionData = $sessionResult ? $sessionResult->fetch_assoc() : null;

$sessionStmt->close();

if (!$sessionData) {
    responseJson(false, 'No active ABDM M3 session found. Please authenticate with ABDM first.');
}

$accessToken = trim((string)$sessionData['access_token']);
$tokenType = trim((string)$sessionData['token_type']);

if ($accessToken === '') {
    responseJson(false, 'ABDM access token is missing.');
}

if ($tokenType === '') {
    $tokenType = 'bearer';
}

$authorization = ucfirst(strtolower($tokenType)) . ' ' . $accessToken;

/*
 * ---------------------------------------------------------
 * REQUEST ID / TIMESTAMP
 * ---------------------------------------------------------
 */

$requestId = generateRequestId();
$timestamp = gmdate('Y-m-d\TH:i:s.v\Z');

/*
 * ---------------------------------------------------------
 * M3 CONSENT REQUEST STATUS BODY
 * ---------------------------------------------------------
 */

$payload = [
    'consentRequestId' => $consentRequestId
];

$payloadJson = json_encode(
    $payload,
    JSON_UNESCAPED_SLASHES
);

if ($payloadJson === false) {
    responseJson(false, 'Unable to create ABDM consent status payload.');
}

/*
 * ---------------------------------------------------------
 * ABDM API
 * ---------------------------------------------------------
 */

$baseUrl = 'https://dev.abdm.gov.in';

$url = $baseUrl . '/api/hiecm/consent/v3/request/status';

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
'Authorization: ' . $authorization,
'REQUEST-ID: ' . $requestId,
'TIMESTAMP: ' . $timestamp,
'X-CM-ID: sbx',
'X-HIU-ID: ' . $hiuId
    ],
    CURLOPT_POSTFIELDS => $payloadJson,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($response === false || $curlError !== '') {
    responseJson(false, 'ABDM connection failed.', [
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'request_id' => $requestId,
        'consent_request_id' => $consentRequestId
    ]);
}

/*
 * ---------------------------------------------------------
 * DECODE RESPONSE
 * ---------------------------------------------------------
 */

$responseData = [];

if (trim($response) !== '') {
    $decodedResponse = json_decode($response, true);

    if (is_array($decodedResponse)) {
        $responseData = $decodedResponse;
    } else {
        $responseData = [
            'raw_response' => $response
        ];
    }
}

/*
 * ---------------------------------------------------------
 * DETERMINE CONSENT STATUS
 * ---------------------------------------------------------
 */

$consentStatus = '';

if (isset($responseData['status'])) {
    $consentStatus = (string)$responseData['status'];
} elseif (isset($responseData['consent']['status'])) {
    $consentStatus = (string)$responseData['consent']['status'];
} elseif (isset($responseData['notification']['status'])) {
    $consentStatus = (string)$responseData['notification']['status'];
}

/*
 * ---------------------------------------------------------
 * UPDATE LOCAL REQUEST
 * ---------------------------------------------------------
 */

$abdmResponseJson = json_encode(
    $responseData,
    JSON_UNESCAPED_SLASHES
);

$localStatus = $localRequest['status'];

if ($httpCode === 202) {
    if ($consentStatus !== '') {
        $localStatus = $consentStatus;
    } else {
        $localStatus = 'Pending Callback';
    }
} elseif ($httpCode >= 200 && $httpCode < 300) {
    if ($consentStatus !== '') {
        $localStatus = $consentStatus;
    } else {
        $localStatus = 'Status Checked';
    }
} else {
    $localStatus = 'Status Check Failed';
}

$updateStmt = $conn->prepare("
    UPDATE abdm_m3_consent_requests
    SET
        abdm_status_code = ?,
        status = ?,
        abdm_response = ?,
        updated_at = NOW()
    WHERE id = ?
    LIMIT 1
");

if (!$updateStmt) {
    responseJson(false, 'Consent status update prepare failed: ' . $conn->error, [
        'http_code' => $httpCode,
        'request_id' => $requestId,
        'consent_request_id' => $consentRequestId,
        'abdm_response' => $responseData
    ]);
}

$updateStmt->bind_param(
    "issi",
    $httpCode,
    $localStatus,
    $abdmResponseJson,
    $localRequest['id']
);

if (!$updateStmt->execute()) {
    $updateError = $updateStmt->error;
    $updateStmt->close();

    responseJson(false, 'ABDM status was received but local request could not be updated.', [
        'http_code' => $httpCode,
        'request_id' => $requestId,
        'consent_request_id' => $consentRequestId,
        'update_error' => $updateError,
        'abdm_response' => $responseData
    ]);
}

$updateStmt->close();

/*
 * ---------------------------------------------------------
 * ABDM ERROR
 * ---------------------------------------------------------
 */

if ($httpCode < 200 || $httpCode >= 300) {
    responseJson(false, 'ABDM consent request status failed.', [
        'local_id' => (int)$localRequest['id'],
        'http_code' => $httpCode,
        'request_id' => $requestId,
        'consent_request_id' => $consentRequestId,
        'status' => $consentStatus,
        'abdm_response' => $responseData
    ]);
}

/*
 * ---------------------------------------------------------
 * SUCCESS
 * ---------------------------------------------------------
 */

responseJson(true, 'ABDM consent request status fetched successfully.', [
    'local_id' => (int)$localRequest['id'],
    'http_code' => $httpCode,
    'request_id' => $requestId,
    'consent_request_id' => $consentRequestId,
    'hiu_id' => $hiuId,
    'status' => $consentStatus,
    'abdm_response' => $responseData
]);