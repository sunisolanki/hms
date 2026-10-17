<?php

header('Content-Type: application/json');

$rawBody = file_get_contents('php://input');

/*
 * Minimal operational debug trace.
 *
 * Previously this block appended the ABHA link token (via the raw body),
 * the X-HIP-ID facility identifier and the complete header set - including
 * the Authorization bearer token via print_r(getallheaders()) - to
 * abdm_callback_debug.txt in the document root, where it was fetchable
 * over plain HTTP.
 *
 * Only non-sensitive operational metadata is retained. Richer diagnostics
 * must go to a server-side log channel outside the document root, never to
 * a file served by Apache.
 */
file_put_contents(
    $_SERVER['DOCUMENT_ROOT'] . '/abdm_callback_debug.txt',
    json_encode([
        'time'           => date('Y-m-d H:i:s'),
        'method'         => $_SERVER['REQUEST_METHOD'] ?? '',
        'content_type'   => $_SERVER['CONTENT_TYPE'] ?? '',
        'content_length' => $_SERVER['CONTENT_LENGTH'] ?? '',
        'auth_present'   => !empty($_SERVER['HTTP_AUTHORIZATION']) ? 'YES' : 'NO'
    ], JSON_UNESCAPED_SLASHES) .
    "\n-------------------------\n",
    FILE_APPEND
);

// Credentials are resolved through the shared resolver. In production the stored
// control_db.php values are returned unchanged; in local mode the external
// HMS_LOCAL_DB_CONFIG values are used instead. Previously these credentials were
// inlined as new mysqli() arguments, so local installs could never connect and
// the endpoint was non-functional.
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
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Control database connection failed.'
    ]);
    exit;
}

$controlConn->set_charset('utf8mb4');

$tenantColumns = hmsTenantColumnList([
    'database_host',
    'database_port',
    'database_name',
    'database_user',
    'database_password',
], $controlConn);

$stmt = $controlConn->prepare(
    'SELECT ' . $tenantColumns . "
     FROM projects
     WHERE database_name = 'u336643111_hms'
     LIMIT 1"
);

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to prepare hospital database lookup.'
    ]);
    exit;
}

$stmt->execute();

$result = $stmt->get_result();
$tenant = $result->fetch_assoc();

$stmt->close();
$controlConn->close();

if (!$tenant) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Hospital database configuration not found.'
    ]);
    exit;
}

// Resolved through the shared resolver rather than the raw stored project row.
// In production the stored credentials are used unchanged; in local mode the
// external local tenant credentials are used and the stored ones are never read.
$tenantCredentials = hmsTenantCredentials($tenant);

$conn = hmsConnectMysqli($tenantCredentials);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Hospital database connection failed.'
    ]);
    exit;
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+05:30'");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'POST method required.'
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

$abhaAddress = trim($data['abhaAddress'] ?? '');
$linkToken = trim($data['linkToken'] ?? '');
$requestId = trim($data['response']['requestId'] ?? '');

if ($abhaAddress === '' || $linkToken === '' || $requestId === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'abhaAddress, linkToken and response.requestId are required.'
    ]);
    exit;
}

$hipId = trim($_SERVER['HTTP_X_HIP_ID'] ?? '');

if ($hipId === '') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'X-HIP-ID header is missing.'
    ]);
    exit;
}

$stmt = $conn->prepare("
    SELECT hip_id
    FROM abdm_m2_settings
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to verify HIP configuration.'
    ]);
    exit;
}

$stmt->execute();

$result = $stmt->get_result();
$settings = $result->fetch_assoc();

$stmt->close();

if (!$settings || empty($settings['hip_id'])) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'HIP ID is not configured.'
    ]);
    exit;
}

if ($hipId !== trim($settings['hip_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid HIP ID.'
    ]);
    exit;
}

$abhaNumber = preg_replace(
    '/[^0-9]/',
    '',
    strstr($abhaAddress, '@', true)
);

$stmt = $conn->prepare("
    SELECT
        id,
        abha_number,
        abha_address
    FROM abdm_patient_links
    WHERE abha_address = ?
       OR REPLACE(
            REPLACE(
                REPLACE(abha_number, '-', ''),
                ' ',
                ''
            ),
            '.',
            ''
       ) = ?
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to prepare patient lookup.'
    ]);
    exit;
}

$stmt->bind_param(
    "ss",
    $abhaAddress,
    $abhaNumber
);

$stmt->execute();

$result = $stmt->get_result();
$patientLink = $result->fetch_assoc();

$stmt->close();

if (!$patientLink) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Patient ABHA record not found.',
        'abhaAddress' => $abhaAddress
    ]);
    exit;
}

$stmt = $conn->prepare("
    UPDATE abdm_patient_links
    SET
        abha_address = ?,
        linking_token = ?,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to prepare token update.'
    ]);
    exit;
}

$stmt->bind_param(
    "ssi",
    $abhaAddress,
    $linkToken,
    $patientLink['id']
);

$updated = $stmt->execute();

$stmt->close();

if (!$updated) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save link token.'
    ]);
    exit;
}

http_response_code(202);

echo json_encode([
    'success' => true,
    'message' => 'Link token received and saved successfully.',
    'requestId' => $requestId
]);
?>
