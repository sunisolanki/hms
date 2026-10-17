<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse([
        'success' => false,
        'message' => 'Only GET method is allowed.'
    ], 405);
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    jsonResponse([
        'success' => false,
        'message' => 'Database connection is not available.'
    ], 500);
}

$hospitalId = isset($_GET['hospital_id'])
    ? (int)$_GET['hospital_id']
    : 0;

if ($hospitalId <= 0) {
    jsonResponse([
        'success' => false,
        'message' => 'Hospital ID is required.'
    ], 400);
}

$stmt = $conn->prepare("
    SELECT
        id,
        patient_id,
        consent_request_id,
        consent_id,
        care_context_reference,
        transaction_id,
        request_id,
        status,
        abdm_status_code,
        abdm_response,
        callback_response,
        data_response,
        created_at,
        updated_at
    FROM abdm_m3_health_information_requests
    WHERE hospital_id = ?
    ORDER BY id DESC
    LIMIT 10
");

if (!$stmt) {
    jsonResponse([
        'success' => false,
        'message' => 'Unable to prepare health information callback monitor query.',
        'error' => $conn->error
    ], 500);
}

$stmt->bind_param('i', $hospitalId);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();

    jsonResponse([
        'success' => false,
        'message' => 'Unable to execute health information callback monitor query.',
        'error' => $error
    ], 500);
}

$result = $stmt->get_result();

$requests = [];

while ($row = $result->fetch_assoc()) {
    $callback = null;

    if (!empty($row['callback_response'])) {
        $decodedCallback = json_decode(
            $row['callback_response'],
            true
        );

        if (is_array($decodedCallback)) {
            $callback = $decodedCallback;
        }
    }

    $transactionId = $row['transaction_id'];

    if (
        ($transactionId === '' || $transactionId === null)
        && is_array($callback)
    ) {
        $transactionId = $callback['hiRequest']['transactionId'] ?? null;
    }

    $sessionStatus = null;

    if (is_array($callback)) {
        $sessionStatus = $callback['hiRequest']['sessionStatus'] ?? null;
    }

    $callbackRequestId = null;

    if (is_array($callback)) {
        $callbackRequestId = $callback['response']['requestId'] ?? null;
    }

    $callbackError = null;

    if (is_array($callback)) {
        $callbackError = $callback['error'] ?? null;
    }

    $abdmResponse = null;

    if (!empty($row['abdm_response'])) {
        $decodedAbdmResponse = json_decode(
            $row['abdm_response'],
            true
        );

        $abdmResponse = is_array($decodedAbdmResponse)
            ? $decodedAbdmResponse
            : $row['abdm_response'];
    }

    $dataResponse = null;

    if (!empty($row['data_response'])) {
        $decodedDataResponse = json_decode(
            $row['data_response'],
            true
        );

        $dataResponse = is_array($decodedDataResponse)
            ? $decodedDataResponse
            : $row['data_response'];
    }

    $requests[] = [
        'local_request_id' => (int)$row['id'],
        'patient_id' => (int)$row['patient_id'],
        'consent_request_id' => $row['consent_request_id'],
        'consent_id' => $row['consent_id'],
        'care_context_reference' => $row['care_context_reference'],
        'transaction_id' => $transactionId,
        'request_id' => $row['request_id'],
        'callback_request_id' => $callbackRequestId,
        'session_status' => $sessionStatus,
        'callback_error' => $callbackError,
        'status' => $row['status'],
        'abdm_status_code' => $row['abdm_status_code'],
        'abdm_response' => $abdmResponse,
        'callback_response' => $callback,
        'data_response' => $dataResponse,
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at']
    ];
}

$stmt->close();

jsonResponse([
    'success' => true,
    'message' => $requests
        ? 'Health Information callback status loaded.'
        : 'No Health Information Request records found.',
    'data' => $requests
]);
?>