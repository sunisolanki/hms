<?php
session_start();

header('Content-Type: application/json');

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/payments/lib/get_active_payu_config.php';

try {

    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        throw new Exception('Invalid JSON input');
    }

    $amount  = (float)($data['amount'] ?? 0);
    $purpose = $data['purpose'] ?? 'GENERAL';

    if ($amount <= 0) {
        throw new Exception('Invalid amount');
    }

    $cfg = getActivePayU($conn);

    if (!$cfg) {
        throw new Exception('PayU not configured');
    }

    $txnid = 'PAYU_' . time() . '_' . random_int(1000, 9999);

    /*
     * Website appointment data
     */
    $appointmentData = $data['appointment'] ?? null;

    if ($purpose === 'APPOINTMENT') {

        if (!is_array($appointmentData) || empty($appointmentData)) {
            throw new Exception('Appointment data missing');
        }

        /*
         * Force Website source.
         */
        $appointmentData['source'] = 'WEBSITE';

        /*
         * Store complete appointment data in payments table.
         */
        $pendingAppointment = json_encode(
            $appointmentData,
            JSON_UNESCAPED_UNICODE
        );

        if ($pendingAppointment === false) {
            throw new Exception('Unable to prepare appointment data');
        }

    } else {

        $pendingAppointment = null;
    }

    /*
     * Save INITIATED payment
     */
    $stmt = $conn->prepare("
        INSERT INTO payments (
            gateway,
            mode,
            purpose,
            amount,
            txnid,
            status,
            pending_appointment,
            created_at
        )
        VALUES (
            'PAYU',
            ?,
            ?,
            ?,
            ?,
            'INITIATED',
            ?,
            NOW()
        )
    ");

    if (!$stmt) {
        throw new Exception(
            'Payment prepare failed: ' . $conn->error
        );
    }

    $stmt->bind_param(
        "ssdss",
        $cfg['mode'],
        $purpose,
        $amount,
        $txnid,
        $pendingAppointment
    );

    if (!$stmt->execute()) {
        throw new Exception(
            'DB insert failed: ' . $stmt->error
        );
    }

    $stmt->close();

    /*
     * Keep txnid in session only as an additional reference.
     * Appointment data itself is now in the database.
     */
    $_SESSION['PAYU_TXNID'] = $txnid;

    echo json_encode([
        'status'   => 'OK',
        'redirect' => '/payments/payu_redirect.php'
    ]);

    exit;

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'status'  => 'ERROR',
        'message' => $e->getMessage()
    ]);

    exit;
}