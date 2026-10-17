<?php
header('Content-Type: application/json');

// ─────────────────────────────────────────────────────────
// Method check FIRST (before any DB work)
// ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST method required.']);
    exit;
}

// ─────────────────────────────────────────────────────────
// Read body
// ─────────────────────────────────────────────────────────
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

$transactionId = trim($data['transactionId'] ?? '');
$patient       = $data['patient'] ?? null;

if ($transactionId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'transactionId is required.']);
    exit;
}

if (!is_array($patient)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'patient object is required.']);
    exit;
}

// ─────────────────────────────────────────────────────────
// Headers
// ─────────────────────────────────────────────────────────
$requestId = $_SERVER['HTTP_REQUEST_ID'] ?? '';
$hipId     = $_SERVER['HTTP_X_HIP_ID'] ?? '';

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

// ─────────────────────────────────────────────────────────
// Load control DB config
// ─────────────────────────────────────────────────────────
// control_db.php supplies the production fallback; hmsControlCredentials()
// returns it unchanged in production and substitutes the external
// HMS_LOCAL_DB_CONFIG control values in local mode. Previously this endpoint
// connected with control_db.php values directly, so local installs could never
// connect and the endpoint was non-functional.
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/db_credentials.php';

/*
 * Callback authentication (off / observe / enforce).
 *
 * Loaded here, before the tenant bootstrap, because the mode reader is what
 * decides whether this request is processed at all. The module is pure function
 * definitions plus a require of includes/abdm_helper.php, which has no side
 * effects and does not touch $conn, so it is safe to load this early.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/abdm/m2_jwt_verifier.php';

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
    // M2 P0 - driver detail is logged server-side, never returned to the caller.
    error_log('ABDM DISCOVERY | Control DB connection failed.');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Control database connection failed.'
    ]);
    exit;
}

$controlConn->set_charset('utf8mb4');

// ─────────────────────────────────────────────────────────
// Look up tenant HMS1
// ─────────────────────────────────────────────────────────
$tenantId = 'HMS1';

// `database_port` is resolved through hmsTenantColumnList() so this lookup also
// works against a control database whose `projects` table predates it. On PHP
// 8.1+ naming a missing column makes prepare() throw.
$tenantColumns = hmsTenantColumnList([
    'tenant_id',
    'database_host',
    'database_port',
    'database_name',
    'database_user',
    'database_password',
    'access_status',
], $controlConn);

$stmt = $controlConn->prepare(
    'SELECT ' . $tenantColumns . '
     FROM projects
     WHERE tenant_id = ?
     LIMIT 1'
);

if (!$stmt) {
    error_log('ABDM DISCOVERY | Tenant lookup could not be prepared.');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Tenant lookup failed.'
    ]);
    exit;
}

$stmt->bind_param('s', $tenantId);
$stmt->execute();
$tenant = $stmt->get_result()->fetch_assoc();
$stmt->close();
$controlConn->close();

if (!$tenant) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Tenant HMS1 not found in control DB.'
    ]);
    exit;
}

if ($tenant['access_status'] !== 'Allowed') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Tenant HMS1 is not allowed.'
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────
// Connect to tenant DB
// ─────────────────────────────────────────────────────────
// Resolved through the shared resolver rather than the raw stored project row.
// In production the stored credentials are used unchanged; in local mode the
// external local tenant credentials are used and the stored ones are never read.
// $tenantCredentials is reused by the reconnect below.
$tenantCredentials = hmsTenantCredentials($tenant);

$conn = hmsConnectMysqli($tenantCredentials);

if ($conn->connect_error) {
    error_log('ABDM DISCOVERY | Tenant DB connection failed.');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Tenant database connection failed.'
    ]);
    exit;
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+05:30'");

// ─────────────────────────────────────────────────────────
// Verify HIP ID
// ─────────────────────────────────────────────────────────
$settingsStmt = $conn->prepare("
    SELECT hip_id
    FROM abdm_m2_settings
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

$settingsStmt->execute();
$settings = $settingsStmt->get_result()->fetch_assoc();
$settingsStmt->close();

if (!$settings || empty($settings['hip_id'])) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Active HIP configuration not found.'
    ]);
    exit;
}

if ($hipId === '' || $hipId !== $settings['hip_id']) {
    /*
     * M2 P0 - the configured HIP ID is no longer echoed back.
     *
     * 'expected' => $settings['hip_id'] disclosed this installation's own
     * facility identifier to any caller who sent a wrong X-HIP-ID. This was
     * directly observable: a guard test with a synthetic value returned
     * "expected":"IN0210000426". The detail now goes to the server log only.
     */
    error_log(
        'ABDM DISCOVERY | X-HIP-ID rejected. header_present=' .
        ($hipId === '' ? 'NO' : 'YES') .
        ' value_present=' . ($hipId === '' ? 'NO' : 'YES') .
        ' matches_configured=' . ($hipId !== '' && $hipId === $settings['hip_id'] ? 'YES' : 'NO')
    );

    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or missing X-HIP-ID.'
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────
// Callback authentication
//
// X-HIP-ID equality above answers "which facility is this claim for"; it does
// not prove the caller is the ABDM gateway, because that header is a facility
// registry code disclosed at bridge registration. This gate adds the missing
// half - cryptographic verification of the gateway session token the HiECM
// gateway documents as sending on callbacks.
//
// It ships in 'observe' mode, which logs the verdict and ALWAYS allows the
// request, so this is inert until the mode is flipped. The observation record
// below is the evidence needed to decide whether 'enforce' is safe.
//
// Placed after the X-HIP-ID check so the probe only records requests that
// already look like genuine gateway traffic, and before the reconnect so no
// work is spent on a request that is going to be refused.
// ─────────────────────────────────────────────────────────
abdmM2JwtObserveHeaders($transactionId, $requestId, strlen($rawBody));

$authDecision = abdmM2JwtEnforceInboundRequest($conn);

if (empty($authDecision['allow'])) {
    /*
     * Only reachable once the mode is 'enforce'. The reason label is a fixed
     * enum from the verifier, never caller-supplied text.
     */
    error_log(
        'ABDM DISCOVERY | Inbound callback refused in ' . $authDecision['mode'] .
        ' mode: ' . $authDecision['reason']
    );

    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized.'
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────
// 🔧 FIX: The ABDM helper files above reset $conn to null
// because they load db.php without a session.
// Re-establish the connection right after.
// ─────────────────────────────────────────────────────────
// Reuses the already-resolved $tenantCredentials so the reconnect cannot fall
// back to the raw stored project-row credentials.
$conn = hmsConnectMysqli($tenantCredentials);

if ($conn->connect_error) {
    error_log('ABDM DISCOVERY | Tenant DB reconnect failed.');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Tenant database reconnect failed.'
    ]);
    exit;
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+05:30'");
// ─────────────────────────────────────────────────────────
// Load ABDM helpers
// ─────────────────────────────────────────────────────────
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/abdm/m2_user_linking.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/abdm/m2_user_linking_api.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/abdm/m2_auto_link.php';

// ─────────────────────────────────────────────────────────
// M2 P0 follow-up - SAFE REQUEST DIAGNOSTIC SUMMARY
// ─────────────────────────────────────────────────────────
// The three persistence points below previously stored
// `json_encode($data)` - the ENTIRE inbound request body - into
// abdm_user_linking_transactions.request_payload (a longtext column).
// That body carries the patient object: ABHA address, ABHA number, mobile,
// MR, name, gender and year of birth, all in clear text and all retained
// indefinitely.
//
// This follows the same allow-listed summary pattern already applied to
// api/v3/hip/link/care-context/confirm/index.php after the P0 review found
// confirmation.token being persisted there.
//
// Safety notes:
//   * No identifier VALUE is stored. Only identifier TYPE labels
//     (ABHA_ADDRESS, MOBILE, MR, ...) which are a fixed enum, so a reviewer
//     can still see that e.g. a MOBILE-only probe was rejected.
//   * name / gender / yearOfBirth are deliberately excluded.
//   * No token, OTP, credential or header value is captured.
//   * transactionId and requestId are non-sensitive correlation keys and are
//     already stored in their own columns; they are repeated here only so the
//     diagnostic blob is self-describing when read standalone.
//
// No reader reads request_payload: every SELECT against
// abdm_user_linking_transactions in the codebase (confirm, init and the
// m2_confirm_hitype harness) uses an explicit column list that omits it, so
// replacing the contents loses nothing the lifecycle depends on. No schema
// change is made and the column remains in use for diagnostics.
function abdmDiscoverRequestSummary($data, $transactionId, $requestId, $outcome, $detail = '')
{
    $patient = (is_array($data) && !empty($data['patient']) && is_array($data['patient']))
        ? $data['patient']
        : [];

    $verifiedTypes   = [];
    $unverifiedTypes = [];

    foreach (['verifiedIdentifiers', 'unverifiedIdentifiers'] as $bucket) {
        if (empty($patient[$bucket]) || !is_array($patient[$bucket])) {
            continue;
        }

        foreach ($patient[$bucket] as $identifier) {
            if (!is_array($identifier)) {
                continue;
            }

            $type = strtoupper(trim((string)($identifier['type'] ?? '')));

            if ($type === '') {
                continue;
            }

            if ($bucket === 'verifiedIdentifiers') {
                $verifiedTypes[] = $type;
            } else {
                $unverifiedTypes[] = $type;
            }
        }
    }

    return json_encode([
        'summaryVersion'            => 1,
        'transactionId'             => $transactionId,
        'requestId'                 => $requestId,
        'outcome'                   => $outcome,
        'matchMethod'               => $detail,
        'patientPresent'            => !empty($patient),
        'verifiedIdentifierTypes'   => array_values(array_unique($verifiedTypes)),
        'unverifiedIdentifierTypes' => array_values(array_unique($unverifiedTypes)),
        'receivedAt'                => gmdate('Y-m-d\TH:i:s.v\Z')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Send the response to the gateway and, where the SAPI allows it, detach.
 *
 * fastcgi_finish_request() completes the client response and closes the
 * connection while leaving the PHP process running. That is what allows the
 * gateway to stop waiting on the discovery callback while the outbound
 * on-discover notification and the care-context push continue in the background.
 *
 * It only exists under FastCGI/FPM. Under mod_php and CLI it is not defined at
 * all, so it is guarded with function_exists() and the caller simply continues
 * synchronously. That is a real behavioural difference rather than a cosmetic
 * one: without detaching, the gateway waits on our outbound round-trips (a
 * gateway session plus the notification) before it receives its 202. The path
 * taken is recorded to the log either way so the difference is diagnosable.
 *
 * ignore_user_abort() is set before the body is written so a gateway that hangs
 * up mid-write does not terminate the background work.
 *
 * @param int    $statusCode
 * @param string $bodyJson
 * @return bool True when the process detached, false when work continues inline.
 */
function abdmDiscoverReleaseResponse($statusCode, $bodyJson)
{
    if (function_exists('ignore_user_abort')) {
        ignore_user_abort(true);
    }

    http_response_code($statusCode);
    echo $bodyJson;

    if (!function_exists('fastcgi_finish_request')) {
        error_log(
            'ABDM DISCOVERY | SAPI cannot detach (fastcgi_finish_request unavailable); '
            . 'outbound work runs inline after the response body is written.'
        );

        return false;
    }

    // Flush any buffer so the gateway actually receives the 202 before the
    // outbound calls start consuming the execution window.
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }

    @flush();

    fastcgi_finish_request();

    error_log('ABDM DISCOVERY | response released; continuing detached.');

    return true;
}

// ─────────────────────────────────────────────────────────
// Match patient
//
// Two matchers exist and they are not interchangeable.
//
//   abdmM2GetUserLinkingPatient()      ABHA-anchored. Unchanged behaviour.
//   abdmM2MatchPatientByDemographics()  MOBILE + GENDER + YEAR OF BIRTH.
//
// The ABHA matcher is used whenever the payload carries an ABHA anchor. That is
// deliberate: if a payload presents an ABHA and it fails to resolve, retrying on
// demographics could turn "this ABHA does not exist here" into "some unrelated
// patient's demographics happen to agree", which is precisely the confusion the
// ABHA anchor exists to prevent.
//
// The demographic matcher is used ONLY when no ABHA was supplied AND the caller
// has cryptographically proven it is the ABDM gateway.
//
// Why that gate is mandatory
// --------------------------
// Demographics are caller-asserted values. A mobile number, a gender and a birth
// year are not self-authenticating - they carry no signature. They are only
// trustworthy because the sender proved it was the gateway, not because they
// were supplied by the gateway. Without this gate, anyone able to reach this
// endpoint could enumerate mobile + gender + year-of-birth and read back a
// patient's UHID, full name and every care context.
//
// The X-HIP-ID check above does not close this. X-HIP-ID is a facility registry
// code that is disclosed during bridge registration and travels in every
// outbound request this installation makes, so it identifies the facility being
// claimed, not the sender.
//
// $authDecision['verified'] is therefore a stricter claim than $authDecision
// ['allow']: it is true only when a gateway session token was presented, its
// signature was checked against the HiECM certificate authority and its
// lifetime was validated. In 'observe' mode 'allow' is always true so the
// callback keeps working, while 'verified' stays false until a real token is
// seen. That split is what lets ABHA-based discovery keep working exactly as
// before, while demographic matching stays dormant until the gateway is
// actually identified.
// ─────────────────────────────────────────────────────────
$hasAbhaAnchor = abdmM2HasAbhaAnchor($patient);
$callerProven  = !empty($authDecision['verified']);
$matchMethod   = $hasAbhaAnchor ? 'abha' : 'demographics';

if ($hasAbhaAnchor) {
    $patientMatch = abdmM2GetUserLinkingPatient($patient);
} elseif ($callerProven) {
    $patientMatch = abdmM2MatchPatientByDemographics($patient);
} else {
    error_log(
        'ABDM DISCOVERY | demographic matching declined, caller not proven | '
        . 'auth_mode=' . $authDecision['mode'] .
        ' | auth_reason=' . $authDecision['reason']
    );

    $patientMatch = [
        'success' => false,
        'error' => 'Discovery without an ABHA identifier requires a verified ABDM gateway token, which was not presented.'
    ];
}

if (!$patientMatch['success']) {
    // M2 P0 follow-up - diagnostic summary only; no raw request body.
    $payloadJson = abdmDiscoverRequestSummary($data, $transactionId, $requestId, 'match_refused', $matchMethod);
    $responseJson = json_encode([
        'success' => false,
        'message' => $patientMatch['error'] ?? 'Patient not found.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $conn->prepare("
        INSERT INTO abdm_user_linking_transactions
        (transaction_id, request_id, patient_id, abha_address,
         status, request_payload, response_payload)
        VALUES (?, ?, NULL, NULL, 'Failed', ?, ?)
        ON DUPLICATE KEY UPDATE
            request_id       = VALUES(request_id),
            patient_id       = NULL,
            abha_address     = NULL,
            status           = 'Failed',
            request_payload  = VALUES(request_payload),
            response_payload = VALUES(response_payload)
    ");

    $stmt->bind_param('ssss', $transactionId, $requestId, $payloadJson, $responseJson);

    /* B-25: this write was previously unchecked, so a failed insert was
       answered with HTTP 200 "Discovery callback received. Patient not found."
       and the transaction was silently left in its old state. Both the return
       value and a thrown mysqli_sql_exception are handled, so the guard holds
       whether or not the host sets MYSQLI_REPORT_STRICT. */
    $writeFailed = false;
    try {
        if (!$stmt->execute()) { $writeFailed = true; }
    } catch (Throwable $e) {
        $writeFailed = true;
        error_log('discover: match_refused write threw | ' . $e->getMessage());
    }
    $stmt->close();

    if ($writeFailed) {
        error_log('discover: match_refused transaction write failed');
        http_response_code(500);
        echo json_encode([
            'success'       => false,
            'message'       => 'Discovery callback could not be recorded.',
            'transactionId' => $transactionId
        ]);
        exit;
    }

    http_response_code(200);
    echo json_encode([
        'success'       => true,
        'message'       => 'Discovery callback received. Patient not found.',
        'transactionId' => $transactionId
    ]);
    exit;
}

$matchedPatient = $patientMatch['patient'];
$patientId      = (int)$matchedPatient['id'];
$abhaAddress    = $matchedPatient['abha_address'] ?? '';

// ─────────────────────────────────────────────────────────
// Build discovery response
// ─────────────────────────────────────────────────────────
$discoveryResult = abdmM2BuildDiscoveryResponse($patientId);

if (!$discoveryResult['success']) {
    // M2 P0 follow-up - diagnostic summary only; no raw request body.
    $payloadJson = abdmDiscoverRequestSummary($data, $transactionId, $requestId, 'discovery_build_failed', $matchMethod);
    $responseJson = json_encode([
        'success' => false,
        'message' => $discoveryResult['error'] ?? 'Unable to build discovery response.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $conn->prepare("
        INSERT INTO abdm_user_linking_transactions
        (transaction_id, request_id, patient_id, abha_address,
         status, request_payload, response_payload)
        VALUES (?, ?, ?, ?, 'Failed', ?, ?)
        ON DUPLICATE KEY UPDATE
            request_id       = VALUES(request_id),
            patient_id       = VALUES(patient_id),
            abha_address     = VALUES(abha_address),
            status           = 'Failed',
            request_payload  = VALUES(request_payload),
            response_payload = VALUES(response_payload)
    ");

    $stmt->bind_param('sissss', $transactionId, $requestId, $patientId, $abhaAddress, $payloadJson, $responseJson);

    /* B-25: see the note on the match_refused write above. */
    $writeFailed = false;
    try {
        if (!$stmt->execute()) { $writeFailed = true; }
    } catch (Throwable $e) {
        $writeFailed = true;
        error_log('discover: discovery_build_failed write threw | ' . $e->getMessage());
    }
    $stmt->close();

    if ($writeFailed) {
        error_log('discover: discovery_build_failed transaction write failed');
        http_response_code(500);
        echo json_encode([
            'success'       => false,
            'message'       => 'Discovery callback could not be recorded.',
            'transactionId' => $transactionId
        ]);
        exit;
    }

    http_response_code(200);
    echo json_encode([
        'success'       => true,
        'message'       => 'Discovery callback received. Discovery data could not be prepared.',
        'transactionId' => $transactionId
    ]);
    exit;
}

// M2 P0 follow-up - diagnostic summary only; no raw request body.
$payloadJson    = abdmDiscoverRequestSummary($data, $transactionId, $requestId, 'accepted', $matchMethod);
$patientRecords = $discoveryResult['patient'];

// ─────────────────────────────────────────────────────────
// matchedBy
//
// Reported to the gateway so it knows which identifiers resolved the patient.
// The values come from the matcher that actually ran, so a demographic match
// reports MOBILE and never falsely claims an ABHA match.
// ─────────────────────────────────────────────────────────
$matchedBy = [];
if (!empty($patientMatch['matched_identifiers']['ABHA_ADDRESS'])) $matchedBy[] = 'ABHA_ADDRESS';
if (!empty($patientMatch['matched_identifiers']['ABHA_NUMBER']))  $matchedBy[] = 'ABHA_NUMBER';
if (!empty($patientMatch['matched_identifiers']['MOBILE']))       $matchedBy[] = 'MOBILE';
if (!empty($patientMatch['matched_identifiers']['MR']))           $matchedBy[] = 'MR';

/*
 * Flat care-context list for the auto-link push.
 *
 * abdmM2BuildDiscoveryResponse() groups the same contexts by HIType for the
 * gateway payload, so this is a second read of the same two queries rather than
 * a reimplementation of the grouping. It is read here rather than refactored out
 * of abdmM2BuildDiscoveryResponse() because that function's return shape is part
 * of the existing M2 contract.
 */
$flatCareContexts = [];
$flatContextResult = abdmM2GetPatientCareContexts($patientId);

if (!empty($flatContextResult['success']) && is_array($flatContextResult['care_contexts'])) {
    $flatCareContexts = $flatContextResult['care_contexts'];
}

// ─────────────────────────────────────────────────────────
// Persist the transaction BEFORE responding.
//
// The row is written while status is still 'Discovery', meaning "accepted, the
// gateway has been told, outbound work not yet finished". This write must happen
// before the response is released because everything after it is best-effort
// background work: if the process dies once detached, this row is the only
// evidence that a discovery was accepted and what it matched. A row stuck at
// 'Discovery' is therefore itself the signal that a background run was lost.
// ─────────────────────────────────────────────────────────
$responsePayload = [
    'transactionId'      => $transactionId,
    'matchedBy'          => $matchedBy,
    'response'           => ['requestId' => $requestId],
    'matchedPatientId'   => $patientId,
    'matchMethod'        => $matchMethod,
    'matchedIdentifiers' => $patientMatch['matched_identifiers'],
    'phase'              => 'accepted'
];

$responseJson = json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$stmt = $conn->prepare("
    INSERT INTO abdm_user_linking_transactions
    (transaction_id, request_id, patient_id, abha_address,
     status, request_payload, response_payload)
    VALUES (?, ?, ?, ?, 'Discovery', ?, ?)
    ON DUPLICATE KEY UPDATE
        request_id       = VALUES(request_id),
        patient_id       = VALUES(patient_id),
        abha_address     = VALUES(abha_address),
        status           = 'Discovery',
        request_payload  = VALUES(request_payload),
        response_payload = VALUES(response_payload)
");

$stmt->bind_param(
    'sissss',
    $transactionId,
    $requestId,
    $patientId,
    $abhaAddress,
    $payloadJson,
    $responseJson
);

/* B-25: an unchecked write here used to answer HTTP 200 "Discovery callback
   processed successfully." even when the row was never stored. Both the return
   value and a thrown mysqli_sql_exception are handled, so the guard holds
   whether or not the host sets MYSQLI_REPORT_STRICT. */
$writeFailed = false;

try {
    if (!$stmt->execute()) {
        $writeFailed = true;
    }
} catch (Throwable $e) {
    $writeFailed = true;
    error_log('discover: accepted write threw | ' . $e->getMessage());
}

$stmt->close();

if ($writeFailed) {
    error_log('discover: accepted transaction write failed');
    http_response_code(500);
    echo json_encode([
        'success'       => false,
        'message'       => 'Discovery callback could not be recorded.',
        'transactionId' => $transactionId
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────
// Release the gateway with 202 Accepted.
//
// 202 rather than 200 because at this point the request has been accepted and
// durably recorded, but the notification to the gateway has not been made yet.
// Answering 200 would claim completion that has not happened.
// ─────────────────────────────────────────────────────────
$acceptedBody = json_encode([
    'success'        => true,
    'message'        => 'Discovery request accepted.',
    'transactionId'  => $transactionId,
    'requestId'      => $requestId,
    'matchMethod'    => $matchMethod
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$detached = abdmDiscoverReleaseResponse(202, $acceptedBody);

// ─────────────────────────────────────────────────────────
// Background phase.
//
// Everything below runs after the gateway has its 202. Under FastCGI the client
// is already gone; under mod_php or CLI the work is inline but the response body
// has already been written.
//
// Display errors are disabled first because after fastcgi_finish_request() the
// client connection is closed, and a warning printed at that point has nowhere
// to go. Diagnostics are written to the error log regardless, so nothing is lost.
// ─────────────────────────────────────────────────────────
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');

try {
    /*
     * M2 P0 - the outbound on-discover call is UNCONDITIONAL.
     *
     * This block previously skipped abdmM2SendUserLinkingOnDiscover() whenever
     * the caller-supplied transactionId began with "TEST-TRANSACTION-". Because
     * this endpoint performs no real source authentication, that prefix was
     * reachable by anyone: it silently suppressed the notification to the ABDM
     * gateway while the endpoint still reported success. A test shortcut must
     * not be able to change gateway behaviour in production, so the branch was
     * removed rather than gated.
     */
    $onDiscoverResult = abdmM2SendUserLinkingOnDiscover(
        $transactionId,
        $patientRecords,
        $matchedBy,
        $requestId
    );

    /*
     * Auto-linking (no OTP).
     *
     * Runs only after on-discover so the gateway is told about the discovery
     * before it is told about the link. Skips patients with no ABHA address or
     * no stored linking token, which the HiECM link API cannot accept; those are
     * reported as skipped rather than treated as failures.
     */
    $autoLinkResult = abdmM2AutoLinkCareContexts($patientId, $flatCareContexts, $matchMethod);

    $finalStatus = !empty($onDiscoverResult['success']) ? 'Discovered' : 'Failed';

    $finalPayload = [
        'transactionId'      => $transactionId,
        'matchedBy'          => $matchedBy,
        'response'           => ['requestId' => $requestId],
        'matchedPatientId'   => $patientId,
        'matchMethod'        => $matchMethod,
        'matchedIdentifiers' => $patientMatch['matched_identifiers'],
        'onDiscover'         => [
            'success'   => !empty($onDiscoverResult['success']),
            'http_code' => (int)($onDiscoverResult['http_code'] ?? 0),
            'message'   => (string)($onDiscoverResult['message'] ?? ''),
            'request_id' => (string)($onDiscoverResult['request_id'] ?? '')
        ],
        'autoLink'           => $autoLinkResult,
        'detached'           => $detached,
        'phase'              => 'completed'
    ];

    /*
     * The patient records themselves are NOT written back into
     * response_payload. The on-discover structure is reduced to status codes and
     * the auto-link result to reference numbers and outcomes, so the column no
     * longer accumulates UHIDs, names and care-context references. The gateway
     * already has the care contexts - they were just delivered.
     */
    $finalJson = json_encode($finalPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $updateStmt = $conn->prepare("
        UPDATE abdm_user_linking_transactions
        SET status           = ?,
            response_payload = ?
        WHERE transaction_id = ?
        LIMIT 1
    ");

    $updateFailed = false;

    if ($updateStmt) {
        $updateStmt->bind_param('sss', $finalStatus, $finalJson, $transactionId);

        try {
            if (!$updateStmt->execute()) {
                $updateFailed = true;
            }
        } catch (Throwable $e) {
            $updateFailed = true;
            error_log('discover: completed update threw | ' . $e->getMessage());
        }

        $updateStmt->close();
    } else {
        $updateFailed = true;
    }

    if ($updateFailed) {
        /*
         * The gateway has already been answered and notified, so this is logged
         * rather than reported. The row remains at 'Discovery', which is the
         * documented signal of an incomplete background run.
         */
        error_log('discover: completed transaction update failed; row left at Discovery.');
    }

    error_log(
        'ABDM DISCOVERY | background complete | match_method=' . $matchMethod .
        ' | detached=' . ($detached ? 'YES' : 'NO') .
        ' | status=' . $finalStatus
    );
} catch (Throwable $e) {
    error_log('discover: background phase threw | ' . $e->getMessage());

    $fallbackStatus = 'Failed';
    $fallbackJson = json_encode([
        'transactionId' => $transactionId,
        'matchMethod'   => $matchMethod,
        'phase'         => 'background_exception',
        'detail'        => 'Background processing raised an unhandled error; the transaction was recorded as failed.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $fallbackStmt = $conn->prepare("
        UPDATE abdm_user_linking_transactions
        SET status           = ?,
            response_payload = ?
        WHERE transaction_id = ?
        LIMIT 1
    ");

    if ($fallbackStmt) {
        try {
            $fallbackStmt->bind_param('sss', $fallbackStatus, $fallbackJson, $transactionId);
            $fallbackStmt->execute();
        } catch (Throwable $inner) {
            error_log('discover: background fallback update threw | ' . $inner->getMessage());
        }

        $fallbackStmt->close();
    }
}
