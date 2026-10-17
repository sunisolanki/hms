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

$current_user_id=(int)$_SESSION['user_id'];

$bill_id=(int)($_GET['bill_id'] ?? $_POST['bill_id'] ?? 0);

if($bill_id<=0){
    die("Invalid Bill ID");
}

/*=========================================
LOAD BILL DETAILS
=========================================*/
$stmt=$conn->prepare("
SELECT
b.*,
a.opd_no,
p.uhid,
CONCAT(
IFNULL(a.title,''),
' ',
IFNULL(a.first_name,''),
' ',
IFNULL(a.last_name,'')
) patient_name,
u.full_name doctor_name
FROM opd_bills b
INNER JOIN appointments a
ON a.id=b.opd_id
INNER JOIN patient_master p
ON p.id=b.patient_id
LEFT JOIN users u
ON u.id=b.doctor_id
WHERE b.id=?
LIMIT 1
");
$stmt->bind_param("i",$bill_id);
$stmt->execute();
$result=$stmt->get_result();
if($result->num_rows==0){
die("Bill not found.");
}
$bill=$result->fetch_assoc();
$stmt->close();
/*=========================================
SAVE PAYMENT
=========================================*/
if($_SERVER['REQUEST_METHOD']=='POST'){
    try{
$bill_id=(int)$_POST['bill_id'];
$receipt_date=$_POST['receipt_date'];
$payment_mode=trim($_POST['payment_mode']);
$amount=(float)$_POST['amount'];
$remarks=trim($_POST['remarks']);
$bank_name=trim($_POST['bank_name'] ?? '');
$reference_no=trim($_POST['reference_no'] ?? '');
$transaction_id=trim($_POST['transaction_id'] ?? '');
$upi_app=trim($_POST['upi_app'] ?? '');
$card_type=$_POST['card_type'] ?? '';
$card_last4=trim($_POST['card_last4'] ?? '');
$card_auth_code=trim($_POST['card_auth_code'] ?? '');
$cheque_no=trim($_POST['cheque_no'] ?? '');
$cheque_date=$_POST['cheque_date'] ?? NULL;
$credit_days=(int)($_POST['credit_days'] ?? 0);
$approved_by=trim($_POST['approved_by'] ?? '');
if($amount<=0){
die("Receive Amount must be greater than 0.");
}
if($amount>$bill['balance_amount']){
die("Receive Amount can't be greater than Balance Amount.");
}
$conn->begin_transaction();
/*=========================================
GENERATE RECEIPT NUMBER
=========================================*/
$receipt_sequence=0;
$receipt_no='';
$stmt=$conn->prepare("
SELECT
IFNULL(MAX(receipt_sequence),0)+1 AS next_sequence
FROM opd_bill_payments
WHERE DATE(payment_date)=DATE(?)
FOR UPDATE
");
$stmt->bind_param(
"s",
$receipt_date
);
$stmt->execute();
$res=$stmt->get_result();
$row=$res->fetch_assoc();
$stmt->close();
$receipt_sequence=(int)$row['next_sequence'];
$today=date(
'dmy',
strtotime($receipt_date)
);
$hospital_id=(int)$bill['hospital_id'];
$branch_id=(int)$bill['branch_id'];

/*-----------------------------------------
GET HOSPITAL CODE
-----------------------------------------*/
$stmt=$conn->prepare("
SELECT hospital_code
FROM hospital_master
WHERE id=?
LIMIT 1
");

$stmt->bind_param("i",$hospital_id);

$stmt->execute();

$res=$stmt->get_result();

$hospital=$res->fetch_assoc();

$stmt->close();

$hospital_code=$hospital['hospital_code'];

/*-----------------------------------------
GET BRANCH CODE
-----------------------------------------*/

$stmt=$conn->prepare("
SELECT branch_code
FROM branch_master
WHERE id=?
LIMIT 1
");

$stmt->bind_param("i",$branch_id);

$stmt->execute();

$res=$stmt->get_result();

$branch=$res->fetch_assoc();

$stmt->close();

$branch_code=$branch['branch_code'];
$receipt_no=
$hospital_code.
'-'.
$branch_code.
'-RCP-'.
$today.
'-'.
str_pad(
$receipt_sequence,
6,
'0',
STR_PAD_LEFT
);

/*=========================================
CALCULATE NEW TOTALS
=========================================*/

$new_paid_amount=$bill['paid_amount']+$amount;

$new_balance_amount=$bill['balance_amount']-$amount;

if($new_balance_amount<0){
$new_balance_amount=0;
}


if($new_paid_amount<=0){
$bill_status="Pending";
}
elseif($new_balance_amount>0){
$bill_status="Partial";
}
else{
$bill_status="Paid";
}

/*=========================================
PAYMENT MODE DETAILS
=========================================*/

$bank_name=NULL;
$reference_no=NULL;
$transaction_id=NULL;
$upi_app=NULL;
$card_type=NULL;
$card_last4=NULL;
$card_auth_code=NULL;
$cheque_no=NULL;
$cheque_date=NULL;
$credit_days=NULL;
$approved_by=NULL;

switch($payment_mode){

case "UPI":

$upi_app=$_POST['upi_app'] ?? NULL;
$transaction_id=$_POST['upi_txn_id'] ?? NULL;

break;

case "Card":

$card_type=$_POST['card_type'] ?? NULL;
$bank_name=$_POST['card_bank_name'] ?? NULL;
$card_last4=$_POST['card_last4'] ?? NULL;
$card_auth_code=$_POST['card_auth_code'] ?? NULL;

break;

case "Cheque":

$cheque_no=$_POST['cheque_no'] ?? NULL;
$bank_name=$_POST['cheque_bank'] ?? NULL;
$cheque_date=$_POST['cheque_date'] ?? NULL;

break;

case "NEFT":

$reference_no=$_POST['neft_ref'] ?? NULL;
$bank_name=$_POST['neft_bank'] ?? NULL;

break;

case "ECS":

$reference_no=$_POST['ecs_reference_no'] ?? NULL;
$bank_name=$_POST['ecs_bank_name'] ?? NULL;

break;

case "Credit":

$credit_days=(int)($_POST['credit_days'] ?? 0);
$approved_by=$_POST['approved_by'] ?? NULL;

break;

}

/*=========================================
INSERT PAYMENT
=========================================*/

$stmt=$conn->prepare("
INSERT INTO opd_bill_payments
(
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
card_type,
card_last4,
card_auth_code,
cheque_no,
cheque_date,
credit_days,
approved_by,
remarks,
received_by,
status
)
VALUES
(
?,
?,
?,
?,
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
?,
?,
?,
?,
'Success'
)
");

$stmt->bind_param(
"isissddssssssssssissi",
$bill_id,
$receipt_no,
$receipt_sequence,
$receipt_date,
$payment_mode,
$amount,
$new_balance_amount,
$bill_status,
$bank_name,
$reference_no,
$transaction_id,
$upi_app,
$card_type,
$card_last4,
$card_auth_code,
$cheque_no,
$cheque_date,
$credit_days,
$approved_by,
$remarks,
$current_user_id
);

if(!$stmt->execute()){
throw new Exception($stmt->error);
}

$stmt->close();

/*=========================================
UPDATE BILL
=========================================*/

$stmt=$conn->prepare("
UPDATE opd_bills
SET
paid_amount=?,
balance_amount=?,
remarks=?,
status=?,
updated_by=?,
updated_at=NOW()
WHERE id=?
");

$stmt->bind_param(
"ddssii",
$new_paid_amount,
$new_balance_amount,
$remarks,
$bill_status,
$current_user_id,
$bill_id
);

if(!$stmt->execute()){
throw new Exception($stmt->error);
}

$stmt->close();

/*=========================================
COMMIT
=========================================*/

$conn->commit();

header(
"Location:print_receipt.php?receipt_no=".$receipt_no
);

exit;

}
catch(Exception $e){
    
$conn->rollback();

die($e->getMessage());
}
}
?>


<!DOCTYPE html>
<html>

<head>

<meta charset="utf-8">

<title>
Receive Payment
</title>

<meta
name="viewport"
content="width=device-width, initial-scale=1">

<link
rel="stylesheet"
href="/assets/css/style.css">

<style>

body{
margin:0;
padding:20px;
background:#f5f6fa;
font-family:Arial,Helvetica,sans-serif;
}

.container{

max-width:1100px;

margin:auto;

background:#fff;

border-radius:10px;

padding:20px;

box-shadow:0 8px 20px rgba(0,0,0,.08);

}

.row{

display:flex;

gap:20px;

}

.left{

flex:1;

}

.right{

width:360px;

}

.card{

border:1px solid #ddd;

border-radius:8px;

padding:15px;

margin-bottom:20px;

}

.card h3{

margin-top:0;

font-size:18px;

}

label{

display:block;

margin-bottom:5px;

font-weight:bold;

}

input,
select,
textarea{

width:100%;

padding:8px;

border:1px solid #ccc;

border-radius:5px;

box-sizing:border-box;

margin-bottom:12px;

}

.summary-row{

display:flex;

justify-content:space-between;

padding:8px 0;

border-bottom:1px solid #eee;

}

.summary-row:last-child{

border-bottom:none;

}

.total{

font-size:18px;

font-weight:bold;

}

.balance{

color:#d32f2f;

font-weight:bold;

font-size:18px;

}

button{

background:#1976d2;

color:#fff;

border:none;

padding:12px 25px;

font-size:15px;

border-radius:6px;

cursor:pointer;

}

button:hover{

background:#125ca3;

}

</style>

</head>

<body>

<div class="container">

<form
method="post"
id="paymentForm">

<input
type="hidden"
name="bill_id"
value="<?= $bill['id'] ?>">

<div class="row">

<div class="left">

<div class="card">

<h3>
💳 Payment Details
</h3>

<label>
Receipt Date
</label>

<input
type="datetime-local"
name="receipt_date"
value="<?= date('Y-m-d\TH:i') ?>">

<label>
Payment Mode
</label>

<select
name="payment_mode"
id="payment_mode">

<option value="Cash">
Cash
</option>

<option value="UPI">
UPI
</option>

<option value="Card">
Card
</option>

<option value="Cheque">
Cheque
</option>

<option value="NEFT">
NEFT
</option>

<option value="Credit">
Credit
</option>

<option value="ECS">
ECS
</option>

</select>

<div id="dynamicFields">

</div>

<label>

Receive Amount

</label>

<input

type="number"

step="0.01"

name="amount"

id="amount"

value="<?= number_format($bill['balance_amount'],2,'.','') ?>">

<label>

Remarks

</label>

<textarea

name="remarks"

rows="4">

</textarea>

<button
type="submit">

Receive Payment

</button>

</div>

</div>

<div class="right">

<div class="card">

<h3>

💰 Bill Summary

</h3>

<div class="summary-row">

<span>

Bill No.

</span>

<strong>

<?= $bill['bill_no'] ?>

</strong>

</div>

<div class="summary-row">

<span>

Patient

</span>

<strong>

<?= htmlspecialchars($bill['patient_name']) ?>

</strong>

</div>

<div class="summary-row">

<span>

UHID

</span>

<strong>

<?= htmlspecialchars($bill['uhid']) ?>

</strong>

</div>

<div class="summary-row">

<span>

OPD No.

</span>

<strong>

<?= htmlspecialchars($bill['opd_no'] ?? '') ?>

</strong>

</div>

<div class="summary-row">

<span>

Doctor

</span>

<strong>

<?= htmlspecialchars($bill['doctor_name']) ?>

</strong>

</div>

<hr>

<div class="summary-row">

<span>

Bill Total

</span>

<strong>

₹ <?= number_format($bill['grand_total'],2) ?>

</strong>

</div>

<div class="summary-row">

<span>

Paid Amount

</span>

<strong>

₹ <?= number_format($bill['paid_amount'],2) ?>

</strong>

</div>

<div class="summary-row">

<span>

Balance

</span>

<strong
class="balance">

₹ <?= number_format($bill['balance_amount'],2) ?>

</strong>

</div>

<div class="summary-row total">

<span>

Balance After Payment

</span>

<strong
id="remainingBalance">

₹ <?= number_format($bill['balance_amount'],2) ?>

</strong>

</div>

</div>

</div>

</div>

</form>

</div>

<script>

const originalBalance=
<?= (float)$bill['balance_amount'] ?>;

document
.getElementById("amount")
.addEventListener("input",function(){

let receive=parseFloat(this.value)||0;

let balance=originalBalance-receive;

if(balance<0){
balance=0;
}

document
.getElementById("remainingBalance")
.innerHTML=
"₹ "+balance.toFixed(2);
});
</script>
</body>
</html>