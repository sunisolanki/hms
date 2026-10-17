<?php
session_start();

header('Content-Type: application/json');

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

if(!isset($_SESSION['user_id'])){
    echo json_encode([
        'status'=>'error',
        'message'=>'Session expired.'
    ]);
    exit;
}

$amount=isset($_POST['amount'])?(float)$_POST['amount']:0;

$gst_percent=isset($_POST['gst_percent'])?(float)$_POST['gst_percent']:0;

$discount=isset($_POST['discount'])?(float)$_POST['discount']:0;

if($amount<0){
    $amount=0;
}

if($gst_percent<0){
    $gst_percent=0;
}

if($discount<0){
    $discount=0;
}

$gst_amount=round(($amount*$gst_percent)/100,2);

$total_amount=round(($amount+$gst_amount)-$discount,2);

if($total_amount<0){
    $total_amount=0;
}

echo json_encode([
    'status'=>'success',
    'amount'=>number_format($amount,2,'.',''),
    'gst_percent'=>number_format($gst_percent,2,'.',''),
    'gst_amount'=>number_format($gst_amount,2,'.',''),
    'discount'=>number_format($discount,2,'.',''),
    'total_amount'=>number_format($total_amount,2,'.','')
]);

exit;
?>