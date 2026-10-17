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

$invoice_id=isset($_GET['invoice_id'])?(int)$_GET['invoice_id']:0;

if($invoice_id<=0){
    echo json_encode([
        'status'=>'error',
        'message'=>'Invalid Invoice.'
    ]);
    exit;
}

$stmt=$conn->prepare("
SELECT
    si.id,
    si.subscription_id,
    si.invoice_no,
    si.invoice_date,
    si.billing_from,
    si.billing_to,
    si.billing_type,
    si.amount,
    si.gst_percent,
    si.gst_amount,
    si.discount,
    si.total_amount,
    si.paid_amount,
    si.balance_amount,
    si.payment_status,
    si.payment_date,
    si.payment_mode,
    si.transaction_no,
    si.remarks,
    ss.plan_name,
    hm.hospital_name
FROM software_invoices si
INNER JOIN software_subscriptions ss
    ON ss.id=si.subscription_id
INNER JOIN hospital_master hm
    ON hm.id=ss.hospital_id
WHERE si.id=?
LIMIT 1
");

$stmt->bind_param("i",$invoice_id);

$stmt->execute();

$result=$stmt->get_result();

if($result->num_rows==0){

    echo json_encode([
        'status'=>'error',
        'message'=>'Invoice not found.'
    ]);

    exit;
}

$row=$result->fetch_assoc();

$stmt->close();

echo json_encode([
    'status'=>'success',
    'data'=>$row
]);

exit;
?>