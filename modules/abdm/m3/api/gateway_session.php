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

if (!isset($_SESSION['user_id'])) {
    responseJson(false, 'Unauthorized access.');
}

$hospitalId = isset($_GET['hospital_id']) ? (int)$_GET['hospital_id'] : 0;

if ($hospitalId <= 0) {
    responseJson(false, 'Hospital ID is required.');
}

$stmt = $conn->prepare("
    SELECT *
    FROM abdm_m3_settings
    WHERE hospital_id = ?
      AND environment = 'Sandbox'
      AND status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if (!$stmt) {
    responseJson(false, 'Database prepare failed: ' . $conn->error);
}

$stmt->bind_param("i", $hospitalId);
$stmt->execute();

$result = $stmt->get_result();
$settings = $result ? $result->fetch_assoc() : null;

$stmt->close();

if (!$settings) {
    responseJson(false, 'Active Sandbox M3 settings not found for this hospital.');
}

$clientId = trim((string)$settings['client_id']);
$clientSecret = trim((string)$settings['client_secret']);

if ($clientId === '') {
    responseJson(false, 'Client ID is missing in M3 settings.');
}

if ($clientSecret === '') {
    responseJson(false, 'Client Secret is missing in M3 settings.');
}

$baseUrl = 'https://dev.abdm.gov.in';
$cmId = 'sbx';

$requestId = sprintf(
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

$timestamp = gmdate('Y-m-d\TH:i:s.v\Z');

$url = $baseUrl . '/api/hiecm/gateway/v3/sessions';

$payload = [
    'clientId' => $clientId,
    'clientSecret' => $clientSecret,
    'grantType' => 'client_credentials'
];

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'REQUEST-ID: ' . $requestId,
        'TIMESTAMP: ' . $timestamp,
        'X-CM-ID: ' . $cmId
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
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
        'curl_error' => $curlError,
        'request_id' => $requestId
    ]);
}

$responseData = json_decode($response, true);

if (!is_array($responseData)) {
    responseJson(false, 'Invalid response received from ABDM.', [
        'http_code' => $httpCode,
        'request_id' => $requestId,
        'raw_response' => $response
    ]);
}

if ($httpCode < 200 || $httpCode >= 300) {
    responseJson(false, 'ABDM authentication failed.', [
        'http_code' => $httpCode,
        'request_id' => $requestId,
        'abdm_response' => $responseData
    ]);
}

$accessToken = $responseData['accessToken'] ?? '';
$tokenType = $responseData['tokenType'] ?? 'bearer';
$expiresIn = isset($responseData['expiresIn']) ? (int)$responseData['expiresIn'] : 0;
$refreshToken = $responseData['refreshToken'] ?? '';
$refreshExpiresIn = isset($responseData['refreshExpiresIn']) ? (int)$responseData['refreshExpiresIn'] : 0;

if ($accessToken === '') {
    responseJson(false, 'ABDM response did not contain an access token.', [
        'http_code' => $httpCode,
        'request_id' => $requestId,
        'abdm_response' => $responseData
    ]);
}

$expiresAt = $expiresIn > 0
    ? date('Y-m-d H:i:s', time() + $expiresIn)
    : null;

$refreshExpiresAt = $refreshExpiresIn > 0
    ? date('Y-m-d H:i:s', time() + $refreshExpiresIn)
    : null;

$conn->begin_transaction();

try {
    $expireStmt = $conn->prepare("
        UPDATE abdm_m3_sessions
        SET status = 'Expired'
        WHERE hospital_id = ?
          AND environment = 'Sandbox'
          AND status = 'Active'
    ");

    if (!$expireStmt) {
        throw new Exception('Unable to prepare old session update.');
    }

    $expireStmt->bind_param("i", $hospitalId);
    $expireStmt->execute();
    $expireStmt->close();

    $insertStmt = $conn->prepare("
        INSERT INTO abdm_m3_sessions
        (
            hospital_id,
            environment,
            access_token,
            token_type,
            expires_in,
            expires_at,
            refresh_token,
            refresh_expires_in,
            refresh_expires_at,
            status
        )
        VALUES (?, 'Sandbox', ?, ?, ?, ?, ?, ?, ?, 'Active')
    ");

    if (!$insertStmt) {
        throw new Exception('Unable to prepare session insert.');
    }

    $insertStmt->bind_param(
    "ississis",
    $hospitalId,
    $accessToken,
    $tokenType,
    $expiresIn,
    $expiresAt,
    $refreshToken,
    $refreshExpiresIn,
    $refreshExpiresAt
);

    if (!$insertStmt->execute()) {
        throw new Exception('Unable to save ABDM session: ' . $insertStmt->error);
    }

    $sessionId = $insertStmt->insert_id;

    $insertStmt->close();

    $conn->commit();

} catch (Exception $e) {

    $conn->rollback();

    responseJson(false, $e->getMessage(), [
        'request_id' => $requestId
    ]);
}

responseJson(true, 'ABDM Gateway authentication successful.', [
    'session_id' => $sessionId,
    'hospital_id' => $hospitalId,
    'environment' => 'Sandbox',
    'token_type' => $tokenType,
    'expires_in' => $expiresIn,
    'expires_at' => $expiresAt,
    'refresh_expires_in' => $refreshExpiresIn,
    'refresh_expires_at' => $refreshExpiresAt,
    'request_id' => $requestId
]);