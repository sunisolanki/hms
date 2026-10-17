<?php
$debugLog = __DIR__ . '/abdm_on_request_debug.log';
$rawBody = file_get_contents('php://input');

$debugData = [
    'time' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'headers' => [],
    'raw_body' => $rawBody
];

foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        $debugData['headers'][$key] = $value;
    }
}

file_put_contents(
    $debugLog,
    json_encode($debugData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
    FILE_APPEND
);

header('Content-Type: application/json; charset=utf-8');

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

// hmsConnectMysqli() threads through the port from DATABASE_URL (Aiven does
// not listen on 3306) and suppresses the PHP 8.1+ mysqli exception so the
// connect_error handler below still runs.
$controlConn = hmsConnectMysqli($controlCredentials);

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
        database_port,
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
    $controlConn->close();
    responseJson([
        'success' => false,
        'message' => 'Tenant lookup prepare failed.',
        'error' => $controlConn->error
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

$conn = hmsConnectMysqli($tenantCredentials);

if ($conn->connect_error) {
    responseJson([
        'success' => false,
        'message' => 'Tenant database connection failed.'
    ], 500);
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+05:30'");

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responseJson([
        'success' => false,
        'message' => 'Only POST method is allowed.'
    ], 405);
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    responseJson([
        'success' => false,
        'message' => 'Database connection is not available.'
    ], 500);
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

$transactionId = '';
$sessionStatus = '';
$errorData = null;
$responseData = null;

if (isset($payload['hiRequest']) && is_array($payload['hiRequest'])) {
    $hiRequest = $payload['hiRequest'];

    $transactionId = trim(
        (string)($hiRequest['transactionId'] ?? '')
    );

    $sessionStatus = strtoupper(
        trim(
            (string)($hiRequest['sessionStatus'] ?? '')
        )
    );
}

if (isset($payload['error'])) {
    $errorData = $payload['error'];
}

if (isset($payload['response'])) {
    $responseData = $payload['response'];
}

$responseRequestId = '';

if (is_array($responseData)) {
    $responseRequestId = trim(
        (string)($responseData['requestId'] ?? '')
    );
}

$callbackType = 'health_information_on_request';

debugLogMessage(
    'Parsed callback identifiers.',
    [
        'request_id' => $requestId,
        'response_request_id' => $responseRequestId,
        'hiu_id' => $hiuId,
        'transaction_id' => $transactionId,
        'session_status' => $sessionStatus
    ]
);

$hospitalId = null;

if ($hiuId !== '') {
    $stmt = $conn->prepare("
        SELECT hospital_id
        FROM abdm_m3_settings
        WHERE hiu_id = ?
          AND environment = 'Sandbox'
          AND status = 'Active'
        ORDER BY id DESC
        LIMIT 1
    ");

    if (!$stmt) {
        debugLogMessage(
            'Failed to prepare hospital lookup.',
            ['error' => $conn->error]
        );
    } else {
        $stmt->bind_param('s', $hiuId);

        if (!$stmt->execute()) {
            debugLogMessage(
                'Failed to execute hospital lookup.',
                ['error' => $stmt->error]
            );
        } else {
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                $hospitalId = (int)$row['hospital_id'];
            }
        }

        $stmt->close();
    }
}

$processingStatus = 'Received';
$processingMessage = 'M3 health-information on-request callback received.';

if ($transactionId === '') {
    $processingStatus = 'Failed';
    $processingMessage = 'transactionId is missing from callback payload.';
}

if ($errorData !== null) {
    $processingStatus = 'Failed';

    $errorMessage = '';

    if (is_array($errorData)) {
        $errorMessage = trim(
            (string)($errorData['message'] ?? '')
        );
    }

    if ($errorMessage !== '') {
        $processingMessage = 'ABDM health-information error: ' . $errorMessage;
    } else {
        $processingMessage = 'ABDM returned an error in health-information on-request callback.';
    }
}

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

if ($headersJson === false) {
    $headersJson = '{}';
}

$consentRequestId = '';
$localRequestId = null;
$healthRequestFound = false;
$lookupRequestId = '';

if ($responseRequestId !== '') {
    $lookupRequestId = $responseRequestId;
} elseif ($requestId !== '') {
    $lookupRequestId = $requestId;
}

if ($lookupRequestId !== '' && $hospitalId !== null) {
    $stmt = $conn->prepare("
        SELECT
            id,
            consent_request_id
        FROM abdm_m3_health_information_requests
        WHERE hospital_id = ?
          AND request_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");

    if (!$stmt) {
        debugLogMessage(
            'Failed to prepare health request lookup.',
            [
                'error' => $conn->error,
                'hospital_id' => $hospitalId,
                'lookup_request_id' => $lookupRequestId
            ]
        );
    } else {
        $stmt->bind_param(
            'is',
            $hospitalId,
            $lookupRequestId
        );

        if (!$stmt->execute()) {
            debugLogMessage(
                'Failed to execute health request lookup.',
                [
                    'error' => $stmt->error,
                    'hospital_id' => $hospitalId,
                    'lookup_request_id' => $lookupRequestId
                ]
            );
        } else {
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                $localRequestId = (int)$row['id'];
                $consentRequestId = (string)($row['consent_request_id'] ?? '');
                $healthRequestFound = true;
            }
        }

        $stmt->close();
    }
}

debugLogMessage(
    'Health request lookup result.',
    [
        'hospital_id' => $hospitalId,
        'lookup_request_id' => $lookupRequestId,
        'local_request_id' => $localRequestId,
        'health_request_found' => $healthRequestFound
    ]
);

$healthRequestUpdated = false;

if ($healthRequestFound && $localRequestId !== null) {
    $healthStatus = 'Received';

    if ($errorData !== null) {
        $healthStatus = 'Failed';
    } elseif ($sessionStatus !== '') {
        $healthStatus = $sessionStatus;
    }

    $stmt = $conn->prepare("
        UPDATE abdm_m3_health_information_requests
        SET
            transaction_id = ?,
            callback_response = ?,
            status = ?,
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        $processingStatus = 'Failed';
        $processingMessage = 'Failed to prepare health-information request update.';
        $processingMessage .= ' MySQL: ' . $conn->error;

        debugLogMessage(
            'Failed to prepare health request update.',
            [
                'error' => $conn->error,
                'local_request_id' => $localRequestId
            ]
        );
    } else {
        $stmt->bind_param(
            'sssi',
            $transactionId,
            $payloadJson,
            $healthStatus,
            $localRequestId
        );

        if (!$stmt->execute()) {
            $processingStatus = 'Failed';
            $processingMessage = 'Failed to update local health-information request.';
            $processingMessage .= ' MySQL: ' . $stmt->error;

            debugLogMessage(
                'Health request UPDATE failed.',
                [
                    'error' => $stmt->error,
                    'local_request_id' => $localRequestId,
                    'transaction_id' => $transactionId,
                    'health_status' => $healthStatus
                ]
            );
        } else {
            $healthRequestUpdated = ($stmt->affected_rows > 0);

            if ($healthRequestUpdated) {
                $processingMessage .= ' Local health-information request updated.';
            } else {
                $processingMessage .= ' Local health-information request UPDATE executed but no row was changed.';
            }

            debugLogMessage(
                'Health request UPDATE executed.',
                [
                    'local_request_id' => $localRequestId,
                    'affected_rows' => $stmt->affected_rows,
                    'transaction_id' => $transactionId,
                    'health_status' => $healthStatus
                ]
            );
        }

        $stmt->close();
    }
} else {
    $processingStatus = 'Received';

    if ($transactionId === '') {
        $processingStatus = 'Failed';
    }

    $processingMessage .= ' Matching local health-information request was not found.';

    debugLogMessage(
        'Matching local health-information request was not found.',
        [
            'hospital_id' => $hospitalId,
            'lookup_request_id' => $lookupRequestId,
            'request_id' => $requestId,
            'response_request_id' => $responseRequestId
        ]
    );
}

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
    debugLogMessage(
        'Failed to prepare callback database statement.',
        ['error' => $conn->error]
    );

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

    debugLogMessage(
        'Failed to save callback.',
        ['error' => $error]
    );

    responseJson([
        'success' => false,
        'message' => 'Failed to save callback.',
        'error' => $error
    ], 500);
}

$callbackId = $stmt->insert_id;

$stmt->close();

responseJson([
    'success' => true,
    'message' => 'Health information on-request callback received.',
    'callback_id' => $callbackId,
    'hospital_id' => $hospitalId,
    'local_request_id' => $localRequestId,
    'request_id' => $requestId,
    'response_request_id' => $responseRequestId,
    'transaction_id' => $transactionId,
    'session_status' => $sessionStatus,
    'health_request_updated' => $healthRequestUpdated,
    'consent_request_id' => $consentRequestId
], 202);
