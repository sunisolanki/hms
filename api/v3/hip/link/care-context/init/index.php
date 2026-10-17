<?php
header('Content-Type: application/json');

/*
 * DIAGNOSTICS NOW GO TO THE PHP ERROR LOG
 *
 * This previously appended to __DIR__ . '/abdm_init_test.log', a file that sits
 * INSIDE THE WEB ROOT and is therefore downloadable over HTTP. It was not named
 * in the .htaccess <FilesMatch> block that denies abdm_callback_debug.txt, so the
 * protection that covers the other diagnostics did not cover it.
 *
 * error_log() keeps every diagnostic still emitted below while removing the
 * file from the web root entirely. Newlines are folded to ' | ' so each event is
 * one greppable line rather than a multi-line entry.
 */
function abdmInitLog($message)
{
    error_log('[ABDM-INIT] ' . preg_replace('/\s*\R\s*/', ' | ', trim((string)$message)));
}

$rawBody = file_get_contents('php://input');

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
/*
 * OTP REPLAY GUARD REMOVED
 *
 * This block existed only to stop a retried on-init from sending a SECOND SMS
 * with a different code, which would have invalidated the first. It queried
 * abdm_user_linking_otps for an unexpired, attempt-budgeted row and echoed the
 * ORIGINAL link reference back to the gateway.
 *
 * With the OTP flow retired there is no code to duplicate and no SMS to
 * suppress, so the guard had nothing left to protect. Its table is dropped in
 * the same change.
 *
 * The link reference is now minted fresh below, on every call, exactly as it
 * already was for the non-retry path.
 */

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
// SMS OTP GENERATION AND DISPATCH REMOVED
//
// This block minted a 6-digit OTP, bcrypt-hashed it into
// abdm_user_linking_otps, and dispatched it through this installation's SMS
// provider (communication/sms/send_sms.php using the abdm_link_otp template).
// It answered ABDM's on-init callback with authenticationType = MEDIATE and
// communicationHint = OTP, which made the PATIENT transcribe a code that this
// system had texted them.
//
// That leg is retired. Linking is now HIP-initiated over the v0.5 gateway
// family: the patient authorises in the ABHA Sandbox app, the Consent Manager
// issues the linking token, and it is captured by
// api/v0.5/users/auth/on-confirm/index.php and spent on
// /gateway/v0.5/links/link/add-contexts.
//
// Consequences of this removal:
//   * No OTP is generated, stored, texted or verified anywhere in this codebase.
//   * communication/sms/send_sms.php is UNTOUCHED. It still has three unrelated
//     non-ABDM callers (settings/users/save_user.php, masters/doctor/
//     save_doctor.php, masters/hospital/save.php) and removing it would break
//     new-user, new-doctor and new-hospital notifications.
//   * The abdm_user_linking_otps table is dropped by
//     database_work/m2_cm_linking_token_migration.sql's sibling migration.
//   * api/v3/hip/link/care-context/confirm/index.php still reads that table for
//     OTP verification. That leg must be retired separately; until it is, a
//     confirm callback will fail to find its row and return 404 rather than
//     silently confirming anything.
//
// THIS ENDPOINT STILL ANSWERS 202 ON PURPOSE.
// ABDM's HiECM gateway drives this callback and will call it regardless of what
// this file does, so the gateway contract must remain satisfied: the on-init
// call is still made, the transaction is still persisted, and 202 is still
// returned. What has changed is that no code is sent.

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
