<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$debugLog = __DIR__ . '/abdm_hip_health_information_request_debug.log';
$rawBody = file_get_contents('php://input');

function responseJson(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function debugLogMessage(string $message, array $data = []): void
{
    global $debugLog;

    $entry = [
        'time' => date('Y-m-d H:i:s'),
        'message' => $message
    ];

    if (!empty($data)) {
        $entry['data'] = $data;
    }

    file_put_contents(
            $debugLog,
            json_encode(
                $entry,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ) . PHP_EOL,
            FILE_APPEND
        );
}

/*
 * Data-transfer library. Loaded up front and required only when the delivery
 * step below actually runs, so that a missing file can never stop this
 * endpoint from acknowledging a valid request.
 */
$hipDeliveryLibrary = $_SERVER['DOCUMENT_ROOT'] . '/modules/abdm/m3/m3_hi_delivery.php';

if (!function_exists('abdmM3HipDeliverHealthInformation') && is_file($hipDeliveryLibrary)) {
    require_once $hipDeliveryLibrary;
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

$debugData = [
    'time' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'headers' => $headers,
    'raw_body' => $rawBody
];

file_put_contents(
    $debugLog,
    json_encode(
        $debugData,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) . PHP_EOL,
    FILE_APPEND
);

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

$requestIdHeader = trim((string)($headers['Request-Id'] ?? ''));
$timestamp = trim((string)($headers['Timestamp'] ?? ''));
$hipId = trim((string)($headers['X-Hip-Id'] ?? ''));
$cmId = trim((string)($headers['X-Cm-Id'] ?? ''));

$requestId = trim((string)($payload['requestId'] ?? ''));
$transactionId = trim((string)($payload['transactionId'] ?? ''));

if ($requestId === '') {
    $requestId = $requestIdHeader;
}

$hiRequest = [];

if (isset($payload['hiRequest']) && is_array($payload['hiRequest'])) {
    $hiRequest = $payload['hiRequest'];
}

$consentId = trim(
    (string)(
        $hiRequest['consent']['id']
        ?? ''
    )
);

$dateFrom = trim(
    (string)(
        $hiRequest['dateRange']['from']
        ?? ''
    )
);

$dateTo = trim(
    (string)(
        $hiRequest['dateRange']['to']
        ?? ''
    )
);

$dataPushUrl = trim(
    (string)(
        $hiRequest['dataPushUrl']
        ?? ''
    )
);

$keyMaterial = [];

if (
    isset($hiRequest['keyMaterial'])
    && is_array($hiRequest['keyMaterial'])
) {
    $keyMaterial = $hiRequest['keyMaterial'];
}

$cryptoAlg = trim(
    (string)(
        $keyMaterial['cryptoAlg']
        ?? ''
    )
);

$curve = trim(
    (string)(
        $keyMaterial['curve']
        ?? ''
    )
);

$dhPublicKey = [];

if (
    isset($keyMaterial['dhPublicKey'])
    && is_array($keyMaterial['dhPublicKey'])
) {
    $dhPublicKey = $keyMaterial['dhPublicKey'];
}

$keyExpiry = trim(
    (string)(
        $dhPublicKey['expiry']
        ?? ''
    )
);

$keyParameters = trim(
    (string)(
        $dhPublicKey['parameters']
        ?? ''
    )
);

$keyValue = trim(
    (string)(
        $dhPublicKey['keyValue']
        ?? ''
    )
);

$hiuNonce = trim(
    (string)(
        $keyMaterial['nonce']
        ?? ''
    )
);

$payloadJson = json_encode(
    $payload,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

if ($payloadJson === false) {
    $payloadJson = $rawBody;
}

/*
 * Credential redaction for stored callback headers.
 *
 * A copy is redacted so that Authorization and other credential-bearing
 * header values are never written to abdm_m3_callbacks.headers. The
 * original $headers array is deliberately NOT mutated: Request-Id,
 * Timestamp, X-Hip-Id and X-Cm-Id were already read from it above and
 * must keep working unchanged.
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

if ($headersJson === false) {
    $headersJson = '{}';
}

debugLogMessage(
    'HIP health-information request parsed.',
    [
        'request_id' => $requestId,
        'request_id_header' => $requestIdHeader,
        'transaction_id' => $transactionId,
        'hip_id' => $hipId,
        'cm_id' => $cmId,
        'consent_id' => $consentId,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'data_push_url' => $dataPushUrl,
        'crypto_alg' => $cryptoAlg,
        'curve' => $curve,
        'key_parameters' => $keyParameters,
        'key_value_present' => ($keyValue !== ''),
        'key_value_bytes' => $keyValue !== ''
            ? strlen(base64_decode($keyValue, true) ?: '')
            : 0,
        'nonce_present' => ($hiuNonce !== ''),
        'nonce_bytes' => $hiuNonce !== ''
            ? strlen(base64_decode($hiuNonce, true) ?: '')
            : 0,
        'key_expiry' => $keyExpiry
    ]
);

if ($transactionId === '') {
    responseJson([
        'success' => false,
        'message' => 'transactionId is missing.'
    ], 400);
}

if ($consentId === '') {
    responseJson([
        'success' => false,
        'message' => 'hiRequest.consent.id is missing.'
    ], 400);
}

if ($dataPushUrl === '') {
    responseJson([
        'success' => false,
        'message' => 'hiRequest.dataPushUrl is missing.'
    ], 400);
}

if ($cryptoAlg === '') {
    responseJson([
        'success' => false,
        'message' => 'hiRequest.keyMaterial.cryptoAlg is missing.'
    ], 400);
}

if ($curve === '') {
    responseJson([
        'success' => false,
        'message' => 'hiRequest.keyMaterial.curve is missing.'
    ], 400);
}

if ($keyValue === '') {
    responseJson([
        'success' => false,
        'message' => 'hiRequest.keyMaterial.dhPublicKey.keyValue is missing.'
    ], 400);
}

if ($hiuNonce === '') {
    responseJson([
        'success' => false,
        'message' => 'hiRequest.keyMaterial.nonce is missing.'
    ], 400);
}

$keyValueDecoded = base64_decode($keyValue, true);
$nonceDecoded = base64_decode($hiuNonce, true);

if ($keyValueDecoded === false) {
    responseJson([
        'success' => false,
        'message' => 'Invalid base64 keyValue.'
    ], 400);
}

if ($nonceDecoded === false) {
    responseJson([
        'success' => false,
        'message' => 'Invalid base64 nonce.'
    ], 400);
}

if (strlen($keyValueDecoded) !== 32) {
    responseJson([
        'success' => false,
        'message' => 'Invalid Curve25519 public key length.',
        'key_bytes' => strlen($keyValueDecoded)
    ], 400);
}

if (strlen($nonceDecoded) !== 32) {
    responseJson([
        'success' => false,
        'message' => 'Invalid nonce length.',
        'nonce_bytes' => strlen($nonceDecoded)
    ], 400);
}

$controlConfigPath = $_SERVER['DOCUMENT_ROOT'] . '/includes/control_db.php';

if (!file_exists($controlConfigPath)) {
    responseJson([
        'success' => false,
        'message' => 'Control DB configuration file not found.'
    ], 500);
}

$controlConfig = require $controlConfigPath;

if (!is_array($controlConfig)) {
    responseJson([
        'success' => false,
        'message' => 'Invalid control DB configuration.'
    ], 500);
}

// This endpoint previously connected straight from control_db.php, bypassing
// the shared resolver entirely. That made it fail on managed hosting, where
// the control database lives behind DATABASE_URL (and a non-3306 port).
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/db_credentials.php';

$controlCredentials = hmsControlCredentials([
    'host' => $controlConfig['host'],
    'db'   => $controlConfig['name'],
    'user' => $controlConfig['user'],
    'pass' => $controlConfig['pass'],
]);

$controlConn = hmsConnectMysqli($controlCredentials);

if ($controlConn->connect_error) {
    responseJson([
        'success' => false,
        'message' => 'Control DB connection failed.'
    ], 500);
}

$controlConn->set_charset('utf8mb4');
$controlConn->query("SET time_zone = '+05:30'");

$tenantId = 'HMS1';

$tenantColumns = hmsTenantColumnList([
    'tenant_id',
    'hospital_id',
    'database_host',
    'database_port',
    'database_name',
    'database_user',
    'database_password',
    'access_status',
], $controlConn);

$stmt = $controlConn->prepare(
    'SELECT ' . $tenantColumns . "
     FROM projects
     WHERE tenant_id = ?
       AND hospital_id = 3
     LIMIT 1"
);

if (!$stmt) {
    $error = $controlConn->error;
    $controlConn->close();

    responseJson([
        'success' => false,
        'message' => 'Tenant lookup prepare failed.',
        'error' => $error
    ], 500);
}

$stmt->bind_param(
    's',
    $tenantId
);

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

$tenantResult = $stmt->get_result();
$tenant = $tenantResult->fetch_assoc();

$stmt->close();
$controlConn->close();

if (!$tenant) {
    responseJson([
        'success' => false,
        'message' => 'HMS1 tenant configuration not found.'
    ], 500);
}

if (($tenant['access_status'] ?? '') !== 'Allowed') {
    responseJson([
        'success' => false,
        'message' => 'Tenant access is not allowed.'
    ], 403);
}

// This endpoint also built its tenant connection straight from the raw row,
// bypassing hmsTenantCredentials(). That skipped both the HMS_ENV=local
// override and the stored database_port, so it could not reach a managed
// tenant database even after the row was migrated.
$conn = hmsConnectMysqli(hmsTenantCredentials($tenant));

if ($conn->connect_error) {
    responseJson([
        'success' => false,
        'message' => 'Tenant database connection failed.'
    ], 500);
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+05:30'");

$hospitalId = null;

if ($hipId !== '') {
    $stmt = $conn->prepare("
        SELECT
            hospital_id
        FROM abdm_m3_settings
        WHERE hip_id = ?
          AND environment = 'Sandbox'
          AND status = 'Active'
        ORDER BY id DESC
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param(
            's',
            $hipId
        );

        if ($stmt->execute()) {
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                $hospitalId = (int)$row['hospital_id'];
            }
        }

        $stmt->close();
    }
}

if ($hospitalId === null) {
    $stmt = $conn->prepare("
        SELECT
            hospital_id
        FROM abdm_m3_settings
        WHERE environment = 'Sandbox'
          AND status = 'Active'
          AND hospital_id = 3
        ORDER BY id DESC
        LIMIT 1
    ");

    if ($stmt) {
        if ($stmt->execute()) {
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                $hospitalId = (int)$row['hospital_id'];
            }
        }

        $stmt->close();
    }
}

if ($hospitalId === null) {
    responseJson([
        'success' => false,
        'message' => 'Active Sandbox M3 HIP configuration not found.'
    ], 500);
}

$localRequestId = null;
$healthRequestFound = false;
$storedCareContextReference = '';

$stmt = $conn->prepare("
    SELECT
        id,
        care_context_reference,
        patient_id
    FROM abdm_m3_health_information_requests
    WHERE hospital_id = ?
      AND request_id = ?
    ORDER BY id DESC
    LIMIT 1
");

if ($stmt) {
    $stmt->bind_param(
        'is',
        $hospitalId,
        $requestId
    );

    if ($stmt->execute()) {
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $localRequestId = (int)$row['id'];
            $healthRequestFound = true;
            $storedCareContextReference = trim((string)($row['care_context_reference'] ?? ''));
        }
    }

    $stmt->close();
}

if (!$healthRequestFound && $consentId !== '') {
    $stmt = $conn->prepare("
        SELECT
            id,
            care_context_reference,
            patient_id
        FROM abdm_m3_health_information_requests
        WHERE hospital_id = ?
          AND consent_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param(
            'is',
            $hospitalId,
            $consentId
        );

        if ($stmt->execute()) {
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                $localRequestId = (int)$row['id'];
                $healthRequestFound = true;
                $storedCareContextReference = trim((string)($row['care_context_reference'] ?? ''));
            }
        }

        $stmt->close();
    }
}

debugLogMessage(
    'Local health-information request lookup completed.',
    [
        'hospital_id' => $hospitalId,
        'request_id' => $requestId,
        'transaction_id' => $transactionId,
        'consent_id' => $consentId,
        'local_request_id' => $localRequestId,
        'health_request_found' => $healthRequestFound
    ]
);

$healthRequestUpdated = false;

if ($healthRequestFound && $localRequestId !== null) {
    $status = 'ACKNOWLEDGED';

    $stmt = $conn->prepare("
        UPDATE abdm_m3_health_information_requests
        SET
            transaction_id = ?,
            data_push_url = ?,
            crypto_alg = ?,
            curve = ?,
            key_parameters = ?,
            key_value = ?,
            nonce = ?,
            expiry = ?,
            callback_response = ?,
            status = ?,
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        debugLogMessage(
            'Health-information request update prepare failed.',
            [
                'error' => $conn->error,
                'local_request_id' => $localRequestId
            ]
        );

        responseJson([
            'success' => false,
            'message' => 'Failed to prepare health-information request update.',
            'error' => $conn->error
        ], 500);
    }

    $stmt->bind_param(
        'ssssssssssi',
        $transactionId,
        $dataPushUrl,
        $cryptoAlg,
        $curve,
        $keyParameters,
        $keyValue,
        $hiuNonce,
        $keyExpiry,
        $payloadJson,
        $status,
        $localRequestId
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        debugLogMessage(
            'Health-information request update failed.',
            [
                'error' => $error,
                'local_request_id' => $localRequestId
            ]
        );

        responseJson([
            'success' => false,
            'message' => 'Failed to update health-information request.',
            'error' => $error
        ], 500);
    }

    $healthRequestUpdated = true;

    $stmt->close();
}

$callbackType = 'health_information_hip_request';

$processingStatus = 'Received';

$processingMessage = 'HIP health-information request received from ABDM.';

if (!$healthRequestFound) {
    $processingStatus = 'Failed';
    $processingMessage .= ' Matching local health-information request was not found.';
}

$callbackId = null;

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

$consentRequestId = '';

$stmt->bind_param(
    'issssssss',
    $hospitalId,
    $callbackType,
    $requestId,
    $consentRequestId,
    $_SERVER['REQUEST_METHOD'],
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
        'message' => 'Failed to save HIP callback.',
        'error' => $error
    ], 500);
}

$callbackId = $stmt->insert_id;

$stmt->close();

$accessToken = '';

$stmt = $conn->prepare("
    SELECT
        access_token
    FROM abdm_m3_sessions
    WHERE hospital_id = ?
      AND environment = 'Sandbox'
      AND status = 'Active'
      AND access_token IS NOT NULL
      AND access_token <> ''
      AND expires_at > NOW()
    ORDER BY id DESC
    LIMIT 1
");

if ($stmt) {
    $stmt->bind_param(
        'i',
        $hospitalId
    );

    if ($stmt->execute()) {
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $accessToken = trim(
                (string)$row['access_token']
            );
        }
    }

    $stmt->close();
}

if ($accessToken === '') {
    debugLogMessage(
        'No active ABDM session token available for HIP acknowledgement.',
        [
            'hospital_id' => $hospitalId,
            'transaction_id' => $transactionId,
            'callback_id' => $callbackId
        ]
    );

    responseJson([
        'success' => false,
        'message' => 'HIP request received and stored, but no active ABDM session token is available for acknowledgement.',
        'callback_id' => $callbackId,
        'hospital_id' => $hospitalId,
        'local_request_id' => $localRequestId,
        'transaction_id' => $transactionId
    ], 500);
}

$ackRequestId = sprintf(
    '%s-%s-%s-%s-%s',
    bin2hex(random_bytes(4)),
    bin2hex(random_bytes(2)),
    bin2hex(random_bytes(2)),
    bin2hex(random_bytes(2)),
    bin2hex(random_bytes(6))
);

$ackTimestamp = gmdate(
    'Y-m-d\TH:i:s.v\Z'
);

$cmId = 'sbx';

$ackPayload = [
    'hiRequest' => [
        'transactionId' => $transactionId,
        'sessionStatus' => 'ACKNOWLEDGED'
    ],
    'response' => [
        'requestId' => $requestId
    ]
];

$ackPayloadJson = json_encode(
    $ackPayload,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

$ackUrl = 'https://dev.abdm.gov.in/api/hiecm/data-flow/v3/health-information/hip/on-request';

$ch = curl_init($ackUrl);

curl_setopt_array(
    $ch,
    [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $accessToken,
            'REQUEST-ID: ' . $ackRequestId,
            'TIMESTAMP: ' . $ackTimestamp,
            'X-CM-ID: ' . $cmId
        ],
        CURLOPT_POSTFIELDS => $ackPayloadJson
    ]
);

$ackResponse = curl_exec($ch);
$ackHttpStatus = (int)curl_getinfo(
    $ch,
    CURLINFO_HTTP_CODE
);
$ackCurlError = curl_error($ch);

curl_close($ch);

$ackResponseJson = null;

if ($ackResponse !== false && $ackResponse !== '') {
    $decodedAckResponse = json_decode(
        $ackResponse,
        true
    );

    if (is_array($decodedAckResponse)) {
        $ackResponseJson = $decodedAckResponse;
    }
}

debugLogMessage(
    'HIP health-information acknowledgement sent.',
    [
        'callback_id' => $callbackId,
        'hospital_id' => $hospitalId,
        'local_request_id' => $localRequestId,
        'transaction_id' => $transactionId,
        'request_id' => $requestId,
        'ack_request_id' => $ackRequestId,
        'ack_timestamp' => $ackTimestamp,
        'ack_url' => $ackUrl,
        'ack_http_status' => $ackHttpStatus,
        'ack_curl_error' => $ackCurlError,
        'ack_response' => $ackResponseJson
    ]
);

$ackSuccess = (
    $ackHttpStatus >= 200
    && $ackHttpStatus < 300
    && $ackCurlError === ''
);

if ($ackSuccess) {
    $stmt = $conn->prepare("
        UPDATE abdm_m3_callbacks
        SET
            processing_status = 'Acknowledged',
            processing_message = ?
        WHERE id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $message = 'HIP health-information request received and ACKNOWLEDGED successfully by ABDM. ACK HTTP: ' . $ackHttpStatus . '.';

        $stmt->bind_param(
            'si',
            $message,
            $callbackId
        );

        $stmt->execute();
        $stmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | DATA TRANSFER
    |--------------------------------------------------------------------------
    |
    | Acknowledging is not delivering. The ABDM contract for this endpoint is
    | that the HIP, having acknowledged the request, generates the consented
    | records, encrypts each entry to the keyMaterial the requester supplied,
    | and POSTs it to the requester's dataPushUrl.
    |
    | Before this was added the handler stored the requester's public key and
    | nonce in abdm_m3_health_information_requests and then returned, so the
    | key material was never used and no record ever left this system. The
    | patient's ABDM record therefore stayed empty no matter how many times
    | ABDM asked.
    |
    | Everything below is deliberately fail-soft: a delivery failure is
    | recorded and reported, but the acknowledgement already succeeded and is
    | never retracted, so a delivery problem can never turn a good callback
    | into a gateway retry loop.
    */
    $deliverySummary = abdmM3HipDeliverHealthInformation($conn, [
        'hospital_id'      => $hospitalId,
        'local_request_id' => $localRequestId,
        'callback_id'      => $callbackId,
        'transaction_id'   => $transactionId,
        'consent_id'       => $consentId,
        'care_context_reference' => $storedCareContextReference ?? null,
        'data_push_url'    => $dataPushUrl,
        'request_key_value' => $keyValue,
        'request_nonce'    => $hiuNonce,
        'date_from'        => $dateFrom,
        'date_to'          => $dateTo,
        'access_token'     => $accessToken
    ]);

    debugLogMessage(
        'HIP health-information delivery attempted.',
        $deliverySummary['summary'] ?? ['error' => 'delivery summary unavailable']
    );

    $message = 'HIP health-information request received and acknowledged.';

    if (!empty($deliverySummary['success'])) {
        $message .= ' Records delivered: '
            . (int) ($deliverySummary['entries_sent'] ?? 0) . '.';
    } elseif (!empty($deliverySummary['skipped'])) {
        $message .= ' Delivery skipped: '
            . (string) ($deliverySummary['reason'] ?? 'unknown') . '.';
    } else {
        $message .= ' Delivery failed: '
            . (string) ($deliverySummary['reason'] ?? 'unknown') . '.';
    }

    $stmt = $conn->prepare("
        UPDATE abdm_m3_callbacks
        SET
            processing_status = ?,
            processing_message = ?
        WHERE id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $callbackStatus = !empty($deliverySummary['success'])
            ? 'Delivered'
            : (!empty($deliverySummary['skipped']) ? 'Acknowledged' : 'DeliveryFailed');

        $stmt->bind_param('ssi', $callbackStatus, $message, $callbackId);
        $stmt->execute();
        $stmt->close();
    }

    responseJson([
        'success' => true,
        'message' => $message,
        'callback_id' => $callbackId,
        'hospital_id' => $hospitalId,
        'local_request_id' => $localRequestId,
        'request_id' => $requestId,
        'transaction_id' => $transactionId,
        'consent_id' => $consentId,
        'data_push_url_received' => ($dataPushUrl !== ''),
        'crypto_alg' => $cryptoAlg,
        'curve' => $curve,
        'key_bytes' => strlen($keyValueDecoded),
        'nonce_bytes' => strlen($nonceDecoded),
        'health_request_updated' => $healthRequestUpdated,
        'ack_http_status' => $ackHttpStatus,
        'ack_request_id' => $ackRequestId,
        'ack_response' => $ackResponseJson,
        'delivery' => [
            'success'       => (bool) ($deliverySummary['success'] ?? false),
            'skipped'       => (bool) ($deliverySummary['skipped'] ?? false),
            'reason'        => $deliverySummary['reason'] ?? null,
            'entries_sent'  => (int) ($deliverySummary['entries_sent'] ?? 0),
            'pages_sent'    => (int) ($deliverySummary['pages_sent'] ?? 0),
            'care_contexts' => $deliverySummary['care_contexts'] ?? [],
        ]
    ], 202);
}

$stmt = $conn->prepare("
    UPDATE abdm_m3_callbacks
    SET
        processing_status = 'Failed',
        processing_message = ?
    WHERE id = ?
    LIMIT 1
");

if ($stmt) {
    $message = 'HIP health-information request received but ABDM acknowledgement failed. HTTP: ' . $ackHttpStatus . '.';

    if ($ackCurlError !== '') {
        $message .= ' cURL: ' . $ackCurlError;
    }

    $stmt->bind_param(
        'si',
        $message,
        $callbackId
    );

    $stmt->execute();
    $stmt->close();
}

responseJson([
    'success' => false,
    'message' => 'HIP health-information request was received, but ABDM acknowledgement failed.',
    'callback_id' => $callbackId,
    'hospital_id' => $hospitalId,
    'local_request_id' => $localRequestId,
    'request_id' => $requestId,
    'transaction_id' => $transactionId,
    'consent_id' => $consentId,
    'ack_http_status' => $ackHttpStatus,
    'ack_request_id' => $ackRequestId,
    'ack_curl_error' => $ackCurlError,
    'ack_response' => $ackResponseJson
], 500);
