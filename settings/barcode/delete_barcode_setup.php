<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}
if (!checkPermission('barcode_setup','delete')) {
    header('Location: /access_denied.php');
    exit;
}
$id = (int)($_GET['id'] ?? 0);
if($id > 0){
    mysqli_query(
        $conn,
        "DELETE FROM barcode_settings WHERE id='$id' LIMIT 1"
    );
}
header('Location: barcode_setup.php');
exit;
?>