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
    SELECT environment, callback_url, bridge_id
    FROM abdm_m3_settings
    WHERE hospital_id = ?
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
    responseJson(false, 'Active M3 settings not found for this hospital.');
}

$environment = $settings['environment'];
$bridgeUrl = trim($settings['callback_url']);
$bridgeId = $settings['bridge_id'];

if ($bridgeUrl === '') {
    responseJson(false, 'Callback URL / Bridge URL is not configured.');
}

if ($environment === 'Production') {
    $baseUrl = 'https://apis.abdm.gov.in';
    $cmId = 'abdm';
} else {
    $baseUrl = 'https://dev.abdm.gov.in';
    $cmId = 'sbx';
}

$sessionStmt = $conn->prepare("
    SELECT id, access_token, token_type, expires_at
    FROM abdm_m3_sessions
    WHERE hospital_id = ?
      AND environment = ?
      AND status = 'Active'
      AND access_token <> ''
    ORDER BY id DESC
    LIMIT 1
");

if (!$sessionStmt) {
    responseJson(false, 'Session database prepare failed: ' . $conn->error);
}

$sessionStmt->bind_param("is", $hospitalId, $environment);
$sessionStmt->execute();

$sessionResult = $sessionStmt->get_result();
$sessionData = $sessionResult ? $sessionResult->fetch_assoc() : null;

$sessionStmt->close();

if (!$sessionData) {
    responseJson(false, 'Active ABDM Gateway session not found. Please authenticate first.');
}

if (!empty($sessionData['expires_at']) && strtotime($sessionData['expires_at']) <= time()) {
    responseJson(false, 'ABDM Gateway session has expired. Please authenticate again.');
}

$requestId = sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff),
    mt_rand(0, 0xffff)
);

$timestamp = gmdate('Y-m-d\TH:i:s.v\Z');

$url = $baseUrl . '/api/hiecm/gateway/v3/bridge/url';

$payload = [
    'url' => $bridgeUrl
];

$authorization = 'Bearer ' . trim($sessionData['access_token']);

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => 'PATCH',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Authorization: ' . $authorization,
        'REQUEST-ID: ' . $requestId,
        'TIMESTAMP: ' . $timestamp,
        'X-CM-ID: ' . $cmId,
        'Content-Type: application/json'
    ],
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

$responseData = [];

if ($response !== '') {
    $decoded = json_decode($response, true);

    if (is_array($decoded)) {
        $responseData = $decoded;
    } else {
        $responseData = [
            'raw_response' => $response
        ];
    }
}

if ($httpCode < 200 || $httpCode >= 300) {
    responseJson(false, 'Update Bridge URL request failed.', [
        'http_code' => $httpCode,
        'environment' => $environment,
        'bridge_id' => $bridgeId,
        'bridge_url' => $bridgeUrl,
        'request_id' => $requestId,
        'abdm_response' => $responseData
    ]);
}

responseJson(true, 'Bridge URL updated successfully.', [
    'http_code' => $httpCode,
    'environment' => $environment,
    'bridge_id' => $bridgeId,
    'bridge_url' => $bridgeUrl,
    'request_id' => $requestId,
    'abdm_response' => $responseData
]);