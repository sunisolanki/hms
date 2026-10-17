<?php
header('Content-Type: application/json');

$rawBody = file_get_contents('php://input');

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
$status = trim($data['status'] ?? '');
$requestId = trim($data['response']['requestId'] ?? '');

if ($abhaAddress === '' || $requestId === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'abhaAddress and response.requestId are required.'
    ]);
    exit;
}

/*
 * ABDM contract note: the payload shape (abhaAddress, status and
 * response.requestId) and the HTTP 202 acknowledgement are unchanged. Only
 * the database work between them is tightened.
 *
 * Defect 1 - status validation. Any status that was not the exact success
 * string fell into an `else` branch that marked EVERY Pending care context for
 * the patient as 'Failed'. An empty status, a typo, or a status belonging to a
 * different workflow therefore damaged unrelated pending records. The empty
 * case is refused here, before any lookup or write; a non-empty unrecognised
 * status is still recorded as Failed, which is the existing observable
 * behaviour and is deliberately not narrowed further without the ABDM status
 * enumeration.
 *
 * Defect 2 - no correlation. $requestId was read, echoed back, and otherwise
 * ignored: the update was scoped by patient only. Any callback naming a known
 * abhaAddress could therefore flip a care context belonging to a different
 * linking transaction. The update is now scoped to the patient AND to the care
 * contexts recorded against the matching linking transaction.
 *
 * Defect 3 - not idempotent. Re-running a success callback re-stamped
 * linked_at. Rows already in the target state are now left untouched, and a
 * terminal row is never demoted to Failed by a later failure callback.
 */

$successStatus = 'Successfully Linked care context';

if (trim($data['status'] ?? '') === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'status is required.'
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

$controlConn = new mysqli(
    $controlCredentials['host'],
    $controlCredentials['user'],
    $controlCredentials['pass'],
    $controlCredentials['db']
);

if ($controlConn->connect_error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Control database connection failed.'
    ]);
    exit;
}

$controlConn->set_charset('utf8mb4');

$stmt = $controlConn->prepare("
    SELECT
        database_host,
        database_name,
        database_user,
        database_password
    FROM projects
    WHERE database_name = 'u336643111_hms'
    LIMIT 1
");

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

$conn = new mysqli(
    $tenantCredentials['host'],
    $tenantCredentials['user'],
    $tenantCredentials['pass'],
    $tenantCredentials['db']
);

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

$stmt = $conn->prepare("
    SELECT id
    FROM abdm_patient_links
    WHERE abha_address = ?
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

$stmt->bind_param("s", $abhaAddress);
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

/*
 * ABDM contract note: the payload shape (abhaAddress, status and
 * response.requestId) and the HTTP 202 acknowledgement are unchanged.
 * Only the database work between them is tightened. See the three defect
 * notes recorded immediately after the payload validation above.
 */

$linkReferenceNumber = trim($data['link']['referenceNumber'] ?? '');
$careContextReference = trim($data['careContexts'][0]['referenceNumber'] ?? '');

if ($linkReferenceNumber === '') {
    $linkReferenceNumber = $careContextReference;
}

$isSuccessStatus = ($status === $successStatus);
$newLinkStatus = $isSuccessStatus ? 'Linked' : 'Failed';

/*
 * Resolve the linking transaction that owns this callback. The stored
 * response_payload carries the ABDM link reference and the selected care
 * contexts; correlation uses that instead of patient-only matching.
 */
$transactionStmt = $conn->prepare("
    SELECT
        t.id,
        t.transaction_id,
        t.patient_id,
        t.status,
        t.response_payload
    FROM abdm_user_linking_transactions t
    INNER JOIN abdm_patient_links l
        ON l.patient_id = t.patient_id
    WHERE l.abha_address = ?
    ORDER BY t.id DESC
");

if (!$transactionStmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to prepare linking transaction lookup.'
    ]);
    exit;
}

$transactionStmt->bind_param('s', $abhaAddress);
$transactionStmt->execute();

$transactionCandidates = $transactionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$transactionStmt->close();

$callbackReference = $linkReferenceNumber !== '' ? $linkReferenceNumber : $requestId;

$matchedTransaction = null;
$matchedReferences = [];

foreach ($transactionCandidates as $candidate) {
    if ((int)$candidate['patient_id'] !== (int)$patientLink['patient_id']) {
        continue;
    }

    if ($callbackReference === '') {
        continue;
    }

    $candidateResponse = json_decode((string)$candidate['response_payload'], true);

    if (!is_array($candidateResponse)) {
        continue;
    }

    $candidateLinkReference = trim((string)($candidateResponse['link']['referenceNumber'] ?? ''));

    if (
        $candidateLinkReference === ''
        || !hash_equals($candidateLinkReference, $callbackReference)
    ) {
        continue;
    }

    $candidateReferences = [];

    foreach (($candidateResponse['selectedCareContexts'] ?? []) as $careContext) {
        if (!is_array($careContext)) {
            continue;
        }

        $referenceNumber = trim((string)($careContext['referenceNumber'] ?? ''));

        if ($referenceNumber !== '') {
            $candidateReferences[] = $referenceNumber;
        }
    }

    if ($careContextReference !== '' && !in_array($careContextReference, $candidateReferences, true)) {
        continue;
    }

    $matchedTransaction = $candidate;
    $matchedReferences = $candidateReferences;
    break;
}

if (!$matchedTransaction) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'No matching linking transaction found for this callback.'
    ]);
    exit;
}

$patientId = (int)$matchedTransaction['patient_id'];

if (empty($matchedReferences)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'No care contexts found for this linking transaction.'
    ]);
    exit;
}

$appliedReferences = [];
$alreadyAppliedReferences = [];
$writeFailures = [];

$conn->begin_transaction();

foreach ($matchedReferences as $referenceNumber) {
    $contextStmt = $conn->prepare("
        SELECT id, link_status
        FROM abdm_care_contexts
        WHERE patient_id = ?
          AND care_context_reference = ?
        LIMIT 1
    ");

    if (!$contextStmt) {
        $writeFailures[] = $referenceNumber;
        error_log('on_carecontext: care-context select prepare failed | ' . $conn->error);
        continue;
    }

    $contextStmt->bind_param('is', $patientId, $referenceNumber);

    $selectFailed = false;

    try {
        if (!$contextStmt->execute()) {
            $selectFailed = true;
        }
    } catch (Throwable $e) {
        $selectFailed = true;
        error_log('on_carecontext: care-context select threw | ' . $e->getMessage());
    }

    if ($selectFailed) {
        $contextStmt->close();
        $writeFailures[] = $referenceNumber;
        continue;
    }

    $existingContext = $contextStmt->get_result()->fetch_assoc();
    $contextStmt->close();

    if (!$existingContext) {
        $writeFailures[] = $referenceNumber;
        continue;
    }

    /*
     * Idempotency. A row already in the target state is not rewritten, so a
     * duplicate callback cannot move linked_at. A terminal row is never
     * demoted to Failed by a later failure callback.
     */
    if ((string)$existingContext['link_status'] === $newLinkStatus) {
        $alreadyAppliedReferences[] = $referenceNumber;
        continue;
    }

    if ($existingContext['link_status'] === 'Linked' || $existingContext['link_status'] === 'Failed') {
        $alreadyAppliedReferences[] = $referenceNumber;
        continue;
    }

    $updateStmt = $conn->prepare("
        UPDATE abdm_care_contexts
        SET
            link_status = ?,
            linked_at = CASE WHEN ? = 'Linked' THEN CURRENT_TIMESTAMP ELSE linked_at END,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND link_status = 'Pending'
        LIMIT 1
    ");

    if (!$updateStmt) {
        $writeFailures[] = $referenceNumber;
        error_log('on_carecontext: care-context update prepare failed | ' . $conn->error);
        continue;
    }

    $updateStmt->bind_param('ssi', $newLinkStatus, $newLinkStatus, (int)$existingContext['id']);

    $updateFailed = false;

    try {
        if (!$updateStmt->execute()) {
            $updateFailed = true;
        }
    } catch (Throwable $e) {
        $updateFailed = true;
        error_log('on_carecontext: care-context update threw | ' . $e->getMessage());
    }

    if ($updateFailed || $updateStmt->affected_rows !== 1) {
        $writeFailures[] = $referenceNumber;
        $updateStmt->close();
        continue;
    }

    $updateStmt->close();
    $appliedReferences[] = $referenceNumber;
}

if (!empty($writeFailures)) {
    $conn->rollback();
    $conn->close();

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Care context callback could not be applied.'
    ]);
    exit;
}

if (!$conn->commit()) {
    $conn->rollback();
    $conn->close();

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Care context callback could not be committed.'
    ]);
    exit;
}

$conn->close();

http_response_code(202);

echo json_encode([
    'success' => true,
    'message' => 'Care context callback received successfully.',
    'requestId' => $requestId,
    'status' => $status,
    'linkStatus' => $newLinkStatus,
    'appliedCount' => count($appliedReferences),
    'alreadyAppliedCount' => count($alreadyAppliedReferences)
]);
?>