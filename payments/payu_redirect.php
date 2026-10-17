<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/payments/lib/get_active_payu_config.php';

$cfg = getActivePayU($conn);
if (!$cfg) {
    die('PayU not configured');
}

$txnid = $_SESSION['PAYU_TXNID'] ?? '';
if (!$txnid) {
    die('Invalid transaction');
}

/* Fetch payment */
$stmt = $conn->prepare("SELECT amount FROM payments WHERE txnid=? LIMIT 1");
$stmt->bind_param("s", $txnid);
$stmt->execute();
$stmt->bind_result($amount);
$stmt->fetch();
$stmt->close();

if (!$amount) {
    die('Payment not found');
}

/* Dummy customer data (PayU mandatory fields) */
$firstname = 'Patient';
$email     = 'patient@example.com';
$productinfo = 'Appointment Booking';

/* 🔐 CORRECT HASH */
$hash_string =
    $cfg['merchant_key'].'|'.
    $txnid.'|'.
    $amount.'|'.
    $productinfo.'|'.
    $firstname.'|'.
    $email.'|||||||||||'.
    $cfg['merchant_secret'];

$hash = strtolower(hash('sha512', $hash_string));

$action = ($cfg['mode'] === 'LIVE')
    ? 'https://secure.payu.in/_payment'
    : 'https://test.payu.in/_payment';
?>

<form method="post" action="<?= $action ?>" name="payuForm">
    <input type="hidden" name="key" value="<?= $cfg['merchant_key'] ?>">
    <input type="hidden" name="txnid" value="<?= $txnid ?>">
    <input type="hidden" name="amount" value="<?= $amount ?>">
    <input type="hidden" name="productinfo" value="<?= $productinfo ?>">
    <input type="hidden" name="firstname" value="<?= $firstname ?>">
    <input type="hidden" name="email" value="<?= $email ?>">

    <input type="hidden" name="phone" value="9999999999">
    <input type="hidden" name="surl" value="https://<?= $_SERVER['HTTP_HOST'] ?>/payments/payu_response.php">
    <input type="hidden" name="furl" value="https://<?= $_SERVER['HTTP_HOST'] ?>/payments/payu_response.php">

    <input type="hidden" name="hash" value="<?= $hash ?>">
</form>

<script>
document.payuForm.submit();
</script>
