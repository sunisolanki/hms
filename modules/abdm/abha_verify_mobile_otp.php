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

function encryptWithAbdmPublicKey($value, $publicKeyBase64)
{
    $publicKeyPem =
        "-----BEGIN PUBLIC KEY-----\n" .
        chunk_split($publicKeyBase64, 64, "\n") .
        "-----END PUBLIC KEY-----";

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

$txnId = trim($_POST['txnId'] ?? '');
$otp = preg_replace('/\D/', '', $_POST['otp'] ?? '');

if ($txnId === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Transaction ID is required.'
    ]);
    exit;
}

if (strlen($otp) !== 6) {
    echo json_encode([
        'success' => false,
        'message' => 'OTP must contain 6 digits.'
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| 1. Get ABDM gateway access token
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| 2. Get ABDM public certificate
|--------------------------------------------------------------------------
*/

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
        'curl_error' => $certificateResult['curl_error']
    ]);
    exit;
}

$certificateData = json_decode(
    $certificateResult['response'],
    true
);

if (
    $certificateResult['http_code'] < 200 ||
    $certificateResult['http_code'] >= 300 ||
    empty($certificateData['publicKey'])
) {
    echo json_encode([
        'success' => false,
        'message' => 'Unable to fetch ABDM public certificate.',
        'http_code' => $certificateResult['http_code'],
        'response' => $certificateData
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| 3. Encrypt OTP
|--------------------------------------------------------------------------
*/

$encryptedOtp = encryptWithAbdmPublicKey(
    $otp,
    $certificateData['publicKey']
);

if ($encryptedOtp === false) {
    echo json_encode([
        'success' => false,
        'message' => 'OTP encryption failed.'
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| 4. Verify Mobile OTP
|--------------------------------------------------------------------------
| POST /v3/profile/login/verify
|
| Returns:
|   - token     → T-token (JWT with "typ":"Transfer")
|   - accounts  → ABHA numbers linked to mobile
*/

$payload = [
    'scope' => [
        'abha-login',
        'mobile-verify'
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

$requestId = generateRequestId();
$timestamp = gmdate('Y-m-d\TH:i:s.v\Z');

$result = abdmRequest(
    ABHA_BASE_URL . '/v3/profile/login/verify',
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
    echo json_encode([
        'success' => false,
        'message' => 'ABDM mobile OTP verification cURL error.',
        'curl_error' => $result['curl_error']
    ]);
    exit;
}

$data = json_decode($result['response'], true);

if (
    $result['http_code'] < 200 ||
    $result['http_code'] >= 300
) {
    echo json_encode([
        'success' => false,
        'message' =>
            $data['error']['message'] ??
            $data['message'] ??
            'ABDM mobile OTP verification failed.',
        'http_code' => $result['http_code'],
        'response' => $data
    ]);
    exit;
}

if (($data['authResult'] ?? '') !== 'success') {
    echo json_encode([
        'success' => false,
        'message' =>
            $data['message'] ??
            'ABDM mobile OTP verification failed.',
        'response' => $data
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| 5. Get T-token returned by login/verify
|--------------------------------------------------------------------------
|
| In the MOBILE + mobile flow:
|   - $data['token'] IS the T-token
|     (its JWT payload contains "typ":"Transfer")
|   - There is NO separate 'tToken' or 'T-token' field in this response.
|
*/

$tToken = trim(
    $data['token'] ??
    $data['tToken'] ??
    $data['T-token'] ??
    ''
);

if ($tToken === '') {
    echo json_encode([
        'success' => false,
        'message' =>
            'OTP verified successfully, but ABDM did not return the required T-token.',
        'response_keys' => array_keys($data),
        'response' => $data
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| 6. Get ABHA accounts returned by Mobile OTP verification
|--------------------------------------------------------------------------
*/

$accounts = $data['accounts'] ?? [];

if (!is_array($accounts) || empty($accounts)) {
    echo json_encode([
        'success' => false,
        'message' =>
            'OTP verified successfully, but no ABHA account was returned by ABDM.',
        'response' => $data
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| 7. Select ACTIVE ABHA account
|--------------------------------------------------------------------------
*/

$selectedAccount = null;

foreach ($accounts as $account) {
    if (
        is_array($account) &&
        strtoupper(trim($account['status'] ?? '')) === 'ACTIVE'
    ) {
        $selectedAccount = $account;
        break;
    }
}

if ($selectedAccount === null) {
    $selectedAccount = $accounts[0];
}

$abhaNumber = trim(
    $selectedAccount['ABHANumber'] ??
    $selectedAccount['abhaNumber'] ??
    ''
);

if ($abhaNumber === '') {
    echo json_encode([
        'success' => false,
        'message' =>
            'ABHA account was returned, but ABHA number was not found.',
        'account' => $selectedAccount
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| 8. Validate and preserve ABHA number format
|--------------------------------------------------------------------------
|
| ABDM returns the ABHA number as "91-3143-4211-7459".
| The Verify User endpoint expects this hyphenated format.
|
| If ABDM returns the number WITHOUT hyphens, we reformat it into
| the standard XX-XXXX-XXXX-XXXX pattern.
|
*/

$abhaDigits = preg_replace('/\D/', '', $abhaNumber);

if (strlen($abhaDigits) !== 14) {
    echo json_encode([
        'success' => false,
        'message' =>
            'Invalid ABHA number returned by ABDM.',
        'abhaNumber' => $abhaNumber,
        'digits' => $abhaDigits
    ]);
    exit;
}

// Always send the hyphenated format to ABDM
$abhaNumberForRequest =
    substr($abhaDigits, 0, 2) . '-' .
    substr($abhaDigits, 2, 4) . '-' .
    substr($abhaDigits, 6, 4) . '-' .
    substr($abhaDigits, 10, 4);

/*
|--------------------------------------------------------------------------
| 9. Verify User
|--------------------------------------------------------------------------
| POST /v3/profile/login/verify/user
|
| Headers:
|   Authorization: Bearer <gateway access token>
|   T-token:       Bearer <T-token from step 4>
|
| Body:
|   ABHANumber = "XX-XXXX-XXXX-XXXX" (hyphenated, 14-digit ABHA number)
|   txnId      = transaction ID from the OTP request
*/

$verifyUserPayload = [
    'ABHANumber' => $abhaNumberForRequest,
    'txnId' => $txnId
];

$requestId = generateRequestId();
$timestamp = gmdate('Y-m-d\TH:i:s.v\Z');

$verifyUserResult = abdmRequest(
    ABHA_BASE_URL . '/v3/profile/login/verify/user',
    'POST',
    [
        'Authorization: Bearer ' . $accessToken,
        'T-token: Bearer ' . $tToken,
        'Content-Type: application/json',
        'REQUEST-ID: ' . $requestId,
        'TIMESTAMP: ' . $timestamp,
        'X-CM-ID: ' . ABDM_CM_ID
    ],
    $verifyUserPayload
);

if ($verifyUserResult['curl_error']) {
    echo json_encode([
        'success' => false,
        'message' => 'ABDM Verify User cURL error.',
        'curl_error' => $verifyUserResult['curl_error']
    ]);
    exit;
}

$verifyUserData = json_decode(
    $verifyUserResult['response'],
    true
);

if (
    $verifyUserResult['http_code'] < 200 ||
    $verifyUserResult['http_code'] >= 300
) {
    echo json_encode([
        'success' => false,
        'message' =>
            $verifyUserData['error']['message'] ??
            $verifyUserData['message'] ??
            $verifyUserData['ABHANumber'] ??
            'ABDM Verify User failed.',
        'http_code' => $verifyUserResult['http_code'],
        'response' => $verifyUserData
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| 10. Get final user X-token from Verify User response
|--------------------------------------------------------------------------
*/

$userXToken = trim(
    $verifyUserData['token'] ??
    $verifyUserData['xToken'] ??
    $verifyUserData['x-token'] ??
    ''
);

if ($userXToken === '') {
    echo json_encode([
        'success' => false,
        'message' =>
            'ABDM Verify User succeeded, but final X-token was not returned.',
        'response' => $verifyUserData
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| 11. Fetch full ABHA profile
|--------------------------------------------------------------------------
| GET /v3/profile/account
|
| Headers:
|   Authorization: Bearer <gateway access token>
|   X-token:       Bearer <user X-token from step 10>
*/

$requestId = generateRequestId();
$timestamp = gmdate('Y-m-d\TH:i:s.v\Z');

$profileResult = abdmRequest(
    ABHA_BASE_URL . '/v3/profile/account',
    'GET',
    [
        'Authorization: Bearer ' . $accessToken,
        'X-token: Bearer ' . $userXToken,
        'REQUEST-ID: ' . $requestId,
        'TIMESTAMP: ' . $timestamp,
        'X-CM-ID: ' . ABDM_CM_ID
    ]
);

if ($profileResult['curl_error']) {
    echo json_encode([
        'success' => false,
        'message' => 'ABDM profile request cURL error.',
        'curl_error' => $profileResult['curl_error']
    ]);
    exit;
}

$profileData = json_decode(
    $profileResult['response'],
    true
);

if (
    $profileResult['http_code'] < 200 ||
    $profileResult['http_code'] >= 300
) {
    echo json_encode([
        'success' => false,
        'message' =>
            $profileData['error']['message'] ??
            $profileData['message'] ??
            'Unable to fetch ABHA profile.',
        'http_code' => $profileResult['http_code'],
        'response' => $profileData
    ]);
    exit;
}

if (!is_array($profileData)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid ABHA profile response.'
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| 12. Return ABHA profile to Registration page
|--------------------------------------------------------------------------
*/

echo json_encode([
    'success' => true,
    'message' =>
        'Mobile OTP verified and ABHA profile fetched successfully.',
    'profile' => $profileData,
    'account' => $selectedAccount,
    'accounts' => $accounts
]);

exit;
?>