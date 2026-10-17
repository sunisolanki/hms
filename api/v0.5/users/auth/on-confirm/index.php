<?php
/**
 * ABDM M2 - Consent Manager linking authorisation callback (inbound).
 *
 *   POST https://<install-base>/api/v0.5/users/auth/on-confirm
 *
 * Purpose
 * -------
 * Receives the CM linking token that completes the HIP-initiated linking
 * handshake opened by modules/abdm/m2_auth_link.php, and stores it against the
 * patient so that POST /gateway/v0.5/links/link/add-contexts can later consume
 * it.
 *
 *   modules/abdm/m2_auth_link.php  --init-->  CM
 *   patient authorises in the ABHA Sandbox app
 *   CM  --on-confirm-->  THIS FILE  --stores-->  abdm_patient_links.cm_linking_token
 *   prescription save --add-contexts-->  CM
 *
 * This is the only place a linking token ever arrives. Without it there is
 * nothing for the OPD hook to push with.
 *
 * Why the patient cannot be identified from the body alone
 * --------------------------------------------------------
 * ABDM does not guarantee that the on-confirm body carries a patient or ABHA
 * identifier. The one value that is dependable is the requestId THIS
 * installation generated and sent in /users/auth/init, which was persisted to
 * abdm_auth_link_requests before that call was issued.
 *
 * Correlation order is therefore:
 *   1. requestId  - primary, exact
 *   2. abhaAddress- fallback, only when the CM omitted our requestId
 *
 * The fallback exists so a token is not silently dropped. Its use is logged so
 * the primary path can be confirmed working from the error log rather than
 * inferred from "records appeared".
 *
 * Security posture
 * ----------------
 * Modelled on api/v3/hip/patient/care-context/discover/index.php, which is the
 * only existing ABDM callback with a real authentication story:
 *
 *   * X-HIP-ID must equal the configured facility code. This answers "which
 *     facility is this claim for" and NOT "is this the gateway" - the header is
 *     a registry code disclosed at bridge registration.
 *   * abdmM2JwtEnforceInboundRequest() then cryptographically verifies the
 *     gateway session token. It ships in 'observe' mode, which logs the verdict
 *     and always allows, so it is inert until the mode is deliberately flipped.
 *
 * The token itself is never logged, never echoed, and never stored anywhere
 * except the cm_linking_token column.
 *
 * Deliberately NOT copied from api/v3/hip/link/care-context/init/index.php:
 * that file hardcodes database_name='u336643111_hms' at line 142 and therefore
 * only ever works for one tenant. The resolver used below is the same one
 * discover uses, so local-mode installs work too.
 *
 * Architecture constraint: procedural PHP, mysqli only, no framework, no ORM.
 */

header('Content-Type: application/json');

// ─────────────────────────────────────────────────────────────────────────────
// Method and body
// ─────────────────────────────────────────────────────────────────────────────
// Checked before any database work, matching discover/index.php:7-11.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST method required.']);
    exit;
}

$rawBody = file_get_contents('php://input');

if ($rawBody === false || trim($rawBody) === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Empty request body.']);
    exit;
}

$data = json_decode($rawBody, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON.']);
    exit;
}

$requestId = trim((string)($_SERVER['HTTP_REQUEST_ID'] ?? ''));

if ($requestId === '') {
    $requestId = sprintf(
        '%08s-%04s-%04s-%04s-%012s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(6))
    );
}

$hipId = trim((string)($_SERVER['HTTP_X_HIP_ID'] ?? ''));

// ─────────────────────────────────────────────────────────────────────────────
// Load the credential resolver and the JWT verifier
//
// Both are loaded before the tenant bootstrap. m2_jwt_verifier.php is pure
// function definitions plus a require of includes/abdm_helper.php, which has no
// side effects and does not touch $conn, so it is safe this early. The mode
// reader decides whether the request is processed at all, so it must be
// available before any work is spent.
// ─────────────────────────────────────────────────────────────────────────────

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/db_credentials.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/abdm/m2_jwt_verifier.php';

$controlConfig = require $_SERVER['DOCUMENT_ROOT'] . '/includes/control_db.php';

// control_db.php exposes the database name as 'name'; hmsControlCredentials()
// takes and returns it as 'db'.
$controlCredentials = hmsControlCredentials([
    'host' => $controlConfig['host'],
    'db'   => $controlConfig['name'],
    'user' => $controlConfig['user'],
    'pass' => $controlConfig['pass'],
]);

$controlConn = hmsConnectMysqli($controlCredentials);

if ($controlConn->connect_error) {
    error_log('ABDM CM-ON-CONFIRM | Control DB connection failed.');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Control database connection failed.']);
    exit;
}

$controlConn->set_charset('utf8mb4');

// ─────────────────────────────────────────────────────────────────────────────
// Resolve the tenant
// ─────────────────────────────────────────────────────────────────────────────

$tenantId = 'HMS1';

/*
 * COLUMN PROBE FOR database_port.
 *
 * discover/index.php:119-131 selects `database_port` from `projects` and fatals
 * with "Unknown column" on any control DB that predates the Aiven port support.
 * The local control database here is exactly such a schema: `projects` carries
 * database_host/name/user/password and no port column at at all, so a straight
 * copy of that SELECT dies before any request is processed.
 *
 * The probe lives in hmsTenantColumnList() / hmsProjectsHasDatabasePort() and is
 * shared by all twelve call sites; this endpoint no longer carries its own copy.
 *
 * The port is NOT simply dropped, because it is load-bearing in production: the
 * tenant database is on Aiven, which does not listen on 3306. hmsTenantCredentials()
 * already tolerates the key being absent - it reads $tenantRow['database_port'] ?? null
 * and falls back to 3306 - so the only problem is asking for a column that may
 * not exist.
 */
$tenantColumns = hmsTenantColumnList([
    'tenant_id',
    'database_host',
    'database_name',
    'database_user',
    'database_password',
    'access_status',
    'database_port',
], $controlConn);

$stmt = $controlConn->prepare(
    'SELECT ' . $tenantColumns . '
     FROM projects
     WHERE tenant_id = ?
     LIMIT 1'
);

if (!$stmt) {
    error_log('ABDM CM-ON-CONFIRM | Tenant lookup could not be prepared.');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Tenant lookup failed.']);
    exit;
}

$stmt->bind_param('s', $tenantId);
$stmt->execute();
$tenant = $stmt->get_result()->fetch_assoc();
$stmt->close();
$controlConn->close();

if (!$tenant) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Tenant HMS1 not found in control DB.']);
    exit;
}

if ($tenant['access_status'] !== 'Allowed') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Tenant HMS1 is not allowed.']);
    exit;
}

// Resolved through the shared resolver rather than the raw stored project row,
// so local-mode installs use their external credentials and the stored ones are
// never read.
$tenantCredentials = hmsTenantCredentials($tenant);

$conn = hmsConnectMysqli($tenantCredentials);

if ($conn->connect_error) {
    error_log('ABDM CM-ON-CONFIRM | Tenant DB connection failed.');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Tenant database connection failed.']);
    exit;
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+05:30'");

// ─────────────────────────────────────────────────────────────────────────────
// Verify the facility code
//
// The configured HIP ID is never echoed back: it disclosed this installation's
// own facility identifier to any caller sending a wrong value.
// ─────────────────────────────────────────────────────────────────────────────

$settingsStmt = $conn->prepare("
    SELECT hip_id
    FROM abdm_m2_settings
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if (!$settingsStmt) {
    error_log('ABDM CM-ON-CONFIRM | M2 settings lookup could not be prepared.');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'HIP configuration lookup failed.']);
    exit;
}

$settingsStmt->execute();
$settings = $settingsStmt->get_result()->fetch_assoc();
$settingsStmt->close();

if (!$settings || empty($settings['hip_id'])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Active HIP configuration not found.']);
    exit;
}

if ($hipId === '' || $hipId !== $settings['hip_id']) {
    error_log(
        'ABDM CM-ON-CONFIRM | X-HIP-ID rejected. header_present=' . ($hipId === '' ? 'NO' : 'YES') .
        ' matches_configured=' . ($hipId !== '' && $hipId === $settings['hip_id'] ? 'YES' : 'NO')
    );

    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing X-HIP-ID.']);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Callback authentication
//
// Placed after the X-HIP-ID check so the observation probe only records requests
// that already look like genuine gateway traffic, and before the reconnect so no
// work is spent on a request that is going to be refused.
// ─────────────────────────────────────────────────────────────────────────────

abdmM2JwtObserveHeaders('', $requestId, strlen($rawBody));

$authDecision = abdmM2JwtEnforceInboundRequest($conn);

if (empty($authDecision['allow'])) {
    // Only reachable once the mode is 'enforce'. The reason label is a fixed
    // enum from the verifier, never caller-supplied text.
    error_log(
        'ABDM CM-ON-CONFIRM | Inbound callback refused in ' . $authDecision['mode'] .
        ' mode: ' . $authDecision['reason']
    );

    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Re-establish the tenant connection
//
// The ABDM helper files loaded above reset $conn to null, because they load
// includes/db.php and this endpoint has no session. This is the same fix as
// api/v3/hip/patient/care-context/discover/index.php:279-298, and it reuses the
// already-resolved $tenantCredentials so the reconnect cannot fall back to the
// raw stored project-row credentials.
// ─────────────────────────────────────────────────────────────────────────────

$conn = hmsConnectMysqli($tenantCredentials);

if ($conn->connect_error) {
    error_log('ABDM CM-ON-CONFIRM | Tenant DB reconnect failed.');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Tenant database reconnect failed.']);
    exit;
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+05:30'");

// Pure function module, safe to load here.
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/abdm/m2_cm_token.php';

// ─────────────────────────────────────────────────────────────────────────────
// Extract the linking token
//
// The field name for the linking token differs between ABDM API revisions: the
// v0.5 link family documents it at link.accessToken, while the users/auth family
// returns it as auth.linkingToken or auth.accessToken. Rather than hardcoding one
// guess and failing silently on the others, every documented location is probed
// and the one that matched is logged.
//
// The path label is a fixed enum built from this file's own literals, so it is
// safe to log. The token VALUE is never logged.
// ─────────────────────────────────────────────────────────────────────────────

$tokenPaths = [
    'link.accessToken',
    'link.linkingToken',
    'auth.linkingToken',
    'auth.accessToken',
    'linkingToken',
    'accessToken',
];

$linkingToken = '';
$tokenPath = '';

foreach ($tokenPaths as $path) {
    $segments = explode('.', $path);
    $cursor = $data;
    $found = true;

    foreach ($segments as $segment) {
        if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
            $found = false;
            break;
        }

        $cursor = $cursor[$segment];
    }

    if ($found && is_string($cursor) && trim($cursor) !== '') {
        $linkingToken = trim($cursor);
        $tokenPath = $path;
        break;
    }
}

if ($linkingToken === '') {
    error_log(
        'ABDM CM-ON-CONFIRM | no linking token found in body. probed=' . implode('+', $tokenPaths) .
        ' | top_level_keys=' . implode('+', array_keys($data))
    );

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No linking token found in request body.']);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Extract purpose, correlation requestId and ABHA address
// ─────────────────────────────────────────────────────────────────────────────

$purpose = '';

foreach ([['auth', 'purpose'], ['link', 'purpose'], ['purpose']] as $candidate) {
    $cursor = $data;
    $found = true;

    foreach ($candidate as $segment) {
        if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
            $found = false;
            break;
        }

        $cursor = $cursor[$segment];
    }

    if ($found && is_string($cursor) && trim($cursor) !== '') {
        $purpose = (string)abdmM2NormalizeCmPurpose($cursor);
        break;
    }
}

// ABDM documents that no linking token is issued for KYC-purpose auth, so a
// LINK token arriving with a KYC purpose is contradictory and is refused rather
// than stored. An absent purpose is treated as LINK, which is the only purpose
// this handshake is ever opened with.
if ($purpose === '' || $purpose === null) {
    $purpose = 'LINK';
}

if ($purpose !== 'LINK') {
    error_log('ABDM CM-ON-CONFIRM | token discarded | purpose=' . $purpose . ' | not_a_linking_token');

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Token purpose does not permit linking.']);
    exit;
}

$correlationRequestId = '';

foreach ([['requestId'], ['auth', 'requestId'], ['link', 'requestId'], ['transactionId']] as $candidate) {
    $cursor = $data;
    $found = true;

    foreach ($candidate as $segment) {
        if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
            $found = false;
            break;
        }

        $cursor = $cursor[$segment];
    }

    if ($found && is_string($cursor) && trim($cursor) !== '') {
        $correlationRequestId = trim($cursor);
        break;
    }
}

$abhaAddress = '';

foreach ([
    ['abhaAddress'],
    ['patient', 'abhaAddress'],
    ['patient', 'id'],
    ['auth', 'abhaAddress'],
] as $candidate) {
    $cursor = $data;
    $found = true;

    foreach ($candidate as $segment) {
        if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
            $found = false;
            break;
        }

        $cursor = $cursor[$segment];
    }

    if ($found && is_string($cursor) && trim($cursor) !== '') {
        $abhaAddress = trim($cursor);
        break;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Resolve the patient
// ─────────────────────────────────────────────────────────────────────────────

$patientId = 0;
$correlation = 'request_id';

if ($correlationRequestId !== '') {
    $linkRequest = abdmM2ResolveAuthLinkRequest($conn, $correlationRequestId);

    if (is_array($linkRequest)) {
        $patientId = (int)($linkRequest['patient_id'] ?? 0);
    }
}

if ($patientId <= 0 && $abhaAddress !== '') {
    $correlation = 'abha_fallback';
    $patientId = abdmM2ResolvePatientByAbhaAddress($conn, $abhaAddress);
}

if ($patientId <= 0) {
    error_log(
        'ABDM CM-ON-CONFIRM | patient could not be resolved. ' .
        'has_correlation_request_id=' . ($correlationRequestId !== '' ? 'YES' : 'NO') .
        ' | has_abha_address=' . ($abhaAddress !== '' ? 'YES' : 'NO')
    );

    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Patient could not be resolved for this token.']);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Determine token expiry
//
// ABDM issues a PERPETUAL token for DIRECT auth mode and a short-lived one for
// mediated modes, and does not necessarily state the lifetime in the callback
// body. When the token is a JWT its own `exp` claim is used as the local gate.
//
// Reading `exp` here is NOT a trust decision: the value is never used to
// authenticate anything, only to decide whether to attempt an add-contexts push
// later, and the gateway revalidates the token itself. A wrong guess can only
// cause a push to be refused by the gateway, never a disclosure. Where no `exp`
// is present the token is stored as perpetual, and an operator can correct
// cm_token_expires_at manually if the gateway later rejects it as expired.
// ─────────────────────────────────────────────────────────────────────────────

$expiresAt = '';
$expirySource = 'perpetual';

$segments = explode('.', $linkingToken);

if (count($segments) === 3) {
    $payloadSegment = strtr($segments[1], '-_', '+/');
    $remainder = strlen($payloadSegment) % 4;

    if ($remainder !== 0) {
        $payloadSegment .= str_repeat('=', 4 - $remainder);
    }

    $decodedPayload = base64_decode($payloadSegment, true);

    if ($decodedPayload !== false) {
        $claims = json_decode($decodedPayload, true);

        if (is_array($claims) && isset($claims['exp']) && is_numeric($claims['exp'])) {
            $expiresAt = gmdate('Y-m-d H:i:s', (int)$claims['exp']);
            $expirySource = 'jwt_exp_claim';
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Persist
// ─────────────────────────────────────────────────────────────────────────────

$saved = abdmM2SaveCmLinkingToken(
    $conn,
    $patientId,
    $linkingToken,
    $purpose,
    $expiresAt
);

if (empty($saved)) {
    error_log(
        'ABDM CM-ON-CONFIRM | token not stored. correlation=' . $correlation .
        ' | token_path=' . $tokenPath .
        ' | probable_cause=' . ($patientId > 0 ? 'no_link_row_or_write_failed' : 'unresolved')
    );

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Linking token could not be stored.']);
    exit;
}

if ($correlationRequestId !== '') {
    abdmM2MarkAuthLinkRequestStatus($conn, $correlationRequestId, 'Confirmed');
}

error_log(
    'ABDM CM-ON-CONFIRM | linking token stored' .
    ' | token_path=' . $tokenPath .
    ' | correlation=' . $correlation .
    ' | expiry=' . $expirySource .
    ' | gateway_token_verified=' . ($authDecision['verified'] ? 'YES' : 'NO') .
    ' | auth_mode=' . $authDecision['mode']
);

// ─────────────────────────────────────────────────────────────────────────────
// 202 - accepted
//
// 202 rather than 200: the CM must not be told the token reached durable storage
// before it actually has. A retry is safe - the store is an idempotent UPDATE and
// the status transition is idempotent too.
// ─────────────────────────────────────────────────────────────────────────────

http_response_code(202);

echo json_encode([
    'success' => true,
    'status'  => 'Linking authorisation recorded.',
]);