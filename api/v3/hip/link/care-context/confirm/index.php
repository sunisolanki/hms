<?php
header('Content-Type: application/json');

$rawBody = file_get_contents('php://input');

/*
 * SAFE DIAGNOSTIC LOGGING
 * -----------------------
 * This handler previously wrote getallheaders() with NO redaction plus the full
 * raw request body to abdm_confirm_test.log. The raw body carries
 * confirmation.token, i.e. the user-initiated-linking OTP in plaintext.
 *
 * Only non-identifying diagnostics are retained now: the event, the method, a
 * per-header presence map (never values), the body SIZE, and correlation-id
 * presence. The log file itself is kept - no history is deleted - but it will
 * no longer accumulate secrets or patient identifiers.
 */
$inboundHeaders = function_exists('getallheaders') ? getallheaders() : [];
$headerPresence = [];
if (is_array($inboundHeaders)) {
    foreach ($inboundHeaders as $headerName => $headerValue) {
        $headerName = strtolower((string)$headerName);
        $isSensitive =
            strpos($headerName, 'authorization') !== false ||
            strpos($headerName, 'token') !== false ||
            strpos($headerName, 'cookie') !== false ||
            strpos($headerName, 'auth') !== false ||
            $headerName === 'x-hiu-id' ||
            $headerName === 'x-cm-id';
        $headerPresence[$headerName] = $isSensitive ? '[REDACTED]' : '[present]';
    }
}

file_put_contents(
    __DIR__ . '/abdm_confirm_test.log',
    date('Y-m-d H:i:s') .
    "\nEVENT: hip_link_care_context_confirm_received" .
    "\nMETHOD: " . ($_SERVER['REQUEST_METHOD'] ?? '') .
    "\nHEADERS: " . json_encode($headerPresence) .
    "\nBODY_BYTES: " . (is_string($rawBody) ? strlen($rawBody) : 0) .
    "\nREQUEST_ID_PRESENT: " . (!empty($_SERVER['HTTP_REQUEST_ID']) ? 'YES' : 'NO') .
    "\n--------------------\n",
    FILE_APPEND
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

$token         = trim((string)($data['confirmation']['token'] ?? ''));
$linkRefNumber = trim((string)($data['confirmation']['linkRefNumber'] ?? ''));

if ($token === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'confirmation.token is required.']);
    exit;
}

if ($linkRefNumber === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'confirmation.linkRefNumber is required.']);
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

$controlConn = new mysqli(
    $controlCredentials['host'],
    $controlCredentials['user'],
    $controlCredentials['pass'],
    $controlCredentials['db']
);

if ($controlConn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Control database connection failed.']);
    exit;
}

$controlConn->set_charset('utf8mb4');

$stmt = $controlConn->prepare("
    SELECT database_host, database_name, database_user, database_password
    FROM projects
    WHERE database_name = 'u336643111_hms'
    LIMIT 1
");

if (!$stmt) {
    $controlConn->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to prepare hospital database lookup.']);
    exit;
}

$stmt->execute();
$tenant = $stmt->get_result()->fetch_assoc();
$stmt->close();
$controlConn->close();

if (!$tenant) {
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
$tenantCredentials = hmsTenantCredentials($tenant);

$conn = new mysqli(
    $tenantCredentials['host'],
    $tenantCredentials['user'],
    $tenantCredentials['pass'],
    $tenantCredentials['db']
);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Hospital database connection failed.']);
    exit;
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+05:30'");

// ─────────────────────────────────────────────────────────
// M2 P0 - SOURCE IDENTITY BEFORE ANY STATE CHANGE
// ─────────────────────────────────────────────────────────
// The ABDM settings read and the X-HIP-ID / X-HIU-ID checks previously sat
// AFTER the OTP block. An unauthenticated caller who knew a linkRefNumber
// could therefore increment `attempts` and mutate the OTP row BEFORE any
// source validation ran, and could burn the 5-attempt allowance to lock the
// legitimate patient out.
//
// Two corrections:
//   1. This whole block now runs before the first OTP read or write.
//   2. Both headers are MANDATORY. The old predicates were
//      `$incomingHipId !== '' && $configuredHipId !== '' && ...`, which skipped
//      validation entirely whenever the header was absent - so omitting
//      X-HIP-ID disabled the only source check this endpoint performed.
//      This now matches the fail-closed convention used by init, discover,
//      on-generate-token and on_carecontext.
$settingsStmt = $conn->prepare("
    SELECT id, hip_id, hiu_id
    FROM abdm_m2_settings
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if (!$settingsStmt) {
    $conn->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to verify ABDM configuration.']);
    exit;
}

$settingsStmt->execute();
$settings = $settingsStmt->get_result()->fetch_assoc();
$settingsStmt->close();

if (!$settings) {
    $conn->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Active ABDM M2 configuration not found.']);
    exit;
}

$incomingHiuId   = trim((string)($_SERVER['HTTP_X_HIU_ID'] ?? ''));
$incomingHipId   = trim((string)($_SERVER['HTTP_X_HIP_ID'] ?? ''));
$configuredHiuId = trim((string)($settings['hiu_id'] ?? ''));
$configuredHipId = trim((string)($settings['hip_id'] ?? ''));

if ($incomingHipId === '') {
    $conn->close();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'X-HIP-ID header is missing.']);
    exit;
}

if ($configuredHipId === '' || $incomingHipId !== $configuredHipId) {
    $conn->close();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid HIP ID.']);
    exit;
}

// X-HIU-ID is only meaningful when this installation is configured as an HIU,
// so it is mandatory exactly in that case and skipped otherwise.
if ($configuredHiuId !== '') {
    if ($incomingHiuId === '') {
        $conn->close();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'X-HIU-ID header is missing.']);
        exit;
    }

    if ($incomingHiuId !== $configuredHiuId) {
        $conn->close();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid HIU ID.']);
        exit;
    }
}

// ─────────────────────────────────────────────────────────
// Verify OTP  (M2 Phase 0 / 0.1 - fail closed)
// ─────────────────────────────────────────────────────────
// PREVIOUSLY: the whole block sat inside `if ($verifyStmt)`, and the
// "no OTP row" branch only wrote a log line and fell through. Either condition
// therefore allowed processing to continue to status='Confirmed' and
// link_status='Linked' with NO OTP ever verified. Both are now terminal.
$verifyStmt = $conn->prepare("
    SELECT id, otp_hash, expires_at, attempts
    FROM abdm_user_linking_otps
    WHERE link_reference_number = ?
    LIMIT 1
");

if (!$verifyStmt) {
    // Do not disclose SQL detail to the caller; detail stays server-side.
    error_log('ABDM_CONFIRM | OTP lookup could not be prepared; refusing.');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to verify confirmation.']);
    exit;
}

$verifyStmt->bind_param('s', $linkRefNumber);
$verifyStmt->execute();
$otpRow = $verifyStmt->get_result()->fetch_assoc();
$verifyStmt->close();

if (!$otpRow) {
    // Previously: log-and-continue. Now: terminal.
    error_log('ABDM_CONFIRM | OTP record not found for the supplied link reference; refusing.');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or expired link reference.'
    ]);
    exit;
}

file_put_contents(
    __DIR__ . '/abdm_confirm_test.log',
    date('Y-m-d H:i:s') .
    "\nEVENT: hip_link_care_context_confirm_otp_record_found" .
    "\nOTP_EXPIRES_PRESENT: " . (!empty($otpRow['expires_at']) ? 'YES' : 'NO') .
    "\n--------------------\n",
    FILE_APPEND
);

if (strtotime($otpRow['expires_at']) < time()) {
    $conn->close();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'OTP expired.']);
    exit;
}

if ((int)$otpRow['attempts'] >= 5) {
    $conn->close();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Too many attempts.']);
    exit;
}

if (!password_verify($token, $otpRow['otp_hash'])) {
    /* The attempt counter is the only replay control on this endpoint. It was
       written with a string-interpolated query whose result was discarded, so a
       failed write silently left the counter unchanged and a caller could retry
       indefinitely. Prepared statement, checked result, terminal on failure. */
    $attemptStmt = $conn->prepare("
        UPDATE abdm_user_linking_otps
        SET attempts = attempts + 1
        WHERE id = ?
        LIMIT 1
    ");

    if (!$attemptStmt) {
        $conn->close();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to record OTP attempt.']);
        exit;
    }

    $attemptStmt->bind_param('i', (int)$otpRow['id']);
    $attemptRecorded = false;

    try {
        $attemptRecorded = (bool)$attemptStmt->execute();
    } catch (Throwable $e) {
        error_log('confirm: OTP attempt update threw | ' . $e->getMessage());
    }

    $attemptStmt->close();

    if (!$attemptRecorded) {
        $conn->close();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to record OTP attempt.']);
        exit;
    }

    $conn->close();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid OTP.']);
    exit;
}

/* Consume the OTP. This is the single-use control: the row's expires_at is
   moved to NOW(), so any later replay of the same link reference is refused by
   the expiry test above. The write was previously unchecked, which meant a
   failed consume still proceeded to 'Confirmed'. It is now terminal on
   failure so an unconsumed token can never be reused. */
$consumeStmt = $conn->prepare("
    UPDATE abdm_user_linking_otps
    SET expires_at = NOW()
    WHERE id = ?
      AND expires_at > NOW()
    LIMIT 1
");

if (!$consumeStmt) {
    $conn->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to consume OTP.']);
    exit;
}

$consumeStmt->bind_param('i', (int)$otpRow['id']);
$otpConsumed = false;

try {
    $otpConsumed = ($consumeStmt->execute() && $consumeStmt->affected_rows === 1);
} catch (Throwable $e) {
    error_log('confirm: OTP consume threw | ' . $e->getMessage());
}

$consumeStmt->close();

if (!$otpConsumed) {
    $conn->close();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or expired link reference.'
    ]);
    exit;
}

file_put_contents(
    __DIR__ . '/abdm_confirm_test.log',
    date('Y-m-d H:i:s') .
    "\nEVENT: hip_link_care_context_confirm_otp_verified" .
    "\n--------------------\n",
    FILE_APPEND
);

// ─────────────────────────────────────────────────────────
// M2 P0 - source identity (ABDM settings + mandatory X-HIP-ID / X-HIU-ID)
// was moved ABOVE the OTP block. It must never run after an OTP mutation.
// $configuredHipId and $configuredHiuId are already in scope from there and
// are reused by the on-confirm request further down.
// ─────────────────────────────────────────────────────────

// ─────────────────────────────────────────────────────────
// Find matching linking transaction
// ─────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT id, transaction_id, request_id, patient_id, abha_address, status, response_payload
    FROM abdm_user_linking_transactions
    WHERE status = 'Linking'
    ORDER BY id DESC
");

if (!$stmt) {
    $conn->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to prepare linking transaction lookup.']);
    exit;
}

$stmt->execute();
$result = $stmt->get_result();
$transaction = null;

while ($row = $result->fetch_assoc()) {
    if (empty($row['response_payload'])) {
        continue;
    }
    $storedResponse = json_decode($row['response_payload'], true);
    if (!is_array($storedResponse)) {
        continue;
    }
    $storedLinkReference = trim((string)($storedResponse['link']['referenceNumber'] ?? ''));
    if ($storedLinkReference !== '' && hash_equals($storedLinkReference, $linkRefNumber)) {
        $transaction = $row;
        break;
    }
}

$stmt->close();

if (!$transaction) {
    $conn->close();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid linkRefNumber.']);
    exit;
}

$transactionId = trim((string)$transaction['transaction_id']);
$patientId     = (int)$transaction['patient_id'];
$storedResponse = json_decode($transaction['response_payload'], true);

if (!is_array($storedResponse)) {
    $storedResponse = [];
}

$selectedCareContexts = $storedResponse['selectedCareContexts'] ?? [];

if (!is_array($selectedCareContexts) || empty($selectedCareContexts)) {
    $conn->close();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No care contexts found for this linking transaction.']);
    exit;
}

$linkedReferences = [];

foreach ($selectedCareContexts as $careContext) {
    if (!is_array($careContext)) {
        continue;
    }
    $referenceNumber = trim((string)($careContext['referenceNumber'] ?? ''));
    if ($referenceNumber === '') {
        continue;
    }
    $display = trim((string)($careContext['display'] ?? ''));
    $hiType  = trim((string)($careContext['hiType'] ?? 'OPConsultation'));
    $linkedReferences[] = [
        'referenceNumber' => $referenceNumber,
        'display'         => $display,
        'hiType'          => $hiType
    ];
}

if (empty($linkedReferences)) {
    $conn->close();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No valid care contexts found.']);
    exit;
}

$originalOnInitRequestId = trim((string)($storedResponse['response']['requestId'] ?? ''));

if ($originalOnInitRequestId === '') {
    $originalOnInitRequestId = trim((string)($transaction['request_id'] ?? ''));
}


// ─────────────────────────────────────────────────────────
// Link care contexts  (Phase 3 B-13 / B-14)
//
// B-13: every write result is now checked. Previously only prepare() was
// null-checked; execute() was called blind, so a failed care-context write was
// still reported as a success.
//
// B-14: linkedCareContexts below now reports the number of care contexts
// ACTUALLY persisted, not count($linkedReferences), which was the number
// supplied in the request.
//
// The writes run inside one transaction. abdm_care_contexts carries
// UNIQUE KEY unique_care_context (patient_id, care_context_reference), so a
// rollback cannot leave a half-linked patient behind.
// ─────────────────────────────────────────────────────────

$persistedCareContexts = [];
$careContextWriteFailures = [];

$conn->begin_transaction();

foreach ($linkedReferences as $careContext) {
    $referenceNumber = $careContext['referenceNumber'];
    $display         = $careContext['display'];
    $hiType          = $careContext['hiType'];

    $existingStmt = $conn->prepare("
        SELECT id
        FROM abdm_care_contexts
        WHERE patient_id = ?
        AND care_context_reference = ?
        LIMIT 1
    ");

    if (!$existingStmt) {
        $careContextWriteFailures[] = $referenceNumber;
        error_log('confirm: care-context select prepare failed | ' . $conn->error);
        continue;
    }

    $existingStmt->bind_param("is", $patientId, $referenceNumber);

    /* The try/catch is not redundant. This file does not call mysqli_report(),
       so execute() returns false on error. But 11 other application files DO
       set MYSQLI_REPORT_STRICT, and under that mode execute() THROWS instead of
       returning false - which would skip the check below entirely, escape as an
       uncaught mysqli_sql_exception, emit a non-JSON response and leave the
       transaction half-written. Handling both modes keeps the guard correct on
       any host. */
    $selectFailed = false;
    try {
        if (!$existingStmt->execute()) { $selectFailed = true; }
    } catch (Throwable $e) {
        $selectFailed = true;
        error_log('confirm: care-context select threw | ' . $e->getMessage());
    }

    if ($selectFailed) {
        error_log('confirm: care-context select execute failed');
        $existingStmt->close();
        $careContextWriteFailures[] = $referenceNumber;
        continue;
    }

    $existing = $existingStmt->get_result()->fetch_assoc();
    $existingStmt->close();

    if ($existing) {
        $stmt = $conn->prepare("
            UPDATE abdm_care_contexts
            SET care_context_display = ?,
                care_context_type    = ?,
                link_status          = 'Linked',
                linked_at            = CURRENT_TIMESTAMP,
                updated_at           = CURRENT_TIMESTAMP
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            error_log('confirm: care-context update prepare failed | ' . $conn->error);
            $careContextWriteFailures[] = $referenceNumber;
            continue;
        }

        $stmt->bind_param("ssi", $display, $hiType, $existing['id']);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO abdm_care_contexts
            (patient_id, care_context_reference, care_context_display,
             care_context_type, link_status, linked_at)
            VALUES (?, ?, ?, ?, 'Linked', CURRENT_TIMESTAMP)
        ");

        if (!$stmt) {
            error_log('confirm: care-context insert prepare failed | ' . $conn->error);
            $careContextWriteFailures[] = $referenceNumber;
            continue;
        }

        $stmt->bind_param("isss", $patientId, $referenceNumber, $display, $hiType);
    }

    /* B-13: this is the check that was missing. See the note above - both the
       return value and a thrown mysqli_sql_exception are handled, so the guard
       holds whether or not the host sets MYSQLI_REPORT_STRICT. */
    $writeFailed = false;
    try {
        if (!$stmt->execute()) { $writeFailed = true; }
    } catch (Throwable $e) {
        $writeFailed = true;
        error_log('confirm: care-context write threw | ' . $e->getMessage());
    }

    if ($writeFailed) {
        error_log('confirm: care-context write execute failed');
        $stmt->close();
        $careContextWriteFailures[] = $referenceNumber;
        continue;
    }

    $stmt->close();
    $persistedCareContexts[] = $careContext;
}

if (empty($persistedCareContexts)) {
    // No required database write succeeded. Do NOT report success.
    $conn->rollback();
    $conn->close();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'No care context could be persisted.'
    ]);
    exit;
}

if (!empty($careContextWriteFailures)) {
    // Partial success: the ones that did persist are kept, the failures are
    // logged, and the true persisted count is reported below. The gateway
    // callback contract is unchanged - this is still a 202 - but the failure is
    // no longer silent.
    error_log(
        'confirm: care-context write failures = ' . count($careContextWriteFailures) .
        ' of ' . count($linkedReferences)
    );
}

if (!$conn->commit()) {
    error_log('confirm: care-context transaction commit failed | ' . $conn->error);
    $conn->close();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Care context link could not be committed.'
    ]);
    exit;
}

/* From here on $linkedReferences is replaced by what was actually written. The
   on-confirm payload below is built from this list, so ABDM is told about the
   care contexts that really exist rather than the ones that were requested. */
$linkedReferences = $persistedCareContexts;

// ─────────────────────────────────────────────────────────
// Phase 3 B-12 - attempt the outbound on-confirm BEFORE answering
//
// Previously this file answered HTTP 202 and called fastcgi_finish_request()
// first, and only then performed the on-confirm call. A failure of the
// on-confirm left the gateway believing the link was confirmed while nothing
// had been sent upstream - a permanent silent desync with no retry.
//
// The callback CONTRACT is unchanged: ABDM still receives HTTP 202 with the
// same JSON keys. What changed is the ordering, so the on-confirm is actually
// attempted before the response is committed, and its outcome is recorded in
// the transaction row instead of being swallowed by an exit.
// ─────────────────────────────────────────────────────────

$onConfirmOutcome = 'attempted';

try {
    $hospitalConn = $conn;

    require_once dirname(__DIR__, 6) . '/modules/abdm/m2_auth.php';

    $conn = $hospitalConn;

    $auth = abdmM2GetAccessToken();

    if (empty($auth['success']) || empty($auth['access_token'])) {
        $onConfirmOutcome = 'auth_failed';
        error_log(
            'confirm: on-confirm auth failed | http_code=' . (int)($auth['http_code'] ?? 0) .
            ' error_present=' . (empty($auth['error']) ? 'NO' : 'YES')
        );
    } else {
        $accessToken = $auth['access_token'];

        $onConfirmRequestId = abdmM2GenerateRequestId();
        $timestamp          = gmdate('Y-m-d\TH:i:s.v\Z');

        $patientRecordsByHiType = [];

        foreach ($linkedReferences as $careContext) {
            $hiType = trim((string)($careContext['hiType'] ?? 'OPConsultation'));

            if ($hiType === '') {
                $hiType = 'OPConsultation';
            }

            if (!isset($patientRecordsByHiType[$hiType])) {
                $patientRecordsByHiType[$hiType] = [
                    'referenceNumber' => $linkRefNumber,
                    'careContexts' => [],
                    'hiType' => $hiType,
                    'count' => 0
                ];
            }

            $patientRecordsByHiType[$hiType]['careContexts'][] = [
                'referenceNumber' => $careContext['referenceNumber'],
                'display' => $careContext['display']
            ];
        }

        foreach ($patientRecordsByHiType as $hiType => $patientRecord) {
            $patientRecordsByHiType[$hiType]['count'] = count(
                $patientRecordsByHiType[$hiType]['careContexts']
            );
        }

        $patientRecords = array_values($patientRecordsByHiType);

        $onConfirmPayload = [
            'patient' => $patientRecords,
            'response' => [
                'requestId' => $originalOnInitRequestId
            ]
        ];

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => ABDM_M2_BASE_URL . '/hiecm/user-initiated-linking/v3/link/care-context/on-confirm',
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'REQUEST-ID: ' . $onConfirmRequestId,
                'TIMESTAMP: ' . $timestamp,
                'X-HIP-ID: ' . $configuredHipId,
                'X-CM-ID: ' . ABDM_M2_CM_ID,
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode(
                $onConfirmPayload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        ]);

        $onConfirmResponse  = curl_exec($ch);
        $onConfirmHttpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $onConfirmCurlError = curl_error($ch);
        curl_close($ch);

        $onConfirmOutcome = ($onConfirmHttpCode >= 200 && $onConfirmHttpCode < 300)
            ? 'sent'
            : 'send_failed';

        error_log(
            'confirm: on-confirm outcome=' . $onConfirmOutcome .
            ' http_code=' . $onConfirmHttpCode .
            ' curl_error_present=' . ($onConfirmCurlError === '' ? 'NO' : 'YES') .
            ' response_bytes=' . strlen((string)$onConfirmResponse) .
            ' payload_bytes=' . strlen((string)json_encode($onConfirmPayload))
        );
    }
} catch (Throwable $e) {
    $onConfirmOutcome = 'exception';
    error_log('confirm: on-confirm threw | ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────
// Record the confirmation now that the true persisted count and
// the on-confirm outcome are both known.
// ─────────────────────────────────────────────────────────


/*
 * M2 P0 - SENSITIVE DATA REMOVAL FROM PERSISTENCE.
 *
 * This summary is written to abdm_user_linking_transactions.response_payload.
 * It previously carried `'receivedPayload' => $data`, i.e. the ENTIRE inbound
 * request body, which contains confirmation.token - the plaintext OTP. That
 * put a live one-time secret into a longtext column in the database. Phase 0
 * removed this class of leak from the LOG files but not from the DATABASE.
 *
 * Only an allow-listed, non-sensitive diagnostic summary is retained. No token,
 * no raw body, no ABHA address, no patient name or identifier.
 *
 * Note this UPDATE replaces response_payload wholesale. The workflow already
 * read selectedCareContexts / link.referenceNumber into $storedResponse and
 * $linkedReferences above, so that pre-existing behaviour is unchanged - but
 * the discovery details are no longer retained after confirmation.
 */

$confirmLog = [
    'transactionId'           => $transactionId,
    'patientId'               => $patientId,
    'linkRefNumber'           => $linkRefNumber,
    'tokenReceived'           => $token !== '',
    'originalOnInitRequestId' => $originalOnInitRequestId,
    // B-14: the persisted count, not the requested count.
    'linkedCareContextCount'  => count($persistedCareContexts),
    'linkedCareContextFailures' => count($careContextWriteFailures),
    // B-12: a retry can now be driven from this instead of the failure being
    // invisible. No token, no raw body, no patient identifier beyond the
    // numeric id already stored in this row.
    'onConfirmOutcome'        => $onConfirmOutcome,
    'confirmedAt'             => gmdate('Y-m-d\TH:i:s.v\Z')
];

$confirmLogJson = json_encode($confirmLog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$updateTransaction = $conn->prepare("
    UPDATE abdm_user_linking_transactions
    SET status = 'Confirmed', response_payload = ?
    WHERE id = ?
    LIMIT 1
");

if (!$updateTransaction) {
    error_log('confirm: transaction update prepare failed | ' . $conn->error);
    $conn->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to record confirmation.']);
    exit;
}

$updateTransaction->bind_param("si", $confirmLogJson, $transaction['id']);

if (!$updateTransaction->execute()) {
    error_log('confirm: transaction update execute failed | ' . $updateTransaction->error);
    $updateTransaction->close();
    $conn->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to record confirmation.']);
    exit;
}

$updateTransaction->close();

// ─────────────────────────────────────────────────────────
// RESPOND TO ABDM
// The contract is unchanged - HTTP 202 with the same JSON keys - but it is now
// emitted only after the care contexts are persisted and the on-confirm call
// has been attempted.
// ─────────────────────────────────────────────────────────
http_response_code(202);

echo json_encode([
    'success'            => true,
    'message'            => 'Care context confirmation received successfully.',
    'transactionId'      => $transactionId,
    'linkRefNumber'      => $linkRefNumber,
    'status'             => 'Confirmed',
    // B-14: the number of care contexts actually written.
    'linkedCareContexts' => count($persistedCareContexts)
]);

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

// Don't close $conn - PHP will clean up.
