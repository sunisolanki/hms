<?php
session_start();
include("../../includes/db.php");
if(!isset($_SESSION['user_id'])){

    exit;
}
if(isset($_POST['ticket_id']) &&
   isset($_POST['status'])){

    $ticket_id = intval($_POST['ticket_id']);

    $status = mysqli_real_escape_string(
        $conn,
        $_POST['status']
    );
    $allowed = [
        'Open',
        'Ongoing',
        'Reject',
        'Closed'
    ];
    if(in_array($status, $allowed)){
        mysqli_query($conn, "
            UPDATE tickets
            SET status = '$status'
            WHERE id = '$ticket_id'
        ");
        echo "success";
    }
}
?>