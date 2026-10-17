<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
require $_SERVER['DOCUMENT_ROOT'].'/communication/send_mail.php';

/* PERMISSION CHECK */
if (!checkPermission('settings','view')) {
    header('Location: /access_denied.php');
    exit;
}

/* LOAD EXISTING SETTINGS */
$res  = mysqli_query($conn, "SELECT * FROM email_settings LIMIT 1");
$data = mysqli_fetch_assoc($res);

$statusMsg = '';
$statusCls = '';

/* SAVE + TEST MAIL */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $sql = "REPLACE INTO email_settings 
    (id, mail_driver, smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, from_email, from_name)
    VALUES (1,?,?,?,?,?,?,?,?)";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param(
        $stmt,
        "ssisssss",
        $_POST['mail_driver'],
        $_POST['smtp_host'],
        $_POST['smtp_port'],
        $_POST['smtp_username'],
        $_POST['smtp_password'],
        $_POST['smtp_encryption'],
        $_POST['from_email'],
        $_POST['from_name']
    );

    mysqli_stmt_execute($stmt);

    /* SEND AUTO TEST MAIL */
    $sent = sendSMTPMail(
        $_POST['from_email'],
        'Welcome to WebSplend!  ',
        '<p>We’re glad to have you on board. Your Email account has been set up successfully, and you can now start using the system.<br><br>
        Regards,<br>
        Team WebSplend</p>'
    );

    if ($sent) {
        $statusMsg = 'Settings saved and test email sent successfully.';
        $statusCls = 'success';
    } else {
        $statusMsg = 'Settings saved, but test email failed. Please check SMTP details.';
        $statusCls = 'error';
    }

    /* RELOAD SAVED DATA */
    $res  = mysqli_query($conn, "SELECT * FROM email_settings LIMIT 1");
    $data = mysqli_fetch_assoc($res);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Email Configuration - WebSplend HMS</title>

    <!-- MAIN CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">

    <!-- FAVICON -->
    <link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
    <link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<style>
body {
    font-family: Arial, sans-serif;
    background:#f4f6f9;
}

.container {
    max-width: 900px;
    margin: 30px auto;
    background: #fff;
    padding: 25px;
    border-radius: 6px;
}

h2 {
    margin-bottom: 20px;
}

.form-row {
    display: flex;
    gap: 20px;
}

.form-group {
    flex: 1;
    display: flex;
    flex-direction: column;
    margin-bottom: 15px;
}

.form-group.small {
    max-width: 160px;
}

label {
    font-weight: 600;
    margin-bottom: 6px;
}

input, select {
    height: 40px;
    padding: 8px 10px;
    font-size: 14px;
}

button {
    background: #0066cc;
    color: #fff;
    border: none;
    padding: 10px 22px;
    cursor: pointer;
    border-radius: 4px;
}

.alert {
    padding: 10px 15px;
    margin-bottom: 15px;
    border-radius: 4px;
}

.alert.success {
    background: #e6f7ea;
    color: #1e7e34;
}

.alert.error {
    background: #fdecea;
    color: #a71d2a;
}
</style>
</head>

<body>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>

<div class="container settings-wrapper">
<div class="container">
    <h2>Email Configuration</h2>

    <?php if ($statusMsg): ?>
        <div class="alert <?= $statusCls ?>">
            <?= $statusMsg ?>
        </div>
    <?php endif; ?>

    <form method="post">

        <div class="form-group">
            <label>Mail Driver</label>
            <select name="mail_driver">
                <option value="smtp" <?=($data['mail_driver']=='smtp')?'selected':''?>>SMTP</option>
                <option value="phpmail" <?=($data['mail_driver']=='phpmail')?'selected':''?>>PHP Mail</option>
            </select>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>SMTP Host</label>
                <input type="text" name="smtp_host"
                       value="<?= htmlspecialchars($data['smtp_host'] ?? '') ?>">
            </div>

            <div class="form-group small">
                <label>SMTP Port</label>
                <input type="number" name="smtp_port"
                       value="<?= htmlspecialchars($data['smtp_port'] ?? '') ?>">
            </div>

            <div class="form-group small">
                <label>Encryption</label>
                <select name="smtp_encryption">
                    <option value="tls" <?=($data['smtp_encryption']=='tls')?'selected':''?>>TLS</option>
                    <option value="ssl" <?=($data['smtp_encryption']=='ssl')?'selected':''?>>SSL</option>
                    <option value="none" <?=($data['smtp_encryption']=='none')?'selected':''?>>None</option>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>SMTP Username</label>
                <input type="text" name="smtp_username"
                       value="<?= htmlspecialchars($data['smtp_username'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>SMTP Password</label>
                <input type="password" name="smtp_password"
                       value="<?= htmlspecialchars($data['smtp_password'] ?? '') ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>From Email</label>
                <input type="email" name="from_email"
                       value="<?= htmlspecialchars($data['from_email'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>From Name</label>
                <input type="text" name="from_name"
                       value="<?= htmlspecialchars($data['from_name'] ?? '') ?>">
            </div>
        </div>

        <button type="submit">Save & Test Email</button>

<a href="/communication/test_email.php" class="btn"> 📧 Test Email
</a>


    </form>
</div>

</body>
</html>
