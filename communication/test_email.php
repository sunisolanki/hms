<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/communication/send_mail.php';

$message = '';
$status  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = trim($_POST['test_email']);

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $status = 'error';
        $message = 'Invalid email address';
    } else {
        $sent = sendSMTPMail(
            $to,
            'Test Email – Configuration Check',
            '<p>This is a test email sent using the configured email settings.</p>'
        );

        if ($sent) {
            $status = 'success';
            $message = 'Test email sent successfully.';
        } else {
            $status = 'error';
            $message = 'Email failed. Please check SMTP configuration.';
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Test Email Configuration</title>
    <style>
        body { font-family: Arial; background:#f6f6f6; }
        .box {
            width: 420px;
            margin: 60px auto;
            background: #fff;
            padding: 25px;
            border-radius: 6px;
            box-shadow: 0 0 10px rgba(0,0,0,.1);
        }
        label { font-weight: bold; }
        input {
            width: 100%;
            padding: 10px;
            margin: 8px 0 15px;
        }
        button {
            padding: 10px 15px;
            background: #007bff;
            color: #fff;
            border: none;
            cursor: pointer;
        }
        .success { color: green; margin-bottom: 10px; }
        .error { color: red; margin-bottom: 10px; }
    </style>
</head>
<body>

<div class="box">
    <h3>Test Email</h3>

    <?php if ($message): ?>
        <div class="<?= $status ?>"><?= $message ?></div>
    <?php endif; ?>

    <form method="post">
        <label>Send Test Email To</label>
        <input type="email" name="test_email" placeholder="example@email.com" required>

        <button type="submit">Send Test Email</button>
    </form>
</div>

</body>
</html>
