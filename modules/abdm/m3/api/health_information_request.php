<?php
header('Content-Type: application/json');

session_start();

require_once __DIR__ . '/../../../../includes/db.php';

function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

function isoUtcFromMysql(?string $date): ?string
{
    if (!$date) {
        return null;
    }

    try {
        $dt = new DateTime($date, new DateTimeZone('UTC'));
        return $dt->format('Y-m-d\TH:i:s.v\Z');
    } catch (Throwable $e) {
        return null;
    }
}
function generateRequestId(): string
{
    return sprintf(
        '%08x-%04x-%04x-%04x-%012x',
        random_int(0, 0xffffffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffffffffffff)
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse([
        'success' => false,
        'message' => 'Only GET and POST requests are allowed.'
    ], 405);
}

$hospitalId = (int)($_GET['hospital_id'] ?? $_POST['hospital_id'] ?? 0);
$consentRequestId = trim((string)($_GET['consent_request_id'] ?? $_POST['consent_request_id'] ?? ''));

if ($hospitalId <= 0) {
    jsonResponse([
        'success' => false,
        'message' => 'hospital_id is required.'
    ], 400);
}

if ($consentRequestId === '') {
    jsonResponse([
        'success' => false,
        'message' => 'consent_request_id is required.'
    ], 400);
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    jsonResponse([
        'success' => false,
        'message' => 'Database connection is not available.'
    ], 500);
}

$stmt = $conn->prepare("
    SELECT
        id,
        hospital_id,
        environment,
        client_id,
        facility_id,
        hiu_id,
        bridge_id,
        callback_url,
        data_push_url,
        status
    FROM abdm_m3_settings
    WHERE hospital_id = ?
      AND environment = 'Sandbox'
      AND status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if (!$stmt) {
    jsonResponse([
        'success' => false,
        'message' => 'Unable to prepare M3 settings query.',
        'error' => $conn->error
    ], 500);
}

$stmt->bind_param('i', $hospitalId);
$stmt->execute();

$settings = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$settings) {
    jsonResponse([
        'success' => false,
        'message' => 'Active Sandbox M3 settings not found.'
    ], 404);
}

$hiuId = trim((string)$settings['hiu_id']);

if ($hiuId === '') {
    jsonResponse([
        'success' => false,
        'message' => 'HIU ID is not configured.'
    ], 400);
}

$stmt = $conn->prepare("
    SELECT
        id,
        hospital_id,
        patient_id,
        abha_address,
        hiu_id,
        hip_id,
        hip_name,
        consent_request_id,
        callback_response,
        status,
        permission_date_from,
        permission_date_to,
        care_context_reference
    FROM abdm_m3_consent_requests
    WHERE hospital_id = ?
      AND consent_request_id = ?
    LIMIT 1
");

if (!$stmt) {
    jsonResponse([
        'success' => false,
        'message' => 'Unable to prepare consent request query.',
        'error' => $conn->error
    ], 500);
}

$stmt->bind_param('is', $hospitalId, $consentRequestId);
$stmt->execute();

$consentRequest = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$consentRequest) {
    jsonResponse([
        'success' => false,
        'message' => 'Consent request not found.'
    ], 404);
}

if (strtoupper((string)$consentRequest['status']) !== 'GRANTED') {
    jsonResponse([
        'success' => false,
        'message' => 'Consent is not GRANTED.',
        'current_status' => $consentRequest['status']
    ], 400);
}
$consentId = '';

$artefactStmt = $conn->prepare("
    SELECT consent_artefact_id
    FROM abdm_m3_consent_artefacts
    WHERE hospital_id = ?
      AND consent_request_id = ?
      AND status = 'GRANTED'
    ORDER BY id DESC
    LIMIT 1
");

if (!$artefactStmt) {
    jsonResponse([
        'success' => false,
        'message' => 'Unable to prepare Consent Artefact query.',
        'error' => $conn->error
    ], 500);
}

$artefactStmt->bind_param('is', $hospitalId, $consentRequestId);
$artefactStmt->execute();

$artefactData = $artefactStmt->get_result()->fetch_assoc();
$artefactStmt->close();

if ($artefactData && !empty($artefactData['consent_artefact_id'])) {
    $consentId = trim((string)$artefactData['consent_artefact_id']);
}

if ($consentId === '') {
    jsonResponse([
        'success' => false,
        'message' => 'Consent Artefact ID is not available in the local consent artefact table.'
    ], 400);
}

$dateFrom = isoUtcFromMysql($consentRequest['permission_date_from']);
$dateTo = isoUtcFromMysql($consentRequest['permission_date_to']);

if (!$dateFrom || !$dateTo) {
    jsonResponse([
        'success' => false,
        'message' => 'Consent permission date range is not available.'
    ], 400);
}

$callbackUrl = rtrim((string)$settings['callback_url'], '/');
$configuredDataPushUrl = trim((string)$settings['data_push_url']);

if ($configuredDataPushUrl !== '') {
    $dataPushUrl = rtrim($configuredDataPushUrl, '/');
} else {
    $dataPushUrl = $callbackUrl . '/api/v3/hiu/health-information/data-push';
}

if (!defined('OPENSSL_KEYTYPE_X25519')) {
    jsonResponse([
        'success' => false,
        'message' => 'X25519 is not available on this server.'
    ], 500);
}

$keyPair = openssl_pkey_new([
    'x25519' => []
]);

if ($keyPair === false) {
    jsonResponse([
        'success' => false,
        'message' => 'Unable to generate X25519 key pair.'
    ], 500);
}

$keyDetails = openssl_pkey_get_details($keyPair);

if ($keyDetails === false) {
    jsonResponse([
        'success' => false,
        'message' => 'Unable to read X25519 key details.'
    ], 500);
}

$publicKeyRaw = $keyDetails['x25519']['pub_key'] ?? null;

if (!is_string($publicKeyRaw) || strlen($publicKeyRaw) !== 32) {
    jsonResponse([
        'success' => false,
        'message' => 'Invalid X25519 public key generated.'
    ], 500);
}

$privateKeyPem = '';

if (!openssl_pkey_export($keyPair, $privateKeyPem)) {
    jsonResponse([
        'success' => false,
        'message' => 'Unable to export X25519 private key.'
    ], 500);
}

$keyValue = base64_encode($publicKeyRaw);

$nonceRaw = random_bytes(32);
$nonce = base64_encode($nonceRaw);

$expiryDate = new DateTime('now', new DateTimeZone('UTC'));
$expiryDate->modify('+10 minutes');
$expiry = $expiryDate->format('Y-m-d\TH:i:s.v\Z');

$requestId = generateRequestId();
$timestamp = gmdate('Y-m-d\TH:i:s.v\Z');

$payload = [
    'hiRequest' => [
        'consent' => [
            'id' => $consentId
        ],
        'dateRange' => [
    'from' => $dateFrom,
    'to' => $dateTo
],
        'dataPushUrl' => $dataPushUrl,
        'keyMaterial' => [
            'cryptoAlg' => 'ECDH',
            'curve' => 'curve25519',
            'dhPublicKey' => [
                'expiry' => $expiry,
                'parameters' => 'Curve25519/32byte random key',
                'keyValue' => $keyValue
            ],
            'nonce' => $nonce
        ]
    ]
];

$jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);

if ($jsonPayload === false) {
    jsonResponse([
        'success' => false,
        'message' => 'Unable to encode request payload.'
    ], 500);
}

$stmt = $conn->prepare("
    INSERT INTO abdm_m3_health_information_requests (
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
        key_parameters,
        key_value,
        nonce,
        expiry,
        private_key,
        request_id,
        status,
        created_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Initiated', ?)
");

if (!$stmt) {
    jsonResponse([
        'success' => false,
        'message' => 'Unable to prepare health information request insert.',
        'error' => $conn->error
    ], 500);
}

$createdBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

$stmt->bind_param(
    'iissssssssssssssi',
    $hospitalId,
    $consentRequest['patient_id'],
    $consentRequestId,
    $consentId,
    $consentRequest['care_context_reference'],
    $consentRequest['permission_date_from'],
    $consentRequest['permission_date_to'],
    $dataPushUrl,
    $payload['hiRequest']['keyMaterial']['cryptoAlg'],
    $payload['hiRequest']['keyMaterial']['curve'],
    $payload['hiRequest']['keyMaterial']['dhPublicKey']['parameters'],
    $keyValue,
    $nonce,
    $expiry,
    $privateKeyPem,
    $requestId,
    $createdBy
);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();

    jsonResponse([
        'success' => false,
        'message' => 'Unable to save health information request.',
        'error' => $error
    ], 500);
}

$localRequestId = $stmt->insert_id;
$stmt->close();

$tokenStmt = $conn->prepare("
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

if (!$tokenStmt) {
    jsonResponse([
        'success' => false,
        'message' => 'Unable to prepare session query.'
    ], 500);
}

$tokenStmt->bind_param('i', $hospitalId);
$tokenStmt->execute();

$sessionData = $tokenStmt->get_result()->fetch_assoc();
$tokenStmt->close();

if (!$sessionData) {
    jsonResponse([
        'success' => false,
        'message' => 'No active ABDM session token found.'
    ], 400);
}
$accessToken = trim((string)$sessionData['access_token']);
$tokenType = trim((string)$sessionData['token_type']);

if ($accessToken === '') {
    jsonResponse([
        'success' => false,
        'message' => 'ABDM access token is missing.'
    ], 401);
}

if ($tokenType === '') {
    $tokenType = 'bearer';
}

$authorization = ucfirst(strtolower($tokenType)) . ' ' . $accessToken;

$url = 'https://dev.abdm.gov.in/api/hiecm/data-flow/v3/health-information/request';

$headers = [
    'Content-Type: application/json',
    'Authorization: ' . $authorization,
    'REQUEST-ID: ' . $requestId,
    'TIMESTAMP: ' . $timestamp,
    'X-CM-ID: sbx',
    'X-HIU-ID: ' . $hiuId
];

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $jsonPayload,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CONNECTTIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2
]);

$responseBody = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($curlError !== '') {
    $stmt = $conn->prepare("
        UPDATE abdm_m3_health_information_requests
        SET
            status = 'Failed',
            abdm_status_code = ?,
            abdm_response = ?
        WHERE id = ?
    ");

    if ($stmt) {
        $failedCode = 0;
        $stmt->bind_param('isi', $failedCode, $curlError, $localRequestId);
        $stmt->execute();
        $stmt->close();
    }

    jsonResponse([
        'success' => false,
        'message' => 'ABDM request failed at network level.',
        'error' => $curlError,
        'local_request_id' => $localRequestId
    ], 502);
}

$decodedResponse = null;

if ($responseBody !== '') {
    $decodedResponse = json_decode($responseBody, true);
}

$storedResponse = $responseBody !== '' ? $responseBody : null;

$newStatus = ($httpCode >= 200 && $httpCode < 300) ? 'Submitted' : 'Failed';

$stmt = $conn->prepare("
    UPDATE abdm_m3_health_information_requests
    SET
        status = ?,
        abdm_status_code = ?,
        abdm_response = ?
    WHERE id = ?
");

if ($stmt) {
    $stmt->bind_param(
        'sisi',
        $newStatus,
        $httpCode,
        $storedResponse,
        $localRequestId
    );
    $stmt->execute();
    $stmt->close();
}

jsonResponse([
    'success' => ($httpCode >= 200 && $httpCode < 300),
    'message' => ($httpCode >= 200 && $httpCode < 300)
        ? 'Health Information Request submitted successfully.'
        : 'ABDM rejected the Health Information Request.',
    'http_status' => $httpCode,
    'local_request_id' => $localRequestId,
    'consent_request_id' => $consentRequestId,
    'consent_id' => $consentId,
    'care_context_reference' => $consentRequest['care_context_reference'],
    'data_push_url' => $dataPushUrl,
    'request_id' => $requestId,
    'abdm_response' => $decodedResponse ?? $responseBody
]);
?>