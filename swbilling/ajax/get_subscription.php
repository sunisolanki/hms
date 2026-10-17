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

$subscription_id=isset($_GET['subscription_id'])?(int)$_GET['subscription_id']:0;

if($subscription_id<=0){
    echo json_encode([
        'status'=>'error',
        'message'=>'Invalid Subscription.'
    ]);
    exit;
}

$stmt=$conn->prepare("
SELECT
    ss.id,
    ss.hospital_id,
    hm.hospital_name,
    ss.plan_name,
    ss.billing_type,
    ss.start_date,
    ss.end_date,
    ss.renewal_date,
    ss.is_auto_renew,
    ss.grace_days,
    ss.amount,
    ss.gst_percent,
    ss.gst_amount,
    ss.discount,
    ss.total_amount,
    ss.status,
    ss.remarks
FROM software_subscriptions ss
INNER JOIN hospital_master hm
    ON hm.id=ss.hospital_id
WHERE ss.id=?
LIMIT 1
");

$stmt->bind_param("i",$subscription_id);

$stmt->execute();

$result=$stmt->get_result();

if($result->num_rows==0){

    echo json_encode([
        'status'=>'error',
        'message'=>'Subscription not found.'
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