<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$appointment_id = (int)$_POST['appointment_id'];
$new_status = $_POST['status'];

/* Get current status */
$q = mysqli_query($conn, "SELECT status FROM appointments WHERE id = $appointment_id");
$row = mysqli_fetch_assoc($q);
$current = $row['status'];

/* Allowed rules */
$rules = [
    'Payment Pending' => ['Confirmed', 'Cancel'],
    'Partial Paid' => [
        'Confirmed',
        'Refund',
        'Cancel'
    ],
    'Confirmed' => [
        'Refund',
        'Registered',
        'Cancel'
    ],
    'Registered' => [
    'Prescription',
    'Admit Advice',
    'Done',
    'Refund',
    'Cancel'
],

'Prescription' => [
    'Done',
    'Refund',
    'Cancel'
],

'Admit Advice' => [
    'Done',
    'Refund',
    'Cancel'
],

'Refund' => [
    'Prescription',
    'Admit Advice',
    'Done',
    'Cancel'
],

'Admitted' => []
];

if (!isset($rules[$current]) || !in_array($new_status, $rules[$current])) {
    die('Status change not allowed');
}

/* Update status */
mysqli_query($conn, "
    UPDATE appointments 
    SET status = '$new_status'
    WHERE id = $appointment_id
");


/* Go back */
$return_to = $_POST['return_to'] ?? 'appointment';

if ($return_to == 'opd') {
    header("Location: /modules/opd/opd_list.php");
} else {
    header("Location: /modules/appointments/appointment_list.php");
}
exit;
