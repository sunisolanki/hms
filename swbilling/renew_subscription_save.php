<?php
session_start();

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
require_once __DIR__.'/includes/init.php';

if(!isset($_SESSION['user_id'])){
    header("Location:/index.php");
    exit;
}

if(!checkPermission('swbilling','edit')){
    header("Location:/access_denied.php");
    exit;
}

if($_SERVER['REQUEST_METHOD']!="POST"){
    header("Location:subscriptions.php");
    exit;
}

$user_id=(int)$_SESSION['user_id'];

$subscription_id=(int)($_POST['subscription_id'] ?? 0);

$start_date=trim($_POST['start_date'] ?? '');

$end_date=trim($_POST['end_date'] ?? '');

$renewal_date=trim($_POST['renewal_date'] ?? '');

$amount=(float)($_POST['amount'] ?? 0);

$gst_percent=(float)($_POST['gst_percent'] ?? 0);

$discount=(float)($_POST['discount'] ?? 0);

$total_amount=(float)($_POST['total_amount'] ?? 0);

$remarks=trim($_POST['remarks'] ?? '');

if($subscription_id<=0){
    die("Invalid Subscription.");
}

if($start_date==""){
    die("Start Date is required.");
}

if($end_date==""){
    die("End Date is required.");
}

if($renewal_date==""){
    die("Renewal Date is required.");
}

if($amount<=0){
    die("Invalid Amount.");
}

$conn->begin_transaction();

try{

$stmt=$conn->prepare("
SELECT
*
FROM software_subscriptions
WHERE id=?
LIMIT 1
");

$stmt->bind_param("i",$subscription_id);

$stmt->execute();

$result=$stmt->get_result();

if($result->num_rows==0){
    throw new Exception("Subscription not found.");
}

$subscription=$result->fetch_assoc();

$stmt->close();

$gst_amount=($amount*$gst_percent)/100;

$total_amount=$amount+$gst_amount-$discount;
$stmt=$conn->prepare("
UPDATE software_subscriptions
SET
start_date=?,
end_date=?,
renewal_date=?,
amount=?,
gst_percent=?,
gst_amount=?,
discount=?,
total_amount=?,
status='Active',
remarks=?,
updated_at=NOW()
WHERE id=?
");

$stmt->bind_param(
"sssddddssi",
$start_date,
$end_date,
$renewal_date,
$amount,
$gst_percent,
$gst_amount,
$discount,
$total_amount,
$remarks,
$subscription_id
);

if(!$stmt->execute()){
    throw new Exception($stmt->error);
}

$stmt->close();

/* Generate Next Invoice Number */

$result=$conn->query("
SELECT MAX(id) AS last_id
FROM software_invoices
");

$row=$result->fetch_assoc();

$nextId=((int)$row['last_id'])+1;

$invoice_no="INV".str_pad($nextId,6,"0",STR_PAD_LEFT);

/* Insert Invoice */

$stmt=$conn->prepare("
INSERT INTO software_invoices
(
subscription_id,
invoice_no,
invoice_date,
billing_from,
billing_to,
billing_type,
amount,
gst_percent,
gst_amount,
discount,
total_amount,
paid_amount,
balance_amount,
payment_status,
created_by,
created_at
)
VALUES
(
?,
?,
CURDATE(),
?,
?,
?,
?,
?,
?,
?,
?,
0,
?,
'Pending',
?,
NOW()
)
");

$balance_amount=$total_amount;

$stmt->bind_param(
"issssdddddddi",
$subscription_id,
$invoice_no,
$start_date,
$end_date,
$subscription['billing_type'],
$amount,
$gst_percent,
$gst_amount,
$discount,
$total_amount,
$balance_amount,
$user_id
);

if(!$stmt->execute()){
    throw new Exception($stmt->error);
}

$invoice_id=$stmt->insert_id;

$stmt->close();
addAuditLog(
$conn,
$user_id,
'Software Billing',
'Renew Subscription',
'Subscription ID : '.$subscription_id.' | Invoice : '.$invoice_no
);

$conn->commit();

$_SESSION['success_message']="Subscription renewed successfully. Invoice ".$invoice_no." generated.";

header("Location:invoice_view.php?id=".$invoice_id);
exit;

}
catch(Exception $e){

$conn->rollback();

addAuditLog(
$conn,
$user_id,
'Software Billing',
'Renew Subscription Failed',
$e->getMessage(),
'',
'',
'Failed'
);

$_SESSION['error_message']=$e->getMessage();

header("Location:subscriptions.php");
exit;

}

?>