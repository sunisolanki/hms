<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../../includes/db.php';

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

function generateRequestId()
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

function findConsentArtifactId($callbackResponse)
{
    if ($callbackResponse === null || $callbackResponse === '') {
        return '';
    }

    if (is_string($callbackResponse)) {
        $decoded = json_decode($callbackResponse, true);

        if (!is_array($decoded)) {
            return '';
        }

        $callbackResponse = $decoded;
    }

    if (!is_array($callbackResponse)) {
        return '';
    }

    if (
        isset($callbackResponse['notification']) &&
        is_array($callbackResponse['notification'])
    ) {
        $notification = $callbackResponse['notification'];

        if (
            isset($notification['consentArtefacts']) &&
            is_array($notification['consentArtefacts'])
        ) {
            foreach ($notification['consentArtefacts'] as $artefact) {
                if (!is_array($artefact)) {
                    continue;
                }

                $id = trim((string)($artefact['id'] ?? ''));

                if ($id !== '') {
                    return $id;
                }
            }
        }
    }

    if (
        isset($callbackResponse['consentArtefacts']) &&
        is_array($callbackResponse['consentArtefacts'])
    ) {
        foreach ($callbackResponse['consentArtefacts'] as $artefact) {
            if (!is_array($artefact)) {
                continue;
            }

            $id = trim((string)($artefact['id'] ?? ''));

            if ($id !== '') {
                return $id;
            }
        }
    }

    if (
        isset($callbackResponse['consent']) &&
        is_array($callbackResponse['consent'])
    ) {
        $consent = $callbackResponse['consent'];

        if (
            isset($consent['consentId']) &&
            trim((string)$consent['consentId']) !== ''
        ) {
            return trim((string)$consent['consentId']);
        }

        if (
            isset($consent['consentDetail']) &&
            is_array($consent['consentDetail']) &&
            isset($consent['consentDetail']['consentId'])
        ) {
            $id = trim((string)$consent['consentDetail']['consentId']);

            if ($id !== '') {
                return $id;
            }
        }
    }

    return '';
}

if (!isset($_SESSION['user_id'])) {
    responseJson(false, 'Unauthorized access.', [], 401);
}

$hospitalId = isset($_GET['hospital_id'])
    ? (int)$_GET['hospital_id']
    : 0;

$consentRequestId = isset($_GET['consent_request_id'])
    ? trim($_GET['consent_request_id'])
    : '';

$requestedConsentId = isset($_GET['consent_id'])
    ? trim($_GET['consent_id'])
    : '';

if ($hospitalId <= 0) {
    responseJson(false, 'Hospital ID is required.', [], 400);
}

if ($consentRequestId === '') {
    responseJson(false, 'Consent Request ID is required.', [], 400);
}

/*
 * ---------------------------------------------------------
 * M3 SETTINGS
 * ---------------------------------------------------------
 */

$settingsStmt = $conn->prepare("
    SELECT
        id,
        hospital_id,
        environment,
        client_id,
        facility_id,
        hiu_id,
        callback_url,
        status
    FROM abdm_m3_settings
    WHERE hospital_id = ?
      AND environment = 'Sandbox'
      AND status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if (!$settingsStmt) {
    responseJson(false, 'M3 settings query prepare failed.', [
        'db_error' => $conn->error
    ], 500);
}

$settingsStmt->bind_param('i', $hospitalId);
$settingsStmt->execute();

$settingsResult = $settingsStmt->get_result();
$settings = $settingsResult
    ? $settingsResult->fetch_assoc()
    : null;

$settingsStmt->close();

if (!$settings) {
    responseJson(false, 'Active Sandbox M3 settings not found for this hospital.', [], 404);
}

$hiuId = trim((string)$settings['hiu_id']);

if ($hiuId === '') {
    responseJson(false, 'HIU ID is missing in M3 settings.', [], 500);
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
    responseJson(false, 'Consent request query prepare failed.', [
        'db_error' => $conn->error
    ], 500);
}

$requestStmt->bind_param(
    'is',
    $hospitalId,
    $consentRequestId
);

$requestStmt->execute();

$requestResult = $requestStmt->get_result();
$localRequest = $requestResult
    ? $requestResult->fetch_assoc()
    : null;

$requestStmt->close();

if (!$localRequest) {
    responseJson(false, 'Local consent request was not found.', [
        'hospital_id' => $hospitalId,
        'consent_request_id' => $consentRequestId
    ], 404);
}

/*
 * ---------------------------------------------------------
 * CONSENT ARTEFACT ID
 * ---------------------------------------------------------
 */

$consentId = $requestedConsentId;

if ($consentId === '') {
    $consentId = findConsentArtifactId(
        $localRequest['callback_response'] ?? ''
    );
}

if ($consentId === '') {
    responseJson(false, 'Consent Artefact ID is not available yet.', [
        'local_id' => (int)$localRequest['id'],
        'consent_request_id' => $consentRequestId,
        'current_status' => $localRequest['status'],
        'next_step' => 'Wait for a GRANTED consent notification containing consentArtefacts[].id, then call this API again.'
    ], 400);
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
    responseJson(false, 'M3 session query prepare failed.', [
        'db_error' => $conn->error
    ], 500);
}

$sessionStmt->bind_param(
    'i',
    $hospitalId
);

$sessionStmt->execute();

$sessionResult = $sessionStmt->get_result();
$sessionData = $sessionResult
    ? $sessionResult->fetch_assoc()
    : null;

$sessionStmt->close();

if (!$sessionData) {
    responseJson(false, 'No active ABDM M3 session found. Please authenticate with ABDM first.', [], 401);
}

$accessToken = trim(
    (string)$sessionData['access_token']
);

$tokenType = trim(
    (string)$sessionData['token_type']
);

if ($accessToken === '') {
    responseJson(false, 'ABDM access token is missing.', [], 401);
}

if ($tokenType === '') {
    $tokenType = 'bearer';
}

$authorization =
    ucfirst(strtolower($tokenType)) .
    ' ' .
    $accessToken;

/*
 * ---------------------------------------------------------
 * REQUEST ID / TIMESTAMP
 * ---------------------------------------------------------
 */

$requestId = generateRequestId();

$timestamp = gmdate(
    'Y-m-d\TH:i:s.v\Z'
);

/*
 * ---------------------------------------------------------
 * CONSENT FETCH BODY
 * ---------------------------------------------------------
 */

$payload = [
    'consentId' => $consentId
];

$payloadJson = json_encode(
    $payload,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

if ($payloadJson === false) {
    responseJson(false, 'Unable to create consent fetch payload.', [
        'json_error' => json_last_error_msg()
    ], 500);
}

/*
 * ---------------------------------------------------------
 * ABDM API
 * ---------------------------------------------------------
 */

$baseUrl = 'https://dev.abdm.gov.in';

$url = $baseUrl .
    '/api/hiecm/consent/v3/fetch';

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

$httpCode = curl_getinfo(
    $ch,
    CURLINFO_HTTP_CODE
);

curl_close($ch);

if ($response === false || $curlError !== '') {
    responseJson(false, 'ABDM consent fetch connection failed.', [
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'request_id' => $requestId,
        'consent_request_id' => $consentRequestId,
        'consent_id' => $consentId
    ], 502);
}

/*
 * ---------------------------------------------------------
 * DECODE RESPONSE
 * ---------------------------------------------------------
 */

$responseData = [];

if (trim($response) !== '') {
    $decodedResponse = json_decode(
        $response,
        true
    );

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
 * SUCCESS / ERROR
 * ---------------------------------------------------------
 */

if ($httpCode < 200 || $httpCode >= 300) {
    responseJson(false, 'ABDM consent fetch failed.', [
        'local_id' => (int)$localRequest['id'],
        'http_code' => $httpCode,
        'request_id' => $requestId,
        'consent_request_id' => $consentRequestId,
        'consent_id' => $consentId,
        'hiu_id' => $hiuId,
        'abdm_response' => $responseData
    ], $httpCode >= 400 && $httpCode < 600
        ? $httpCode
        : 502);
}

/*
 * ---------------------------------------------------------
 * SUCCESS
 * ---------------------------------------------------------
 */

responseJson(true, 'ABDM consent fetch request submitted successfully.', [
    'local_id' => (int)$localRequest['id'],
    'http_code' => $httpCode,
    'request_id' => $requestId,
    'consent_request_id' => $consentRequestId,
    'consent_id' => $consentId,
    'hiu_id' => $hiuId,
    'abdm_response' => $responseData,
    'next_step' => 'Wait for the 4.3.8 on-fetch callback at /api/v3/hiu/consent/on-fetch.'
], $httpCode === 202 ? 202 : 200);
?>