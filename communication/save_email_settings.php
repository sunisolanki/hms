<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

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

header("Location: email_settings.php?success=1");
