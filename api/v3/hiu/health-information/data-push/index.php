<?php
/*
 * Minimal operational debug trace.
 *
 * Previously this file dumped every HTTP_* header (including Authorization)
 * and the complete raw request body to abdm_data_push_debug.log in the web
 * root. That exposed bearer tokens, ABHA identifiers and encrypted health
 * information key material to anyone who could fetch the log over HTTP.
 *
 * Only non-sensitive operational metadata is retained now. If richer
 * diagnostics are ever needed, send them to a server-side log channel that
 * is not web-reachable, never to a file inside the document root.
 */
$debugLog = __DIR__ . '/abdm_data_push_debug.log';
$rawBody = file_get_contents('php://input');

$debugData = [
    'time'           => date('Y-m-d H:i:s'),
    'method'         => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri'            => $_SERVER['REQUEST_URI'] ?? '',
    'content_length' => isset($rawBody) ? strlen($rawBody) : 0
];

file_put_contents(
    $debugLog,
    json_encode(
        $debugData,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) . PHP_EOL,
    FILE_APPEND
);

header('Content-Type: application/json; charset=utf-8');

function responseJson(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

/*
|--------------------------------------------------------------------------
| CONTROL DATABASE CONNECTION
|--------------------------------------------------------------------------
| Batch 1 security remediation.
|
| This endpoint previously carried its own plaintext copy of the control
| database credentials inside an unauthenticated, web-reachable callback. It
| now resolves them through the same shared resolver every other ABDM callback
| uses (includes/db_credentials.php + includes/control_db.php), so:
|   - no credential literal exists in this file, and
|   - local installs resolve through HMS_LOCAL_DB_CONFIG exactly as they do
|     in confirm/index.php, discover/index.php, init/index.php and
|     on_carecontext/index.php.
|
| BOTH connections go through the resolver: the control database through
| hmsControlCredentials() and the tenant database through hmsTenantCredentials().
| The tenant connection previously read the raw `projects` row, which left the
| production tenant account in use even in local mode; that is now corrected.
|
| Connection behaviour, the set_charset/time_zone calls and the callback
| response contract are unchanged.
*/
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/db_credentials.php';

$controlConfig = require $_SERVER['DOCUMENT_ROOT'] . '/includes/control_db.php';

// control_db.php exposes the database name as 'name'; hmsControlCredentials()
// takes and returns it as 'db'. Map it so the production fallback matches the
// resolver contract and production resolution stays identical to before.
$controlCredentials = hmsControlCredentials([
    'host' => $controlConfig['host'],
    'db'   => $controlConfig['name'],
    'user' => $controlConfig['user'],
    'pass' => $controlConfig['pass'],
]);

$controlConn = new mysqli(
    $controlCredentials['host'],
    $controlCredentials['user'],
    $controlCredentials['pass'],
    $controlCredentials['db']
);

if ($controlConn->connect_error) {
    responseJson([
        'success' => false,
        'message' => 'Control database connection failed.'
    ], 500);
}

$controlConn->set_charset('utf8mb4');
$controlConn->query("SET time_zone = '+05:30'");

$tenantId = 'HMS1';

$stmt = $controlConn->prepare("
    SELECT
        tenant_id,
        hospital_id,
        database_host,
        database_name,
        database_user,
        database_password,
        access_status
    FROM projects
    WHERE tenant_id = ?
      AND hospital_id = 3
    LIMIT 1
");

if (!$stmt) {
    $error = $controlConn->error;
    $controlConn->close();

    responseJson([
        'success' => false,
        'message' => 'Tenant lookup prepare failed.',
        'error' => $error
    ], 500);
}

$stmt->bind_param('s', $tenantId);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $controlConn->close();

    responseJson([
        'success' => false,
        'message' => 'Tenant lookup failed.',
        'error' => $error
    ], 500);
}

$result = $stmt->get_result();
$tenant = $result->fetch_assoc();

$stmt->close();
$controlConn->close();

if (!$tenant) {
    responseJson([
        'success' => false,
        'message' => 'HMS1 tenant configuration not found.'
    ], 500);
}

if ($tenant['access_status'] !== 'Allowed') {
    responseJson([
        'success' => false,
        'message' => 'Tenant access is not allowed.'
    ], 403);
}

/* Batch 1: the TENANT connection must go through the same resolver as the
   control connection. Connecting straight from the stored `projects` row would
   have kept using the production tenant account even in local mode, which is
   exactly what hmsTenantCredentials() exists to prevent. Local mode now
   resolves the tenant database the same way confirm/index.php, init/index.php,
   discover/index.php, on-generate-token/index.php and on_carecontext/index.php
   do. In production the stored row values are returned unchanged, so the
   production behaviour is identical to before. */
$tenantCredentials = hmsTenantCredentials($tenant);

$conn = new mysqli(
    $tenantCredentials['host'],
    $tenantCredentials['user'],
    $tenantCredentials['pass'],
    $tenantCredentials['db']
);

if ($conn->connect_error) {
    responseJson([
        'success' => false,
        'message' => 'Tenant database connection failed.'
    ], 500);
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+05:30'");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responseJson([
        'success' => false,
        'message' => 'Only POST method is allowed.'
    ], 405);
}

$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    responseJson([
        'success' => false,
        'message' => 'Invalid JSON payload.'
    ], 400);
}

$headers = [];

foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        $headerName = str_replace(
            ' ',
            '-',
            ucwords(
                strtolower(
                    str_replace('_', ' ', substr($key, 5))
                )
            )
        );

        $headers[$headerName] = $value;
    }
}

$requestId = trim((string)($headers['Request-Id'] ?? ''));
$timestamp = trim((string)($headers['Timestamp'] ?? ''));
$hiuId = trim((string)($headers['X-Hiu-Id'] ?? ''));

$transactionId = trim(
    (string)($payload['transactionId'] ?? '')
);

$pageNumber = isset($payload['pageNumber'])
    ? (int)$payload['pageNumber']
    : null;

$pageCount = isset($payload['pageCount'])
    ? (int)$payload['pageCount']
    : null;

$entries = $payload['entries'] ?? [];

if ($transactionId === '') {
    responseJson([
        'success' => false,
        'message' => 'transactionId is missing from data-push payload.'
    ], 400);
}

if (!is_array($entries) || count($entries) === 0) {
    responseJson([
        'success' => false,
        'message' => 'entries are missing from data-push payload.'
    ], 400);
}

$localRequestId = null;
$consentRequestId = '';
$storedRequest = null;

$stmt = $conn->prepare("
    SELECT
        id,
        hospital_id,
        consent_request_id,
        transaction_id,
        care_context_reference,
        key_value,
        nonce,
        private_key,
        status
    FROM abdm_m3_health_information_requests
    WHERE transaction_id = ?
    ORDER BY id DESC
    LIMIT 1
");

if (!$stmt) {
    responseJson([
        'success' => false,
        'message' => 'Failed to prepare local health-information request lookup.',
        'error' => $conn->error
    ], 500);
}

$stmt->bind_param('s', $transactionId);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();

    responseJson([
        'success' => false,
        'message' => 'Failed to execute local health-information request lookup.',
        'error' => $error
    ], 500);
}

$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $storedRequest = $row;
    $localRequestId = (int)$row['id'];
    $consentRequestId = (string)($row['consent_request_id'] ?? '');
}

$stmt->close();

if (!$storedRequest) {
    responseJson([
        'success' => false,
        'message' => 'Matching local M3 health-information request not found.',
        'transaction_id' => $transactionId
    ], 404);
}

$hospitalId = (int)$storedRequest['hospital_id'];

$hipPublicKeyRaw = null;
$hipNonceRaw = null;

if (
    isset($payload['keyMaterial']) &&
    is_array($payload['keyMaterial'])
) {
    $keyMaterial = $payload['keyMaterial'];

    if (
        isset($keyMaterial['dhPublicKey']) &&
        is_array($keyMaterial['dhPublicKey'])
    ) {
        $hipPublicKeyRaw = base64_decode(
            (string)($keyMaterial['dhPublicKey']['keyValue'] ?? ''),
            true
        );
    }

    $hipNonceRaw = base64_decode(
        (string)($keyMaterial['nonce'] ?? ''),
        true
    );
}

if (
    !is_string($hipPublicKeyRaw) ||
    strlen($hipPublicKeyRaw) !== 32
) {
    responseJson([
        'success' => false,
        'message' => 'HIP public key is missing or invalid.',
        'transaction_id' => $transactionId
    ], 400);
}

if (
    !is_string($hipNonceRaw) ||
    strlen($hipNonceRaw) !== 32
) {
    responseJson([
        'success' => false,
        'message' => 'HIP nonce is missing or invalid.',
        'transaction_id' => $transactionId
    ], 400);
}

$hiuPrivatePem = (string)$storedRequest['private_key'];

$hiuNonceRaw = base64_decode(
    (string)$storedRequest['nonce'],
    true
);

$hiuPublicRaw = base64_decode(
    (string)$storedRequest['key_value'],
    true
);

if (
    !is_string($hiuNonceRaw) ||
    strlen($hiuNonceRaw) !== 32
) {
    responseJson([
        'success' => false,
        'message' => 'Stored HIU nonce is invalid.',
        'transaction_id' => $transactionId
    ], 500);
}

if (
    !is_string($hiuPublicRaw) ||
    strlen($hiuPublicRaw) !== 32
) {
    responseJson([
        'success' => false,
        'message' => 'Stored HIU public key is invalid.',
        'transaction_id' => $transactionId
    ], 500);
}

$hiuPrivateKey = openssl_pkey_get_private($hiuPrivatePem);

if ($hiuPrivateKey === false) {
    responseJson([
        'success' => false,
        'message' => 'Unable to load stored HIU private key.',
        'transaction_id' => $transactionId
    ], 500);
}

$hipPublicPem =
    "-----BEGIN PUBLIC KEY-----\n" .
    chunk_split(
        base64_encode(
            hex2bin('302a300506032b656e032100') .
            $hipPublicKeyRaw
        ),
        64,
        "\n"
    ) .
    "-----END PUBLIC KEY-----\n";

$hipPublicKey = openssl_pkey_get_public($hipPublicPem);

if ($hipPublicKey === false) {
    responseJson([
        'success' => false,
        'message' => 'Unable to load HIP public key.',
        'transaction_id' => $transactionId
    ], 500);
}

$sharedSecret = openssl_pkey_derive(
    $hipPublicKey,
    $hiuPrivateKey,
    32
);

if (
    $sharedSecret === false ||
    strlen($sharedSecret) !== 32
) {
    responseJson([
        'success' => false,
        'message' => 'ECDH shared-secret derivation failed.',
        'transaction_id' => $transactionId
    ], 500);
}

$combinedNonce = '';

for ($i = 0; $i < 32; $i++) {
    $combinedNonce .= $hipNonceRaw[$i] ^ $hiuNonceRaw[$i];
}

$salt = substr($combinedNonce, 0, 20);
$iv = substr($combinedNonce, 20, 12);

$aesKey = hash_hkdf(
    'sha256',
    $sharedSecret,
    32,
    '',
    $salt
);

$decryptedEntries = [];
$entryResults = [];
$overallSuccess = true;

foreach ($entries as $entryIndex => $entry) {
    if (!is_array($entry)) {
        $overallSuccess = false;

        $entryResults[] = [
            'entry_index' => $entryIndex,
            'status' => 'FAILED',
            'message' => 'Invalid entry.'
        ];

        continue;
    }

    $encryptedContent = base64_decode(
        (string)($entry['content'] ?? ''),
        true
    );

    $receivedChecksum = strtolower(
        trim((string)($entry['checksum'] ?? ''))
    );

    $media = trim(
        (string)($entry['media'] ?? '')
    );

    $careContextReference = trim(
        (string)($entry['careContextReference'] ?? '')
    );

    if (
        $encryptedContent === false ||
        strlen($encryptedContent) < 17
    ) {
        $overallSuccess = false;

        $entryResults[] = [
            'entry_index' => $entryIndex,
            'status' => 'FAILED',
            'message' => 'Invalid encrypted content.'
        ];

        continue;
    }

    $tag = substr($encryptedContent, -16);

    $ciphertext = substr(
        $encryptedContent,
        0,
        -16
    );

    $decryptedContent = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $aesKey,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($decryptedContent === false) {
        $overallSuccess = false;

        $entryResults[] = [
            'entry_index' => $entryIndex,
            'status' => 'FAILED',
            'message' => 'AES-256-GCM decryption failed.',
            'care_context_reference' => $careContextReference
        ];

        continue;
    }

    $calculatedChecksum = md5(
        $decryptedContent
    );

    $checksumVerified = (
        $receivedChecksum !== '' &&
        hash_equals(
            $receivedChecksum,
            strtolower($calculatedChecksum)
        )
    );

    if (!$checksumVerified) {
        $overallSuccess = false;

        $entryResults[] = [
            'entry_index' => $entryIndex,
            'status' => 'FAILED',
            'message' => 'Checksum verification failed.',
            'care_context_reference' => $careContextReference
        ];

        continue;
    }

    $fhir = json_decode(
        $decryptedContent,
        true
    );

    if (!is_array($fhir)) {
        $overallSuccess = false;

        $entryResults[] = [
            'entry_index' => $entryIndex,
            'status' => 'FAILED',
            'message' => 'Decrypted content is not valid JSON.',
            'care_context_reference' => $careContextReference
        ];

        continue;
    }

    $resourceType = trim(
        (string)($fhir['resourceType'] ?? '')
    );

    if ($resourceType !== 'Bundle') {
        $overallSuccess = false;

        $entryResults[] = [
            'entry_index' => $entryIndex,
            'status' => 'FAILED',
            'message' => 'FHIR resourceType is not Bundle.',
            'resource_type' => $resourceType,
            'care_context_reference' => $careContextReference
        ];

        continue;
    }

    $bundleType = trim(
        (string)($fhir['type'] ?? '')
    );

    $entryCount = is_array($fhir['entry'] ?? null)
        ? count($fhir['entry'])
        : 0;

    $decryptedEntries[] = [
        'entry_index' => $entryIndex,
        'care_context_reference' => $careContextReference,
        'media' => $media,
        'checksum' => $receivedChecksum,
        'checksum_verified' => true,
        'resource_type' => $resourceType,
        'bundle_type' => $bundleType,
        'entry_count' => $entryCount,
        'content' => $decryptedContent
    ];

    $entryResults[] = [
        'entry_index' => $entryIndex,
        'status' => 'SUCCESS',
        'care_context_reference' => $careContextReference,
        'checksum_verified' => true,
        'resource_type' => $resourceType,
        'bundle_type' => $bundleType,
        'entry_count' => $entryCount,
        'decrypted_bytes' => strlen($decryptedContent)
    ];
}

$processingStatus = $overallSuccess
    ? 'Received'
    : 'Failed';

$processingMessage = $overallSuccess
    ? 'M3 health-information data-push received, decrypted, checksum verified and FHIR validated.'
    : 'M3 health-information data-push received but one or more entries failed processing.';

$payloadJson = json_encode(
    $payload,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

/*
 * Credential redaction for stored callback headers.
 *
 * A copy is redacted so that Authorization and other credential-bearing
 * header values are never written to abdm_m3_callbacks.headers. The
 * original $headers array is deliberately NOT mutated: Request-Id,
 * Timestamp and X-Hiu-Id were already read from it above and must keep
 * working unchanged.
 */
$headersToStore = $headers;

foreach ($headersToStore as $__hdrName => $__hdrValue) {
    // Normalise the raw CGI form (HTTP_AUTHORIZATION) to the header name
    // before matching, so both spellings are covered.
    $__hdrNorm = preg_replace('/^HTTP_/i', '', (string) $__hdrName);

    if (
        preg_match(
            '/^(authorization|proxy-authorization|cookie|set-cookie|x-api-key|apikey|api-key|x-auth-token|x-access-token|x-amz-security-token)$/i',
            $__hdrNorm
        )
    ) {
        $headersToStore[$__hdrName] =
            ($__hdrValue === '' || $__hdrValue === null)
                ? ''
                : '[REDACTED]';
    }
}
unset($__hdrName, $__hdrValue, $__hdrNorm);

$headersJson = json_encode(
    $headersToStore,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

$callbackType = 'health_information_data_push';

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
        processing_message,
        received_at,
        processed_at
    )
    VALUES
    (
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        NOW(),
        NOW()
    )
");

if (!$stmt) {
    responseJson([
        'success' => false,
        'message' => 'Failed to prepare callback database statement.',
        'error' => $conn->error
    ], 500);
}

$httpMethod = $_SERVER['REQUEST_METHOD'] ?? 'POST';

$stmt->bind_param(
    'issssssss',
    $hospitalId,
    $callbackType,
    $requestId,
    $consentRequestId,
    $httpMethod,
    $headersJson,
    $payloadJson,
    $processingStatus,
    $processingMessage
);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();

    responseJson([
        'success' => false,
        'message' => 'Failed to save data-push callback.',
        'error' => $error
    ], 500);
}

$callbackId = $stmt->insert_id;

$stmt->close();

responseJson([
    'success' => $overallSuccess,
    'message' => $processingMessage,
    'callback_id' => $callbackId,
    'hospital_id' => $hospitalId,
    'local_request_id' => $localRequestId,
    'request_id' => $requestId,
    'transaction_id' => $transactionId,
    'page_number' => $pageNumber,
    'page_count' => $pageCount,
    'hip_public_key_bytes' => strlen($hipPublicKeyRaw),
    'hip_nonce_bytes' => strlen($hipNonceRaw),
    'shared_secret_bytes' => strlen($sharedSecret),
    'salt_bytes' => strlen($salt),
    'iv_bytes' => strlen($iv),
    'aes_key_bytes' => strlen($aesKey),
    'entries_received' => count($entries),
    'entries_processed' => count($decryptedEntries),
    'entry_results' => $entryResults
], $overallSuccess ? 202 : 422);