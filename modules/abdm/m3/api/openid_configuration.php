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
    SELECT environment
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

if ($environment === 'Production') {
    $baseUrl = 'https://apis.abdm.gov.in';
    $cmId = 'abdm';
} else {
    $baseUrl = 'https://dev.abdm.gov.in';
    $cmId = 'sbx';
}

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

$url = $baseUrl . '/api/hiecm/gateway/v3/.well-known/openid-configuration';

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_HTTPGET => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'REQUEST-ID: ' . $requestId,
        'TIMESTAMP: ' . $timestamp,
        'X-CM-ID: ' . $cmId
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

$responseData = json_decode($response, true);

if (!is_array($responseData)) {
    responseJson(false, 'Invalid response received from ABDM.', [
        'http_code' => $httpCode,
        'request_id' => $requestId,
        'raw_response' => $response
    ]);
}

if ($httpCode < 200 || $httpCode >= 300) {
    responseJson(false, 'OpenID Configuration request failed.', [
        'http_code' => $httpCode,
        'request_id' => $requestId,
        'abdm_response' => $responseData
    ]);
}

responseJson(true, 'OpenID Configuration fetched successfully.', [
    'http_code' => $httpCode,
    'environment' => $environment,
    'request_id' => $requestId,
    'openid_configuration' => $responseData
]);