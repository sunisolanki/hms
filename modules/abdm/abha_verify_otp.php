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


function abdmRequest($url, $method = 'GET', $headers = [], $body = null)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => ABDM_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return [
        'http_code' => $httpCode,
        'response' => $response,
        'error' => $error
    ];
}

function generateRequestId()
{
    return sprintf(
        '%08s-%04s-%04s-%04s-%012s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(6))
    );
}

function encryptWithAbdmPublicKey($plainText, $publicKey)
{
    $publicKey = trim($publicKey);
    if (
        strpos($publicKey, 'BEGIN PUBLIC KEY') === false &&
        strpos($publicKey, 'BEGIN RSA PUBLIC KEY') === false
    ) {
        $publicKey = "-----BEGIN PUBLIC KEY-----\n" .
            chunk_split($publicKey, 64, "\n") .
            "-----END PUBLIC KEY-----";
    }
    $key = openssl_pkey_get_public($publicKey);
    if (!$key) {
        throw new Exception('Unable to load ABDM public key.');
    }
    $encrypted = '';
    if (!openssl_public_encrypt(
        $plainText,
        $encrypted,
        $key,
        OPENSSL_PKCS1_OAEP_PADDING
    )) {
        throw new Exception('ABDM OTP encryption failed.');
    }
    return base64_encode($encrypted);
}

try {
    $txnId = trim($_POST['txnId'] ?? '');
    $otp = trim($_POST['otp'] ?? '');

    if ($txnId === '') {
        throw new Exception('Transaction ID is required.');
    }

    if (!preg_match('/^\d{6}$/', $otp)) {
        throw new Exception('OTP must be 6 digits.');
    }

    $tokenResult = abdmGetAccessToken();

    if (!$tokenResult['success']) {
        throw new Exception($tokenResult['error'] ?? 'ABDM authentication failed.');
    }

    $accessToken = $tokenResult['access_token'];

    $certificateResult = abdmRequest(
        ABHA_BASE_URL . '/v3/profile/public/certificate',
        'GET',
        [
            'Authorization: Bearer ' . $accessToken,
            'REQUEST-ID: ' . generateRequestId(),
            'TIMESTAMP: ' . gmdate('Y-m-d\TH:i:s.v\Z'),
            'X-CM-ID: ' . ABDM_CM_ID
        ]
    );

    if ($certificateResult['error']) {
        throw new Exception('Unable to connect to ABDM public certificate API.');
    }

    if ($certificateResult['http_code'] !== 200) {
        echo json_encode([
            'success' => false,
            'message' => 'Unable to fetch ABDM public certificate.',
            'http_code' => $certificateResult['http_code'],
            'response' => json_decode($certificateResult['response'], true) ?? $certificateResult['response']
        ]);
        exit;
    }

    $certificateData = json_decode($certificateResult['response'], true);

    if (!is_array($certificateData) || empty($certificateData['publicKey'])) {
        throw new Exception('ABDM public certificate response does not contain publicKey.');
    }

    $encryptedOtp = encryptWithAbdmPublicKey(
        $otp,
        $certificateData['publicKey']
    );

    $payload = [
        'scope' => [
            'abha-login',
            'aadhaar-verify'
        ],
        'authData' => [
            'authMethods' => [
                'otp'
            ],
            'otp' => [
                'txnId' => $txnId,
                'otpValue' => $encryptedOtp
            ]
        ]
    ];

    $result = abdmRequest(
        ABHA_BASE_URL . '/v3/profile/login/verify',
        'POST',
        [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $accessToken,
            'REQUEST-ID: ' . generateRequestId(),
            'TIMESTAMP: ' . gmdate('Y-m-d\TH:i:s.v\Z'),
            'X-CM-ID: ' . ABDM_CM_ID
        ],
        json_encode($payload)
    );

    $response = json_decode($result['response'], true);

    if ($result['error']) {
        throw new Exception('ABDM OTP verification connection failed.');
    }

    if ($result['http_code'] !== 200) {
        // Do not return the raw gateway body to the caller. Log the status only.
        error_log('ABDM M1 VERIFY FAILED | http_code=' . (int)$result['http_code']);
        echo json_encode([
            'success' => false,
            'message' => 'ABDM OTP verification failed.',
            'http_code' => $result['http_code']
        ]);
        exit;
    }

    if (
        isset($response['authResult']) &&
        strtolower($response['authResult']) !== 'success'
    ) {
        echo json_encode([
            'success' => false,
            'message' => $response['message'] ?? 'ABDM OTP verification failed.',
            'http_code' => $result['http_code']
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => $response['message'] ?? 'ABHA verified successfully.',
        'response' => $response
    ]);
} catch (Throwable $e) {
    // Log the exception class only. The message can carry a gateway error body,
    // and this endpoint must not write that to the error log either.
    error_log('ABDM M1 VERIFY ERROR | class=' . get_class($e));
    echo json_encode([
        'success' => false,
        'message' => 'ABDM OTP verification failed.'
    ]);
}