<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if (!checkPermission('settings','view')) {
    header('Location: /access_denied.php');
    exit;
}

$res  = mysqli_query($conn, "SELECT * FROM sms_settings LIMIT 1");
$data = mysqli_fetch_assoc($res) ?: [
    'provider' => '',
    'api_url' => '',
    'api_key' => '',
    'sender_id' => '',
    'route' => ''
];

$msg = ''; $cls = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $sql = "REPLACE INTO sms_settings
    (id, provider, api_url, api_key, sender_id, route, is_active)
    VALUES (1,?,?,?,?,?,?)";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param(
        $stmt,
        "ssssssi",
        $_POST['provider'],
        $_POST['api_url'],
        $_POST['api_key'],
        $_POST['sender_id'],
        $_POST['route'],
        isset($_POST['is_active']) ? 1 : 0
    );
    mysqli_stmt_execute($stmt);

    $msg = 'SMS configuration saved successfully.';
    $cls = 'success';
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
<h2>SMS Configuration</h2>

<?php if ($msg): ?>
<div class="alert <?= $cls ?>"><?= $msg ?></div>
<?php endif; ?>

<form method="post">
    <label>Provider</label>
    <input name="provider" value="<?= $data['provider'] ?>">

    <label>API URL</label>
    <input name="api_url" value="<?= $data['api_url'] ?>">

    <label>API Key</label>
    <input name="api_key" value="<?= $data['api_key'] ?>">

    <label>Sender ID</label>
    <input name="sender_id" value="<?= $data['sender_id'] ?>">

    <label>Route / Template</label>
    <input name="route" value="<?= $data['route'] ?>">

    <label>Active</label>
    <input type="hidden" name="is_active" value="0">
    <input type="checkbox" name="is_active" value="1" <?= !empty($data['is_active']) ? 'checked' : '' ?>>

    <button>Save SMS Settings</button>
</form>
