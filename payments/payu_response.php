<?php
/****************************************************
 * PAYU PAYMENT RESPONSE HANDLER
 * WEBSITE APPOINTMENT FLOW
 ****************************************************/

session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/payments/lib/get_active_payu_config.php';

/* =========================
   LOAD ACTIVE PAYU CONFIG
========================= */

$cfg = getActivePayU($conn);

if (!$cfg) {
    die('PayU configuration not found');
}

/* =========================
   COLLECT PAYU RESPONSE
========================= */

$status      = $_POST['status'] ?? '';
$txnid       = $_POST['txnid'] ?? '';
$payu_id     = $_POST['mihpayid'] ?? '';
$amount      = $_POST['amount'] ?? '';
$hash        = $_POST['hash'] ?? '';
$email       = $_POST['email'] ?? '';
$firstname   = $_POST['firstname'] ?? '';
$productinfo = $_POST['productinfo'] ?? '';

/* =========================
   BASIC SAFETY
========================= */

if (!$status || !$txnid || !$hash) {
    die('Invalid PayU response');
}

/* =========================
   HASH VERIFICATION
========================= */

$hash_string =
    $cfg['merchant_secret'].'|' .
    $status.'|||||||||||' .
    $email.'|' .
    $firstname.'|' .
    $productinfo.'|' .
    $amount.'|' .
    $txnid.'|' .
    $cfg['merchant_key'];

$verify_hash = strtolower(hash('sha512', $hash_string));

if ($verify_hash !== strtolower($hash)) {
    die('Hash verification failed');
}

/* =========================
   RAW RESPONSE
========================= */

$raw_response = json_encode($_POST);

/* =========================
   PAYMENT SUCCESS
========================= */

if ($status === 'success') {

    /* =========================
       FIND PAYMENT RECORD
    ========================= */

    $stmt = $conn->prepare("
        SELECT
            id,
            amount,
            purpose,
            status,
            pending_appointment
        FROM payments
        WHERE txnid = ?
        LIMIT 1
    ");

    if (!$stmt) {
        die('Payment lookup failed: ' . $conn->error);
    }

    $stmt->bind_param("s", $txnid);
    $stmt->execute();

    $stmt->bind_result(
        $payment_id,
        $payment_amount,
        $payment_purpose,
        $payment_status,
        $pending_appointment
    );

    if (!$stmt->fetch()) {
        $stmt->close();
        die('Payment record not found');
    }

    $stmt->close();

    /* =========================
       PREVENT DUPLICATE PAYMENT
    ========================= */

    if ($payment_status === 'CONFIRMED') {

        die(
            'This payment has already been processed. ' .
            'Transaction ID: ' . htmlspecialchars($txnid)
        );
    }

    /* =========================
       UPDATE PAYMENT
    ========================= */

    $stmt = $conn->prepare("
        UPDATE payments
        SET status = 'CONFIRMED',
            payu_txn_id = ?,
            raw_response = ?,
            confirmed_at = NOW()
        WHERE txnid = ?
        LIMIT 1
    ");

    if (!$stmt) {
        die('Payment update failed: ' . $conn->error);
    }

    $stmt->bind_param(
        "sss",
        $payu_id,
        $raw_response,
        $txnid
    );

    if (!$stmt->execute()) {
        $stmt->close();
        die('Payment update failed: ' . $stmt->error);
    }

    $stmt->close();

    /* =========================
       WEBSITE APPOINTMENT
    ========================= */

    if ($payment_purpose === 'APPOINTMENT') {

        if (
            empty($pending_appointment) ||
            !is_string($pending_appointment)
        ) {
            die(
                'Payment successful, but pending appointment ' .
                'data was not found.'
            );
        }

        /*
         * Convert stored JSON back into appointment data.
         */
        $appointmentData = json_decode(
            $pending_appointment,
            true
        );

        if (
            !is_array($appointmentData) ||
            empty($appointmentData)
        ) {
            die(
                'Payment successful, but appointment data ' .
                'could not be read.'
            );
        }

        /*
         * Force Website booking.
         */
        $appointmentData['source'] = 'WEBSITE';

        /*
         * Restore appointment data into POST because
         * save_appointment.php reads from $_POST.
         */
        $_POST = $appointmentData;

        /*
         * Confirm payment for save_appointment.php.
         */
        $_SESSION['PAYMENT_CONFIRMED'] = [
            'txnid'   => $txnid,
            'payu_id' => $payu_id,
            'amount'  => $amount
        ];

        /*
         * Save appointment.
         *
         * save_appointment.php will:
         * - validate the appointment
         * - check the slot
         * - insert appointments record
         * - generate appointment number
         * - generate token
         * - save payment transaction
         * - redirect Website user to Thank You page
         */
        require $_SERVER['DOCUMENT_ROOT'] .
            '/modules/appointments/save_appointment.php';

        exit;
    }

    /* =========================
       GENERAL PAYMENT SUCCESS
    ========================= */

    unset($_SESSION['PAYMENT_CONFIRMED']);

    echo "
        <script>
            alert('Payment Successful');
            window.location.href = '/';
        </script>
    ";

    exit;
}

/* =========================
   PAYMENT FAILED
========================= */

$raw_response = json_encode($_POST);

$stmt = $conn->prepare("
    UPDATE payments
    SET status = 'FAILED',
        raw_response = ?
    WHERE txnid = ?
    LIMIT 1
");

if ($stmt) {

    $stmt->bind_param(
        "ss",
        $raw_response,
        $txnid
    );

    $stmt->execute();
    $stmt->close();
}

/*
 * Do not delete pending_appointment.
 * The patient may retry payment using the same flow.
 */

unset($_SESSION['PAYMENT_CONFIRMED']);

echo "
    <script>
        alert('Payment Failed or Cancelled');
        window.history.back();
    </script>
";

exit;