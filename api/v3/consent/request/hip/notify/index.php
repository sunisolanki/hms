<?php
header('Content-Type: application/json');

$rawBody = file_get_contents('php://input');

$debugFile = __DIR__ . '/hip_notify_debug.log';

$debugHeaders = function_exists('getallheaders') ? getallheaders() : [];

if (isset($debugHeaders['Authorization'])) {
    $debugHeaders['Authorization'] = '[REDACTED]';
}

$debugData = [
    'received_at' => date('Y-m-d H:i:s'),
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
    'headers' => $debugHeaders,
    'body' => $rawBody
];

@file_put_contents(
    $debugFile,
    "\n============================\n" .
    json_encode(
        $debugData,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) .
    "\n============================\n",
    FILE_APPEND | LOCK_EX
);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['tenant_id'] = 'HMS1';

require_once __DIR__ . '/../../../../../../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Only POST method is allowed.'
    ]);
    exit;
}

$data = json_decode($rawBody, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON body.'
    ]);
    exit;
}

$notification = [];

if (isset($data['notification']) && is_array($data['notification'])) {
    $notification = $data['notification'];
}

if (!$notification) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Notification object is missing.'
    ]);
    exit;
}

$requestId = trim($_SERVER['HTTP_REQUEST_ID'] ?? '');
$timestamp = trim($_SERVER['HTTP_TIMESTAMP'] ?? '');
$hipId = trim($_SERVER['HTTP_X_HIP_ID'] ?? '');

if ($requestId === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'REQUEST-ID header is missing.'
    ]);
    exit;
}

if ($timestamp === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'TIMESTAMP header is missing.'
    ]);
    exit;
}

if ($hipId === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'X-HIP-ID header is missing.'
    ]);
    exit;
}

$status = strtoupper(trim((string)($notification['status'] ?? '')));
$consentId = trim((string)($notification['consentId'] ?? ''));
$createdAt = trim((string)($notification['createdAt'] ?? ''));

$consentDetail = [];

if (
    isset($notification['consentDetail']) &&
    is_array($notification['consentDetail'])
) {
    $consentDetail = $notification['consentDetail'];
}

$grantAcknowledgement = $notification['grantAcknowledgement'] ?? null;

if ($status === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Consent status is missing.'
    ]);
    exit;
}

if ($consentId === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Consent ID is missing.'
    ]);
    exit;
}

if (!$conn) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection is not available.'
    ]);
    exit;
}

$conn->set_charset('utf8mb4');

$stmt = $conn->prepare("
    SELECT
        id,
        hospital_id,
        facility_id,
        hiu_id
    FROM abdm_m3_settings
    WHERE status = 'Active'
      AND environment = 'Sandbox'
      AND (
          facility_id = ?
          OR hiu_id = ?
      )
    ORDER BY id DESC
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to verify M3 HIP configuration.'
    ]);
    exit;
}

$stmt->bind_param('ss', $hipId, $hipId);
$stmt->execute();

$result = $stmt->get_result();
$m3Settings = $result->fetch_assoc();

$stmt->close();

if (!$m3Settings) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid HIP ID or active M3 configuration not found.'
    ]);
    exit;
}

$hospitalId = (int)$m3Settings['hospital_id'];

$callbackPayload = json_encode(
    $data,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

$stmt = $conn->prepare("
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
    (?, ?, ?, ?, ?, ?, ?, ?, ?)
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to prepare callback log.'
    ]);
    exit;
}

$callbackType = 'consent_request_hip_notify';
$consentRequestId = null;
$httpMethod = 'POST';

$headersData = json_encode([
    'REQUEST-ID' => $requestId,
    'TIMESTAMP' => $timestamp,
    'X-HIP-ID' => $hipId
], JSON_UNESCAPED_SLASHES);

$processingStatus = 'Received';
$processingMessage = 'HIP consent notification received.';

$stmt->bind_param(
    'issssssss',
    $hospitalId,
    $callbackType,
    $requestId,
    $consentRequestId,
    $httpMethod,
    $headersData,
    $callbackPayload,
    $processingStatus,
    $processingMessage
);

$stmt->execute();

$callbackId = $stmt->insert_id;

$stmt->close();
$sessionStmt = $conn->prepare("
    SELECT
        access_token,
        token_type
    FROM abdm_m3_sessions
    WHERE hospital_id = ?
      AND environment = 'Sandbox'
      AND status = 'Active'
      AND (
          expires_at IS NULL
          OR expires_at > NOW()
      )
    ORDER BY id DESC
    LIMIT 1
");

if (!$sessionStmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to prepare M3 session lookup.'
    ]);
    exit;
}

$sessionStmt->bind_param('i', $hospitalId);
$sessionStmt->execute();

$sessionResult = $sessionStmt->get_result();
$sessionData = $sessionResult->fetch_assoc();

$sessionStmt->close();

if (!$sessionData) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Active M3 session not found.'
    ]);
    exit;
}

$accessToken = trim((string)($sessionData['access_token'] ?? ''));
$tokenType = trim((string)($sessionData['token_type'] ?? ''));

if ($accessToken === '') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'M3 access token is empty.'
    ]);
    exit;
}

if ($tokenType === '') {
    $tokenType = 'bearer';
}

$authorization = ucfirst(strtolower($tokenType)) . ' ' . $accessToken;

$abdmBaseUrl = 'https://dev.abdm.gov.in';

$ackUrl = $abdmBaseUrl . '/api/hiecm/consent/v3/request/hip/on-notify';

$ackTimestamp = gmdate('Y-m-d\TH:i:s.v\Z');

$ackPayload = [
    'acknowledgement' => [
        'status' => 'OK',
        'consentId' => $consentId
    ],
    'error' => null,
    'response' => [
        'requestId' => $requestId
    ]
];

$ackJson = json_encode(
    $ackPayload,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

$curl = curl_init($ackUrl);

curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $ackJson,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: ' . $authorization,
        'REQUEST-ID: ' . $requestId,
        'TIMESTAMP: ' . $ackTimestamp,
        'X-CM-ID: sbx'
    ],
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 30
]);

$ackResponse = curl_exec($curl);
$ackCurlError = curl_error($curl);
$ackHttpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);

curl_close($curl);

if ($ackCurlError !== '') {
    $processingStatus = 'Failed';
    $processingMessage = 'HIP consent acknowledgement failed: ' . $ackCurlError;

    $stmt = $conn->prepare("
        UPDATE abdm_m3_callbacks
        SET
            processing_status = ?,
            processing_message = ?,
            processed_at = CURRENT_TIMESTAMP
        WHERE id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param(
            'ssi',
            $processingStatus,
            $processingMessage,
            $callbackId
        );
        $stmt->execute();
        $stmt->close();
    }

    http_response_code(502);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to acknowledge consent notification to ABDM.',
        'callback_id' => $callbackId,
        'ack_http_code' => $ackHttpCode
    ]);
    exit;
}

if ($ackHttpCode < 200 || $ackHttpCode >= 300) {
    $processingStatus = 'Failed';
    $processingMessage = 'ABDM HIP acknowledgement rejected. HTTP ' . $ackHttpCode . ' Response: ' . $ackResponse;

    $stmt = $conn->prepare("
        UPDATE abdm_m3_callbacks
        SET
            processing_status = ?,
            processing_message = ?,
            processed_at = CURRENT_TIMESTAMP
        WHERE id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param(
            'ssi',
            $processingStatus,
            $processingMessage,
            $callbackId
        );
        $stmt->execute();
        $stmt->close();
    }

    http_response_code(502);

    echo json_encode([
        'success' => false,
        'message' => 'ABDM rejected the HIP consent acknowledgement.',
        'callback_id' => $callbackId,
        'ack_http_code' => $ackHttpCode,
        'abdm_response' => $ackResponse
    ]);
    exit;
}

$processingStatus = 'Processed';
$processingMessage = 'HIP consent notification acknowledged successfully by ABDM.';

$stmt = $conn->prepare("
    UPDATE abdm_m3_callbacks
    SET
        processing_status = ?,
        processing_message = ?,
        processed_at = CURRENT_TIMESTAMP
    WHERE id = ?
    LIMIT 1
");

if ($stmt) {
    $stmt->bind_param(
        'ssi',
        $processingStatus,
        $processingMessage,
        $callbackId
    );
    $stmt->execute();
    $stmt->close();
}

http_response_code(202);

echo json_encode([
    'success' => true,
    'message' => 'HIP consent notification received and acknowledged successfully.',
    'callback_id' => $callbackId,
    'consent_id' => $consentId,
    'status' => $status,
    'request_id' => $requestId,
    'ack_http_code' => $ackHttpCode
]);
?>