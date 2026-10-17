<?php
header('Content-Type: application/json');

$logFile = __DIR__ . '/abdm_init_test.log';

function abdmInitLog($message)
{
    global $logFile;
    file_put_contents(
        $logFile,
        "\n" . date('Y-m-d H:i:s') . "\n" . $message . "\n--------------------\n",
        FILE_APPEND
    );
}

$rawBody = file_get_contents('php://input');

/*
 * SAFE DIAGNOSTIC LOGGING  (M2 Phase 0 / 0.4)
 * -------------------------------------------
 * Previously every inbound header VALUE except AUTHORIZATION was written to
 * abdm_init_test.log, together with the full raw request body. That body carries
 * abhaAddress and patient identifiers, so this file accumulated secrets and
 * patient data in plain text.
 *
 * Only non-identifying diagnostics are retained now: event, method, a per-header
 * PRESENCE map (never values), body SIZE, and correlation-id presence. The log
 * file is kept - no history is deleted.
 */
$incomingHeaders = function_exists('getallheaders') ? getallheaders() : [];
$headerPresence = [];
if (is_array($incomingHeaders)) {
    foreach ($incomingHeaders as $key => $value) {
        $key = strtolower((string)$key);
        $isSensitive =
            strpos($key, 'authorization') !== false ||
            strpos($key, 'token') !== false ||
            strpos($key, 'cookie') !== false ||
            strpos($key, 'auth') !== false ||
            $key === 'x-hiu-id' ||
            $key === 'x-cm-id';
        $headerPresence[$key] = $isSensitive ? '[REDACTED]' : '[present]';
    }
}
abdmInitLog(
    "EVENT: hip_link_care_context_init_received" .
    "\nMETHOD: " . ($_SERVER['REQUEST_METHOD'] ?? '') .
    "\nHEADERS: " . json_encode($headerPresence) .
    "\nBODY_BYTES: " . (is_string($rawBody) ? strlen($rawBody) : 0) .
    "\nREQUEST_ID_PRESENT: " . (!empty($_SERVER['HTTP_REQUEST_ID']) ? 'YES' : 'NO') .
    "\nABHA_ADDRESS_PRESENT: " . (is_string($rawBody) && strpos($rawBody, 'abhaAddress') !== false ? 'YES' : 'NO')
);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST method required.']);
    exit;
}

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

$transactionId  = trim((string)($data['transactionId'] ?? ''));
$abhaAddress    = trim((string)($data['abhaAddress'] ?? ''));
$patientRecords = $data['patient'] ?? [];
$requestId      = trim((string)($_SERVER['HTTP_REQUEST_ID'] ?? ''));
$hipId          = trim((string)($_SERVER['HTTP_X_HIP_ID'] ?? ''));

if ($transactionId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'transactionId is required.']);
    exit;
}

if ($abhaAddress === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'abhaAddress is required.']);
    exit;
}

if (!is_array($patientRecords) || empty($patientRecords)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'patient is required.']);
    exit;
}

if ($requestId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'REQUEST-ID header is required.']);
    exit;
}

// ─────────────────────────────────────────────────────────
// Connect to control DB
// ─────────────────────────────────────────────────────────
// Credentials are resolved through the shared resolver. In production the stored
// control_db.php values are returned unchanged; in local mode the external
// HMS_LOCAL_DB_CONFIG values are used instead. Previously this endpoint carried
// its own copy of the production literals, so local installs could never
// connect and the endpoint was non-functional.
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
    echo json_encode(['success' => false, 'message' => 'Control database connection failed.']);
    exit;
}

$controlConn->set_charset('utf8mb4');

$stmt = $controlConn->prepare("
    SELECT database_name, database_host, database_port, database_user, database_password
    FROM projects
    WHERE database_name = 'u336643111_hms'
    LIMIT 1
");

if (!$stmt) {
    $controlConn->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to prepare hospital database configuration query.']);
    exit;
}

$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();
$stmt->close();
$controlConn->close();

if (!$project) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Hospital database configuration not found.']);
    exit;
}

// ─────────────────────────────────────────────────────────
// Connect to tenant DB
// ─────────────────────────────────────────────────────────
// Resolved through the shared resolver rather than the raw stored project row.
// In production the stored credentials are used unchanged; in local mode the
// external local tenant credentials are used and the stored ones are never read.
$tenantCredentials = hmsTenantCredentials($project);

$hospitalConn = hmsConnectMysqli($tenantCredentials);

if ($hospitalConn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Hospital database connection failed.']);
    exit;
}

$hospitalConn->set_charset('utf8mb4');
$hospitalConn->query("SET time_zone = '+05:30'");

// ─────────────────────────────────────────────────────────
// Verify HIP ID
// ─────────────────────────────────────────────────────────
$settingsStmt = $hospitalConn->prepare("
    SELECT hip_id
    FROM abdm_m2_settings
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if (!$settingsStmt) {
    $hospitalConn->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to prepare ABDM M2 settings query.']);
    exit;
}

$settingsStmt->execute();
$settings = $settingsStmt->get_result()->fetch_assoc();
$settingsStmt->close();

if (!$settings || empty($settings['hip_id'])) {
    $hospitalConn->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Active HIP configuration not found.']);
    exit;
}

$configuredHipId = trim((string)$settings['hip_id']);

// M2 Phase 0 / 0.2 - X-HIP-ID is MANDATORY.
// Previously: `if ($hipId !== '' && $hipId !== $configuredHipId)`, so simply
// OMITTING the header passed the only authentication check this endpoint
// performs. The sibling endpoint modules' discover handler rejects a missing
// header; this now matches that convention.
if ($hipId === '') {
    $hospitalConn->close();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'X-HIP-ID header is required.']);
    exit;
}

if ($hipId !== $configuredHipId) {
    $hospitalConn->close();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid X-HIP-ID.']);
    exit;
}

// ─────────────────────────────────────────────────────────
// Load M2 auth helper (preserve $conn)
// ─────────────────────────────────────────────────────────
$hospitalConnBackup = $hospitalConn;
$conn = $hospitalConn;

try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/abdm/m2_auth.php';
    $conn = $hospitalConnBackup;
} catch (Throwable $e) {
    $hospitalConn->close();
    abdmInitLog("M2 AUTH FILE LOAD ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load ABDM M2 authentication.']);
    exit;
}

// ─────────────────────────────────────────────────────────
// Look up transaction
// ─────────────────────────────────────────────────────────
$transactionStmt = $hospitalConn->prepare("
    SELECT id, transaction_id, request_id, patient_id, abha_address, status, response_payload
    FROM abdm_user_linking_transactions
    WHERE transaction_id = ?
    LIMIT 1
");

if (!$transactionStmt) {
    $hospitalConn->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to prepare transaction lookup.']);
    exit;
}

$transactionStmt->bind_param('s', $transactionId);
$transactionStmt->execute();
$transaction = $transactionStmt->get_result()->fetch_assoc();
$transactionStmt->close();

if (!$transaction) {
    $hospitalConn->close();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid transactionId.']);
    exit;
}

if (empty($transaction['patient_id'])) {
    $hospitalConn->close();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Patient is not associated with this transaction.']);
    exit;
}

if ($transaction['status'] !== 'Discovered' && $transaction['status'] !== 'Linking') {
    $hospitalConn->close();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Transaction is not available for linking.',
        'status'  => $transaction['status']
    ]);
    exit;
}

if (!empty($transaction['abha_address']) && $transaction['abha_address'] !== $abhaAddress) {
    $hospitalConn->close();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ABHA address does not match the discovery transaction.']);
    exit;
}

// ─────────────────────────────────────────────────────────
// Parse stored discovery data
// ─────────────────────────────────────────────────────────
$storedDiscovery = [];

if (!empty($transaction['response_payload'])) {
    $storedDiscovery = json_decode($transaction['response_payload'], true);
    if (!is_array($storedDiscovery)) {
        $storedDiscovery = [];
    }
}

$storedPatientRecords = $storedDiscovery['patient'] ?? [];
$patientId = (int)$transaction['patient_id'];

$validCareContexts = [];
foreach ($storedPatientRecords as $storedPatient) {
    if (!is_array($storedPatient)) {
        continue;
    }
    if (
        isset($storedPatient['referenceNumber']) &&
        isset($storedPatient['careContexts']) &&
        is_array($storedPatient['careContexts'])
    ) {
        foreach ($storedPatient['careContexts'] as $storedContext) {
            if (!is_array($storedContext)) {
                continue;
            }
            $referenceNumber = trim((string)($storedContext['referenceNumber'] ?? ''));
            if ($referenceNumber === '') {
                continue;
            }
            $validCareContexts[$referenceNumber] = [
                'referenceNumber' => $referenceNumber,
                'display'         => (string)($storedContext['display'] ?? ''),
                'hiType'          => (string)($storedPatient['hiType'] ?? 'OPConsultation')
            ];
        }
    }
}

$selectedCareContexts = [];
foreach ($patientRecords as $patientRecord) {
    if (!is_array($patientRecord)) {
        continue;
    }
    $submittedPatientReference = trim((string)($patientRecord['referenceNumber'] ?? ''));
    $submittedHiType = trim((string)($patientRecord['hiType'] ?? ''));
    $careContexts = $patientRecord['careContexts'] ?? [];

    if (!is_array($careContexts)) {
        continue;
    }

    foreach ($careContexts as $careContext) {
        if (!is_array($careContext)) {
            continue;
        }
        $referenceNumber = trim((string)($careContext['referenceNumber'] ?? ''));
        if ($referenceNumber === '') {
            continue;
        }
        if (!isset($validCareContexts[$referenceNumber])) {
            continue;
        }
        $selectedCareContexts[] = [
            'referenceNumber'        => $referenceNumber,
            'display'                => $validCareContexts[$referenceNumber]['display'],
            'hiType'                 => $submittedHiType !== ''
                ? $submittedHiType
                : $validCareContexts[$referenceNumber]['hiType'],
            'patientReferenceNumber' => $submittedPatientReference
        ];
    }
}

$selectedCareContexts = array_values(
    array_reduce(
        $selectedCareContexts,
        function ($carry, $item) {
            $carry[$item['referenceNumber']] = $item;
            return $carry;
        },
        []
    )
);

if (empty($selectedCareContexts)) {
    $hospitalConn->close();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No valid care contexts were selected.']);
    exit;
}

if (count($selectedCareContexts) > 20) {
    $hospitalConn->close();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Maximum 20 care contexts can be linked in one request.']);
    exit;
}

abdmInitLog(
    "EVENT: hip_link_care_context_init_validation_ok" .
    "\nTRANSACTION_ID_PRESENT: " . ($transactionId === '' ? 'NO' : 'YES') .
    "\nPATIENT_RECORD_COUNT: " . (is_array($patientRecords) ? count($patientRecords) : 0) .
    "\nABHA_ADDRESS_PRESENT: " . ($abhaAddress === '' ? 'NO' : 'YES') .
    "\nREQUEST_ID_PRESENT: " . ($requestId === '' ? 'NO' : 'YES') .
    "\nHIP_ID_PRESENT: " . ($configuredHipId === '' ? 'NO' : 'YES') .
    "\nSELECTED CARE CONTEXT COUNT: " . count($selectedCareContexts)
);

// ─────────────────────────────────────────────────────────
// Get M2 access token
// ─────────────────────────────────────────────────────────
$auth = abdmM2GetAccessToken();

if (empty($auth['success']) || empty($auth['access_token'])) {
    abdmInitLog(
        "M2 AUTH FAILED" .
        "\nHTTP CODE: " . (int)($auth['http_code'] ?? 0) .
        "\nERROR: " . ($auth['error'] ?? '') .
        "\nREQUEST-ID: " . ($auth['request_id'] ?? '')
    );
    $hospitalConn->close();
    http_response_code(500);
    echo json_encode([
        'success'   => false,
        'message'   => 'M2 authentication failed.',
        'http_code' => $auth['http_code'] ?? 0,
        'error'     => $auth['error'] ?? 'Unknown M2 authentication error.'
    ]);
    exit;
}

$accessToken = $auth['access_token'];

// ─────────────────────────────────────────────────────────
// Build on-init payload
// ─────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────────────
// REPLAY GUARD - prevent a duplicate SMS when ABDM retries the on-init callback
// ─────────────────────────────────────────────────────────────────────────────
// A previous attempt for this transaction already produced an OTP and, because
// that attempt only reaches this point after a successful dispatch, already put
// an SMS on the patient's phone. Generating and sending again would deliver a
// second code, so a retry is acknowledged with the ORIGINAL link reference and
// the ORIGINAL expiry, and nothing is regenerated, re-persisted or re-sent.
//
// This runs BEFORE the outbound on-init for a specific reason: that call carries
// the link reference. Reusing an earlier reference while telling ABDM about a new
// one would leave on-confirm unable to resolve the reference, so the guard must
// short-circuit before the new reference is minted.
//
// An expired or attempt-exhausted row is deliberately NOT matched. A stale attempt
// is a different case from a duplicate retry and must still be able to receive a
// fresh code.
$existingOtpStmt = $hospitalConn->prepare("
    SELECT link_reference_number, expires_at
    FROM abdm_user_linking_otps
    WHERE transaction_id = ?
      AND expires_at > NOW()
      AND attempts < 5
    ORDER BY id DESC
    LIMIT 1
");

$existingOtpRow = null;

if ($existingOtpStmt) {
    $existingOtpStmt->bind_param('s', $transactionId);
    $existingOtpStmt->execute();
    $existingOtpRow = $existingOtpStmt->get_result()->fetch_assoc();
    $existingOtpStmt->close();
}

if (!empty($existingOtpRow['link_reference_number'])) {
    $replayLinkReference = (string)$existingOtpRow['link_reference_number'];

    abdmInitLog(
        "EVENT: hip_link_care_context_otp_replay_suppressed" .
        "\nLINK_REF: {$replayLinkReference}" .
        "\nSMS_SENT: NO"
    );

    $hospitalConn->close();

    http_response_code(202);

    echo json_encode([
        'success'                => true,
        'message'                => 'Link initialization processed successfully.',
        'transactionId'          => $transactionId,
        'linkReferenceNumber'    => $replayLinkReference,
        'authenticationType'     => 'MEDIATE',
        'communicationMedium'    => 'MOBILE',
        'communicationHint'      => 'OTP',
        'communicationExpiry'    => gmdate('Y-m-d\TH:i:s.v\Z', strtotime((string)$existingOtpRow['expires_at']))
    ]);
    exit;
}

$linkReferenceNumber  = abdmM2GenerateRequestId();
$communicationExpiry  = gmdate('Y-m-d\TH:i:s.v\Z', time() + 600);

$linkPayload = [
    'transactionId' => $transactionId,
    'link' => [
        'referenceNumber'    => $linkReferenceNumber,
        'authenticationType' => 'MEDIATE',
        'meta' => [
            'communicationMedium'   => 'MOBILE',
            'communicationHint'     => 'OTP',
            'communicationExpiry'   => $communicationExpiry
        ]
    ],
    'response' => [
        'requestId' => $requestId
    ]
];

$timestamp    = gmdate('Y-m-d\TH:i:s.v\Z');
$apiRequestId = abdmM2GenerateRequestId();
$url = ABDM_M2_BASE_URL . '/hiecm/user-initiated-linking/v3/link/care-context/on-init';

$onInitJson = json_encode($linkPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

abdmInitLog(
    "ON-INIT OUTBOUND REQUEST" .
    "\nURL: " . $url .
    "\nREQUEST-ID: " . $apiRequestId .
    "\nTIMESTAMP: " . $timestamp .
    "\nX-CM-ID: " . ABDM_M2_CM_ID .
    "\nPAYLOAD: " . $onInitJson
);

// ─────────────────────────────────────────────────────────
// Send on-init to ABDM
// ─────────────────────────────────────────────────────────
$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $accessToken,
        'REQUEST-ID: ' . $apiRequestId,
        'TIMESTAMP: ' . $timestamp,
        'X-HIP-ID: ' . $configuredHipId,
        'X-CM-ID: ' . ABDM_M2_CM_ID
    ],
    CURLOPT_POSTFIELDS     => $onInitJson,
    CURLOPT_TIMEOUT        => ABDM_M2_TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2
]);

$response      = curl_exec($ch);
$httpCode      = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError     = curl_error($ch);
$totalTime     = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
curl_close($ch);

$responseData = json_decode($response, true);

abdmInitLog(
    "ON-INIT OUTBOUND RESPONSE" .
    "\nHTTP CODE: " . $httpCode .
    "\nCURL ERROR: " . $curlError .
    "\nTOTAL TIME: " . $totalTime .
    "\nRESPONSE_BYTES: " . strlen((string)$response)
);

if ($response === false || $curlError !== '') {
    $hospitalConn->close();
    http_response_code(500);
    echo json_encode([
        'success'   => false,
        'message'   => 'Unable to send link initialization response to ABDM.',
        'http_code' => $httpCode,
        'error'     => $curlError
    ]);
    exit;
}

if ($httpCode < 200 || $httpCode >= 300) {
    $hospitalConn->close();
    http_response_code(500);
    echo json_encode([
        'success'   => false,
        'message'   => 'ABDM rejected link initialization response.',
        'http_code' => $httpCode,
        'response'  => $responseData ?: $response
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────
// 🔑 GENERATE + SEND OTP
// This is the NEW block that was missing.
// ─────────────────────────────────────────────────────────
$mobileStmt = $hospitalConn->prepare("
    SELECT mobile, first_name
    FROM patient_master
    WHERE id = ?
    LIMIT 1
");

$mobileStmt->bind_param('i', $patientId);
$mobileStmt->execute();
$patientRow = $mobileStmt->get_result()->fetch_assoc();
$mobileStmt->close();

$mobile = preg_replace('/\D+/', '', (string)($patientRow['mobile'] ?? ''));
if (strlen($mobile) === 10) {
    $mobile = '91' . $mobile;
}

if ($mobile === '') {
    abdmInitLog("EVENT: hip_link_care_context_otp_send_skipped reason=NO_MOBILE");
} else {
    // Generate 6-digit OTP
    $otp       = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otpHash   = password_hash($otp, PASSWORD_BCRYPT);
    $otpExpiry = date('Y-m-d H:i:s', time() + 300); // 5 minutes

// Save OTP hash
       //
       // B-05: this INSERT previously carried "ON DUPLICATE KEY UPDATE
       // patient_id=..., otp_hash=..., expires_at=..., attempts=0". That branch
       // was DEAD CODE. abdm_user_linking_otps carries exactly one unique key -
       // UNIQUE KEY link_reference_number (link_reference_number), verified
       // against the live schema - and $linkReferenceNumber is generated fresh
       // for every request at init:451 by abdmM2GenerateRequestId(). The
       // conflict column and the inserted value were therefore always
       // different, so the UPDATE could never run.
       //
       // Removing it is also a safety improvement. Had the reference ever
       // collided, the old clause would have silently overwritten ANOTHER
       // transaction's row - changing its patient_id and resetting its attempts
       // counter to 0, which weakens the replay allowance. A plain INSERT makes
       // a collision a loud, reported failure instead: $otpStored stays false
       // and the handler answers 500 below.
       $otpStmt = $hospitalConn->prepare("
           INSERT INTO abdm_user_linking_otps
           (transaction_id, link_reference_number, patient_id, otp_hash, expires_at, attempts, created_at)
           VALUES (?, ?, ?, ?, ?, 0, NOW())
       ");

    // M2 Phase 0 / 0.5 - the OTP row MUST exist, otherwise the confirm endpoint
    // has nothing to verify against. Previously execute()'s result was discarded
    // and the handler still answered 202, which silently manufactured an
    // unverifiable confirmation. Now a failure is terminal and is reported
    // honestly. The SQL reason is logged server-side only, never returned.
    $otpStored = false;
    if (!$otpStmt) {
        abdmInitLog('OTP_PERSIST_FAILED: prepare failed');
    } else {
        $otpStmt->bind_param(
            'ssiss',
            $transactionId,
            $linkReferenceNumber,
            $patientId,
            $otpHash,
            $otpExpiry
        );
        /* A thrown mysqli_sql_exception is handled alongside the return value, so the
           guard holds whether or not the host sets MYSQLI_REPORT_STRICT. */
        $otpStored = false;
        try {
            $otpStored = (bool) $otpStmt->execute();
        } catch (Throwable $e) {
            abdmInitLog('OTP_PERSIST_FAILED: execute threw');
            error_log('init: OTP insert threw | ' . $e->getMessage());
        }
        if (!$otpStored) {
            abdmInitLog('OTP_PERSIST_FAILED: execute failed');
        }
        $otpStmt->close();
    }

    if (!$otpStored) {
        if (isset($hospitalConn) && $hospitalConn instanceof mysqli) {
            $hospitalConn->close();
        }
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to complete the link request. Please retry.'
        ]);
        exit;
    }

    // ─────────────────────────────────────────────────────
    // SEND SMS - real dispatch via the project's existing provider integration
    // ─────────────────────────────────────────────────────
    // M2 Phase 0 / 0.4 - the OTP, the mobile number and the patient id are never
    // written to disk in plain text. The diagnostics below record only
    // non-identifying facts: whether the template resolved, whether the provider
    // accepted the dispatch, and the HTTP status. The plaintext OTP exists only in
    // the $otp variable for the lifetime of this request.
    require_once $_SERVER['DOCUMENT_ROOT'] . '/communication/sms/send_sms.php';

    $smsTemplatePath = $_SERVER['DOCUMENT_ROOT']
        . '/communication/sms/sms_templates/abdm_link_otp.php';

    $smsDispatched = false;

    if (!file_exists($smsTemplatePath)) {
        abdmInitLog(
            "EVENT: hip_link_care_context_otp_sms_failed" .
            "\nREASON: TEMPLATE_MISSING" .
            "\nSMS_SENT: NO"
        );
    } else {
        $smsMessage = require $smsTemplatePath;
        $smsDispatched = sendSMS($mobile, $smsMessage);
        unset($smsMessage);

        abdmInitLog(
            "EVENT: hip_link_care_context_otp_sms_dispatch" .
            "\nSMS_SENT: " . ($smsDispatched ? 'YES' : 'NO') .
            "\nLINK_REF: {$linkReferenceNumber}"
        );
    }

    if (!$smsDispatched) {
        /*
         * The OTP stored a moment ago can never reach the patient, so it is
         * removed instead of being left to expire against someone who was never
         * sent a code and has no way to know the link failed. Removing it is also
         * what makes the retry story exact: the next attempt finds no stored OTP,
         * mints a fresh reference and dispatches exactly one SMS - never two.
         *
         * The delete is best-effort. If it fails the row simply expires on its
         * own; nothing unsafe is created either way, because the callback answers
         * 500 and no care context is ever marked linked without a real on-confirm.
         */
        $deleteOtpStmt = $hospitalConn->prepare("
            DELETE FROM abdm_user_linking_otps
            WHERE link_reference_number = ?
            LIMIT 1
        ");

        if ($deleteOtpStmt) {
            $deleteOtpStmt->bind_param('s', $linkReferenceNumber);
            $deleteOtpStmt->execute();
            $deleteOtpStmt->close();
        }

        // Reason only - never the OTP, the mobile number or any provider secret.
        error_log('init: OTP SMS dispatch failed; stored OTP removed for this link reference.');

        $hospitalConn->close();

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to send the verification code. Please retry.'
        ]);
        exit;
    }
}

// ─────────────────────────────────────────────────────────
// Save transaction state
// ─────────────────────────────────────────────────────────
$storedResponse = [
    'transactionId'        => $transactionId,
    'abhaAddress'          => $abhaAddress,
    'patientId'            => $patientId,
    'selectedCareContexts' => $selectedCareContexts,
    'link'                 => $linkPayload['link'],
    'response'             => $linkPayload['response'],
    'apiRequestId'         => $apiRequestId,
    'onInitHttpCode'       => $httpCode,
    'onInitResponse'       => $responseData ?: $response,
    'processedAt'          => gmdate('Y-m-d\TH:i:s.v\Z')
];

$storedResponseJson = json_encode($storedResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$updateStmt = $hospitalConn->prepare("
    UPDATE abdm_user_linking_transactions
    SET
        status = 'Linking',
        response_payload = ?
    WHERE transaction_id = ?
    LIMIT 1
");

if (!$updateStmt) {
    $hospitalConn->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to prepare transaction update.']);
    exit;
}

$updateStmt->bind_param('ss', $storedResponseJson, $transactionId);

if (!$updateStmt->execute()) {
    $updateStmt->close();
    $hospitalConn->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update linking transaction.']);
    exit;
}

$updateStmt->close();

// Save incoming REQUEST-ID for the confirm handler
$saveInitReqStmt = $hospitalConn->prepare("
    UPDATE abdm_user_linking_transactions
    SET init_request_id = ?
    WHERE transaction_id = ?
    LIMIT 1
");

if ($saveInitReqStmt) {
    $saveInitReqStmt->bind_param('ss', $requestId, $transactionId);
    $saveInitReqStmt->execute();
    $saveInitReqStmt->close();
    abdmInitLog("SAVED init_request_id: [" . $requestId . "]");
}

$hospitalConn->close();

http_response_code(202);

echo json_encode([
    'success'                => true,
    'message'                => 'Link initialization processed successfully.',
    'transactionId'          => $transactionId,
    'linkReferenceNumber'    => $linkReferenceNumber,
    'authenticationType'     => 'MEDIATE',
    'communicationMedium'    => 'MOBILE',
    'communicationHint'      => 'OTP',
    'communicationExpiry'    => $communicationExpiry
]);