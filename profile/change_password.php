<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if(!isset($_SESSION['user_id'])){
    header("Location:/index.php");
    exit;
}
$user_id = $_SESSION['user_id'];
$current_password = $_POST['current_password'];
$new_password     = $_POST['new_password'];
$confirm_password = $_POST['confirm_password'];
/* FETCH USER */
$stmt = $conn->prepare("
    SELECT password
    FROM users
    WHERE id=?
");
$stmt->bind_param("i",$user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
/* VERIFY */
if(!password_verify($current_password,$user['password'])){
    die("Current password is incorrect.");
}
/* MATCH */
if($new_password !== $confirm_password){
    die("New password and confirm password do not match.");
}
/* HASH */
$new_hash = password_hash($new_password,PASSWORD_DEFAULT);
/* UPDATE */
$stmt = $conn->prepare("
    UPDATE users
    SET password=?
    WHERE id=?
");
$stmt->bind_param("si",$new_hash,$user_id);
$stmt->execute();
/* REDIRECT */
header("Location: profile.php");
exit;
?>