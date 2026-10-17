<?php
session_start();

header('Content-Type: application/json');

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';

if(!isset($_SESSION['user_id'])){
    echo json_encode([
        'status'=>'error',
        'message'=>'Session expired.'
    ]);
    exit;
}

if(!checkPermission('swbilling','edit')){
    echo json_encode([
        'status'=>'error',
        'message'=>'Permission denied.'
    ]);
    exit;
}

$user_id=(int)$_SESSION['user_id'];

$invoice_id=(int)($_POST['invoice_id'] ?? 0);
$payment_date=trim($_POST['payment_date'] ?? '');
$payment_mode=trim($_POST['payment_mode'] ?? '');
$transaction_no=trim($_POST['transaction_no'] ?? '');
$bank_name=trim($_POST['bank_name'] ?? '');
$amount=(float)($_POST['amount'] ?? 0);
$remarks=trim($_POST['remarks'] ?? '');

if($invoice_id<=0){
    echo json_encode([
        'status'=>'error',
        'message'=>'Invalid Invoice.'
    ]);
    exit;
}

if($payment_date==''){
    echo json_encode([
        'status'=>'error',
        'message'=>'Payment Date is required.'
    ]);
    exit;
}

if($payment_mode==''){
    echo json_encode([
        'status'=>'error',
        'message'=>'Payment Mode is required.'
    ]);
    exit;
}

if($amount<=0){
    echo json_encode([
        'status'=>'error',
        'message'=>'Amount should be greater than zero.'
    ]);
    exit;
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

if($amount>$invoice['balance_amount']){
    throw new Exception("Amount exceeds invoice balance.");
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
received_by,
created_at
)
VALUES
(
?,
?,
?,
?,
?,
?,
?,
?,
NOW()
)
");

$stmt->bind_param(
"issssdsi",
$invoice_id,
$payment_date,
$payment_mode,
$transaction_no,
$bank_name,
$amount,
$remarks,
$user_id
);

if(!$stmt->execute()){
    throw new Exception($stmt->error);
}

$stmt->close();

$newPaid=$invoice['paid_amount']+$amount;

$newBalance=$invoice['total_amount']-$newPaid;

if($newBalance<=0){

$newBalance=0;

$status='Paid';

}else if($newPaid>0){

$status='Partial';

}else{

$status='Pending';

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
$newPaid,
$newBalance,
$status,
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
$conn,
$user_id,
'Software Billing',
'Payment Received',
'Invoice '.$invoice['invoice_no'].' Amount '.$amount
);

$conn->commit();

echo json_encode([
    'status'=>'success',
    'message'=>'Payment received successfully.',
    'paid_amount'=>number_format($newPaid,2,'.',''),
    'balance_amount'=>number_format($newBalance,2,'.',''),
    'payment_status'=>$status
]);

}
catch(Exception $e){

$conn->rollback();

addAuditLog(
$conn,
$user_id,
'Software Billing',
'Payment Failed',
$e->getMessage(),
'',
'',
'Failed'
);

echo json_encode([
    'status'=>'error',
    'message'=>$e->getMessage()
]);

}

exit;
?>