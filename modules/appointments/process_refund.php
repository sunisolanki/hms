<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$appointment_id = (int)$_POST['appointment_id'];
$refund_amount  = (float)$_POST['refund_amount'];
$refund_mode    = $_POST['refund_mode'];
$refund_by      = $_SESSION['user_id'];
$status         = $_POST['status'] ?? 'Refund';

mysqli_query($conn, "
    UPDATE appointments SET
        status = '$status',
        refund_amount = $refund_amount,
        refund_mode = '$refund_mode',
        refund_by = $refund_by,
        refund_datetime = NOW()
    WHERE id = $appointment_id
");

$return_to = $_POST['return_to'] ?? '';

if ($return_to == 'opd') {
    header("Location: /modules/opd/opd_list.php");
} else {
    header("Location: /modules/appointments/appointment_list.php");
}
exit;