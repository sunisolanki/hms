<?php
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/abdm_helper.php';
require_once __DIR__ . '/abdm_m1_request_guard.php';

/*
 * GLOBAL ABDM SWITCH
 *
 * This endpoint is refused before any Aadhaar or ABHA OTP request, before any
 * gateway call, before any ABHA creation or verification, and before any other
 * sensitive processing, whenever the installation has ABDM switched off.
 *
 * The same refusal applies when the Global ABDM setting cannot be read at all
 * (no database connection, permission failure or malformed value): the switch
 * fails closed rather than silently allowing outbound ABDM traffic.
 *
 * Hiding the control in the browser is NOT relied upon for enforcement.
 */
if (!abdmOutboundAllowed($conn)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => abdmGetGlobalSwitch($conn)['state'] === 'error'
            ? 'ABDM is unavailable because the Global ABDM setting could not be read.'
            : 'ABDM is disabled for this installation.',
        'abdm_enabled' => false
    ]);
    exit;
}


function abdmRequest($url, $method = 'GET', $headers = [], $body = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, ABDM_TIMEOUT);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    return [
        'http_code' => $httpCode,
        'response' => $response,
        'curl_error' => $curlError
    ];
}

function generateRequestId() {
    return sprintf(
        '%08s-%04s-%04s-%04s-%012s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(6))
    );
}

function encryptWithAbdmPublicKey($value, $publicKeyBase64) {
    $publicKeyPem = "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split($publicKeyBase64, 64, "\n")
        . "-----END PUBLIC KEY-----";
    $encrypted = '';
    $result = openssl_public_encrypt(
        $value,
        $encrypted,
        $publicKeyPem,
        OPENSSL_PKCS1_OAEP_PADDING
    );
    if (!$result) {
        return false;
    }
    return base64_encode($encrypted);
}

$aadhaar = preg_replace('/\D/', '', $_POST['aadhaar'] ?? '');

if (strlen($aadhaar) !== 12) {
    echo json_encode([
        'success' => false,
        'message' => 'Aadhaar number must contain 12 digits.'
    ]);
    exit;
}

$tokenResult = abdmGetAccessToken();

if (!$tokenResult['success']) {
    // Never echo the raw token result: on failure it carries the gateway's raw
    // error body, and on an unexpected shape it could carry a token.
    // Log the transport status only - no body, no token, no identifier.
    error_log('ABDM M1 AUTH FAILED | http_code=' . (int)($tokenResult['http_code'] ?? 0));
    echo json_encode([
        'success' => false,
        'message' => 'Unable to authorise with ABDM. Please try again.'
    ]);
    exit;
}

$accessToken = $tokenResult['access_token'];

$requestId = generateRequestId();
$timestamp = gmdate('Y-m-d\TH:i:s.v\Z');

$certificateResult = abdmRequest(
    ABHA_BASE_URL . '/v3/profile/public/certificate',
    'GET',
    [
        'Authorization: Bearer ' . $accessToken,
        'REQUEST-ID: ' . $requestId,
        'TIMESTAMP: ' . $timestamp,
        'X-CM-ID: ' . ABDM_CM_ID
    ]
);

if ($certificateResult['curl_error']) {
    echo json_encode([
        'success' => false,
        'message' => 'ABDM certificate request cURL error.',
        'curl_error' => $certificateResult['curl_error'],
        'http_code' => $certificateResult['http_code']
    ]);
    exit;
}

$certificateData = json_decode($certificateResult['response'], true);

if ($certificateResult['http_code'] !== 200 || empty($certificateData['publicKey'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Unable to fetch ABDM public certificate.',
        'http_code' => $certificateResult['http_code'],
        'response' => $certificateData
    ]);
    exit;
}

$encryptedAadhaar = encryptWithAbdmPublicKey(
    $aadhaar,
    $certificateData['publicKey']
);

if ($encryptedAadhaar === false) {
    echo json_encode([
        'success' => false,
        'message' => 'Aadhaar encryption failed.'
    ]);
    exit;
}

$requestId = generateRequestId();
$timestamp = gmdate('Y-m-d\TH:i:s.v\Z');

$payload = [
    'scope' => [
        'abha-enrol'
    ],
    'loginHint' => 'aadhaar',
    'loginId' => $encryptedAadhaar,
    'otpSystem' => 'aadhaar'
];

$result = abdmRequest(
    ABHA_BASE_URL . '/v3/enrollment/request/otp',
    'POST',
    [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
        'REQUEST-ID: ' . $requestId,
        'TIMESTAMP: ' . $timestamp,
        'X-CM-ID: ' . ABDM_CM_ID
    ],
    $payload
);

if ($result['curl_error']) {
    error_log('ABDM M1 TRANSPORT ERROR');
    echo json_encode([
        'success' => false,
        'message' => 'Unable to reach ABDM.'
    ]);
    exit;
}

$data = json_decode($result['response'], true);

if ($result['http_code'] !== 200) {
    echo json_encode([
        'success' => false,
        'message' => 'ABDM Aadhaar OTP request failed.',
        'http_code' => $result['http_code'],
        'response' => $data
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => $data['message'] ?? 'OTP sent successfully.',
    'txnId' => $data['txnId'] ?? null
]);