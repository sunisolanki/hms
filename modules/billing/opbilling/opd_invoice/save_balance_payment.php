<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
session_start();
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if(!isset($_SESSION['user_id'])){
header("Location:/login.php");
exit;
}
if($_SERVER['REQUEST_METHOD']!=='POST'){
header("Location:index.php");
exit;
}
$opd_id=(int)($_POST['opd_id'] ?? 0);
$payment_mode=trim($_POST['payment_mode'] ?? '');
$items=$_POST['items'] ?? [];
$bank_name=trim($_POST['bank_name'] ?? '');
$reference_no=trim($_POST['reference_no'] ?? '');
$transaction_id=trim($_POST['transaction_id'] ?? '');
$upi_app=trim($_POST['upi_app'] ?? '');
$cheque_no=trim($_POST['cheque_no'] ?? '');
$cheque_date=!empty($_POST['cheque_date'])?$_POST['cheque_date']:null;
$credit_days=(int)($_POST['credit_days'] ?? 0);
$remarks=trim($_POST['remarks'] ?? '');
$user_id=(int)$_SESSION['user_id'];
$allowed_modes=['Cash','UPI','Card','Cheque','NEFT','Credit','ECS'];
if($opd_id<=0){
die("Invalid OPD ID.");
}
if(!in_array($payment_mode,$allowed_modes,true)){
die("Invalid payment mode.");
}
if(!is_array($items)||empty($items)){
die("No payment items selected.");
}
function generateReceiptNo($conn,$hospital_id,$branch_id){
$hospital_code='HOSP';
$branch_code='MAIN';
$stmt=$conn->prepare("
SELECT hospital_code
FROM hospital_master
WHERE id=?
LIMIT 1
");
$stmt->bind_param("i",$hospital_id);
$stmt->execute();
$row=$stmt->get_result()->fetch_assoc();
$stmt->close();
if(!empty($row['hospital_code'])){
$hospital_code=$row['hospital_code'];
}
$stmt=$conn->prepare("
SELECT branch_code
FROM branch_master
WHERE id=?
LIMIT 1
");
$stmt->bind_param("i",$branch_id);
$stmt->execute();
$row=$stmt->get_result()->fetch_assoc();
$stmt->close();
if(!empty($row['branch_code'])){
$branch_code=$row['branch_code'];
}
$today=date('dmy');
$prefix=$hospital_code.'-'.$branch_code.'-RCPT-'.$today.'-';
$stmt=$conn->prepare("
SELECT MAX(receipt_sequence) AS max_sequence
FROM opd_bill_payments
WHERE DATE(payment_date)=CURDATE()
");
$stmt->execute();
$row=$stmt->get_result()->fetch_assoc();
$stmt->close();
$sequence=(int)($row['max_sequence'] ?? 0)+1;
$receipt_no=$prefix.str_pad($sequence,6,'0',STR_PAD_LEFT);
return [
'receipt_no'=>$receipt_no,
'receipt_sequence'=>$sequence
];
}
$conn->begin_transaction();
try{
$stmt=$conn->prepare("
SELECT
id,
net_payable,
paid_amount,
balance_amount,
payment_mode,
payment_txnid,
status
FROM appointments
WHERE id=?
AND type='doctor'
FOR UPDATE
");
$stmt->bind_param("i",$opd_id);
$stmt->execute();
$result=$stmt->get_result();
if($result->num_rows===0){
throw new Exception("OPD record not found.");
}
$opd=$result->fetch_assoc();
$stmt->close();
$selected_items=[];
foreach($items as $item){
if(!isset($item['selected'])||$item['selected']!='1'){
continue;
}
$type=trim($item['type'] ?? '');
$reference_id=(int)($item['reference_id'] ?? 0);
$pay_amount=round((float)($item['pay_amount'] ?? 0),2);
if(!in_array($type,['Consultation','Service'],true)){
throw new Exception("Invalid payment item type.");
}
if($reference_id<=0||$pay_amount<=0){
throw new Exception("Invalid payment amount.");
}
$selected_items[]=[
'type'=>$type,
'reference_id'=>$reference_id,
'pay_amount'=>$pay_amount
];
}
if(empty($selected_items)){
throw new Exception("Please select at least one payment item.");
}
$consultation_selected=false;
foreach($selected_items as $selected){
if($selected['type']!=='Consultation'){
continue;
}
$consultation_selected=true;
if($selected['reference_id']!==$opd_id){
throw new Exception("Invalid consultation reference.");
}
$current_pending=round((float)$opd['balance_amount'],2);
if($current_pending<=0){
throw new Exception("Consultation payment is already completed.");
}
if(abs($selected['pay_amount']-$current_pending)>0.01){
throw new Exception(
"Consultation must be paid in full. Required amount: ₹".
number_format($current_pending,2)
);
}
$new_paid=round((float)$opd['paid_amount']+$current_pending,2);
$new_balance=round((float)$opd['net_payable']-$new_paid,2);
if($new_balance<0){
$new_balance=0;
}
$new_status=$new_balance<=0?'Paid':'Partial';
$stmt=$conn->prepare("
UPDATE appointments
SET
paid_amount=?,
balance_amount=?,
payment_mode=?,
payment_txnid=?,
status=?,
updated_at=NOW()
WHERE id=?
");
$stmt->bind_param(
"ddsssi",
$new_paid,
$new_balance,
$payment_mode,
$transaction_id,
$new_status,
$opd_id
);
$stmt->execute();
$stmt->close();
}
$service_payments=[];
foreach($selected_items as $selected){
if($selected['type']!=='Service'){
continue;
}
$bill_id=$selected['reference_id'];
$pay_amount=$selected['pay_amount'];
$stmt=$conn->prepare("
SELECT
id,
bill_no,
hospital_id,
branch_id,
net_payable,
paid_amount,
balance_amount,
status
FROM opd_bills
WHERE id=?
AND opd_id=?
AND status NOT IN('Cancelled','Refunded')
FOR UPDATE
");
$stmt->bind_param("ii",$bill_id,$opd_id);
$stmt->execute();
$result=$stmt->get_result();
if($result->num_rows===0){
$stmt->close();
throw new Exception("Service bill not found.");
}
$bill=$result->fetch_assoc();
$stmt->close();
$current_pending=round((float)$bill['balance_amount'],2);
if($current_pending<=0){
throw new Exception("Bill ".$bill['bill_no']." is already paid.");
}
if($pay_amount>$current_pending+0.01){
throw new Exception(
"Payment amount cannot exceed pending balance for bill ".$bill['bill_no']
);
}
$new_paid=round((float)$bill['paid_amount']+$pay_amount,2);
$new_balance=round((float)$bill['net_payable']-$new_paid,2);
if($new_balance<0){
$new_balance=0;
}
$new_status=$new_balance<=0?'Paid':'Partial';
$receipt=generateReceiptNo(
$conn,
(int)$bill['hospital_id'],
(int)$bill['branch_id']
);
$receipt_no=$receipt['receipt_no'];
$receipt_sequence=$receipt['receipt_sequence'];
$stmt=$conn->prepare("
INSERT INTO opd_bill_payments(
bill_id,
receipt_no,
receipt_sequence,
payment_date,
transaction_type,
payment_mode,
amount,
balance_after_payment,
bill_status,
bank_name,
reference_no,
transaction_id,
upi_app,
cheque_no,
cheque_date,
credit_days,
remarks,
received_by,
status
)
VALUES(
?,
?,
?,
NOW(),
'Receipt',
?,
?,
?,
?,
?,
?,
?,
?,
?,
?,
?,
?,
?,
'Success'
)
");
$stmt->bind_param(
"isissddsssssssis",
$bill_id,
$receipt_no,
$receipt_sequence,
$payment_mode,
$pay_amount,
$new_balance,
$new_status,
$bank_name,
$reference_no,
$transaction_id,
$upi_app,
$cheque_no,
$cheque_date,
$credit_days,
$remarks,
$user_id
);
$stmt->execute();
$stmt->close();
$stmt=$conn->prepare("
UPDATE opd_bills
SET
paid_amount=?,
balance_amount=?,
status=?,
updated_by=?,
updated_at=NOW()
WHERE id=?
");
$stmt->bind_param(
"ddsii",
$new_paid,
$new_balance,
$new_status,
$user_id,
$bill_id
);
$stmt->execute();
$stmt->close();
$service_payments[]=[
'bill_id'=>$bill_id,
'bill_no'=>$bill['bill_no'],
'receipt_no'=>$receipt_no,
'amount'=>$pay_amount
];
}
$conn->commit();
header(
"Location:generate_invoice.php?opd_id=".$opd_id.
"&payment_success=1"
);
exit;
}catch(Exception $e){
$conn->rollback();
die(
"<h3>Payment Failed</h3>".
"<p>".htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8')."</p>".
"<p><a href='javascript:history.back()'>← Back</a></p>"
);
}
?>