<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
require_once __DIR__.'/includes/init.php';

if(!isset($_SESSION['user_id'])){
    header("Location:/index.php");
    exit;
}

if(!checkPermission('swbilling','add')){
    header("Location:/access_denied.php");
    exit;
}

if($_SERVER['REQUEST_METHOD']!='POST'){
    header("Location:invoices.php");
    exit;
}

$user_id=(int)$_SESSION['user_id'];

$invoice_id=(int)($_POST['invoice_id'] ?? 0);

$payment_date=trim($_POST['payment_date'] ?? '');

$payment_mode=trim($_POST['payment_mode'] ?? '');

$transaction_no=trim($_POST['transaction_no'] ?? '');

$received_amount=(float)($_POST['received_amount'] ?? 0);

$remarks=trim($_POST['remarks'] ?? '');

if($invoice_id<=0){
    die("Invalid Invoice.");
}

if($payment_date==''){
    die("Payment Date is required.");
}

if($payment_mode==''){
    die("Payment Mode is required.");
}

if($received_amount<=0){
    die("Received Amount should be greater than zero.");
}

$conn->begin_transaction();

try{

$stmt=$conn->prepare("
SELECT
id,
invoice_no,
total_amount,
paid_amount,
balance_amount
FROM software_invoices
WHERE id=?
LIMIT 1
");

$stmt->bind_param("i",$invoice_id);

$stmt->execute();

$result=$stmt->get_result();

if($result->num_rows==0){
throw new Exception("Invoice not found.");
}

$invoice=$result->fetch_assoc();

$stmt->close();

if($received_amount>$invoice['balance_amount']){
throw new Exception("Received Amount cannot be greater than Balance Amount.");
}
$stmt=$conn->prepare("
INSERT INTO software_payments
(
invoice_id,
payment_date,
payment_mode,
transaction_no,
bank_name,
amount,
remarks,
received_by
)
VALUES
(
?,?,?,?,?,?,?,?
)
");

$bank_name='';

$stmt->bind_param(
"issssdsi",
$invoice_id,
$payment_date,
$payment_mode,
$transaction_no,
$bank_name,
$received_amount,
$remarks,
$user_id
);

if(!$stmt->execute()){
    throw new Exception($stmt->error);
}

$stmt->close();
$newPaidAmount=$invoice['paid_amount']+$received_amount;

$newBalanceAmount=$invoice['total_amount']-$newPaidAmount;

if($newBalanceAmount<0){
$newBalanceAmount=0;
}

if($newBalanceAmount==0){
$paymentStatus="Paid";
}elseif($newPaidAmount>0){
$paymentStatus="Partial";
}else{
$paymentStatus="Pending";
}

$stmt=$conn->prepare("
UPDATE software_invoices
SET
paid_amount=?,
balance_amount=?,
payment_status=?,
payment_date=?,
payment_mode=?,
transaction_no=?,
remarks=?,
updated_at=NOW()
WHERE id=?
");

$stmt->bind_param(
"ddsssssi",
$newPaidAmount,
$newBalanceAmount,
$paymentStatus,
$payment_date,
$payment_mode,
$transaction_no,
$remarks,
$invoice_id
);

if(!$stmt->execute()){
throw new Exception($stmt->error);
}

$stmt->close();
addAuditLog(
    'Software Payment',
    'Payment',
    'Received payment of ₹'.number_format($received_amount,2).' against Invoice '.$invoice['invoice_no'],
    $invoice_id,
    '',
    json_encode([
        'Received Amount' => $received_amount,
        'Payment Date'    => $payment_date,
        'Payment Mode'    => $payment_mode,
        'Transaction No'  => $transaction_no,
        'Invoice No'      => $invoice['invoice_no']
    ]),
    'SUCCESS'
);

$conn->commit();

$_SESSION['success_message']="Payment received successfully.";

header("Location:invoice_view.php?id=".$invoice_id);

exit;

}catch(Exception $e){

$conn->rollback();

die("ERROR : ".$e->getMessage());

}
?>