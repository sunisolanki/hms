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

$payment_id=(int)($_GET['payment_id'] ?? 0);

if($payment_id<=0){
    die("Invalid Payment ID.");
}

/*-----------------------------------------
LOAD IPD PAYMENT
-----------------------------------------*/

$stmt=$conn->prepare("
SELECT

pay.*,

ia.ipd_no AS admission_ipd_no,
ia.appointment_id,
ia.doctor_id,
ia.department_id,
ia.hospital_id,
ia.branch_id,

ib.bill_no,
ib.bill_date,
ib.total_amount,
ib.discount_amount,
ib.net_amount,
ib.paid_amount,
ib.balance_amount,
ib.payment_status,

p.uhid,
p.title,
p.first_name,
p.last_name,
p.mobile,

u.full_name AS doctor_name,

d.department_name,

hm.hospital_name,
hm.hospital_code,
hm.address AS hospital_address,
hm.phone,
hm.mobile AS hospital_mobile,
hm.email,
hm.logo,

bm.branch_name,
bm.branch_code

FROM ipd_payments pay

INNER JOIN ipd_admissions ia
ON ia.id=pay.ipd_admission_id

LEFT JOIN ipd_bills ib
ON ib.id=pay.ipd_bill_id

LEFT JOIN patient_master p
ON p.id=pay.patient_id

LEFT JOIN users u
ON u.id=ia.doctor_id

LEFT JOIN department_master d
ON d.id=ia.department_id

LEFT JOIN hospital_master hm
ON hm.id=ia.hospital_id

LEFT JOIN branch_master bm
ON bm.id=ia.branch_id

WHERE pay.id=?

LIMIT 1
");

$stmt->bind_param(
    "i",
    $payment_id
);

$stmt->execute();

$result=$stmt->get_result();

if($result->num_rows===0){

    die("IPD Payment Receipt not found.");

}

$row=$result->fetch_assoc();

$stmt->close();

function e($value){

    return htmlspecialchars(
        (string)($value ?? '-'),
        ENT_QUOTES,
        'UTF-8'
    );

}

/*-----------------------------------------
RECEIPT / PAYMENT INFORMATION
-----------------------------------------*/

$payment_no=$row['payment_no'] ?? '';

$payment_date=$row['payment_date'] ?? '';

$receipt_date='-';

if($payment_date!==''){

    $receipt_date=date(
        "d-M-Y h:i A",
        strtotime($payment_date)
    );

}

/*-----------------------------------------
BILL INFORMATION
-----------------------------------------*/

$has_bill=(
    !empty($row['ipd_bill_id']) &&
    (int)$row['ipd_bill_id']>0
);

$bill_date='-';

if(
    $has_bill &&
    !empty($row['bill_date'])
){

    $bill_date=date(
        "d-M-Y",
        strtotime($row['bill_date'])
    );

}

/*-----------------------------------------
AMOUNTS
-----------------------------------------*/

$payment_amount=(float)(
    $row['payment_amount'] ?? 0
);

if($has_bill){

    $bill_amount=(float)(
        $row['net_amount'] ?? 0
    );

    $paid_amount=(float)(
        $row['paid_amount'] ?? 0
    );

    /*
    paid_amount normally includes the
    current payment, therefore previous
    paid amount is current paid minus
    current payment.
    */

    $previous_paid=
        $paid_amount-
        $payment_amount;

    if($previous_paid<0){
        $previous_paid=0;
    }

    $balance=(float)(
        $row['balance_amount'] ?? 0
    );

}else{

    /*
    Advance received before billing.
    There is no bill yet.
    */

    $bill_amount=0;

    $previous_paid=0;

    $balance=0;

}

/*-----------------------------------------
PAYMENT DETAILS
-----------------------------------------*/

$transaction_no=
    trim($row['transaction_no'] ?? '');

$remarks=
    trim($row['remarks'] ?? '');

$payment_details=[];

if(
    !empty($row['payment_details'])
){

    $decoded=json_decode(
        $row['payment_details'],
        true
    );

    if(
        is_array($decoded)
    ){

        $payment_details=$decoded;

    }

}

/*-----------------------------------------
PATIENT NAME
-----------------------------------------*/

$patient_name=trim(
    ($row['title'] ?? '').
    ' '.
    ($row['first_name'] ?? '').
    ' '.
    ($row['last_name'] ?? '')
);

if($patient_name===''){
    $patient_name='-';
}

/*-----------------------------------------
PAYMENT STATUS
-----------------------------------------*/

if(!$has_bill){

    $payment_status='Advance';

}elseif($balance<=0){

    $payment_status='Paid';

}else{

    $payment_status='Partial';

}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>IPD Payment Receipt</title>
<style>
*{
margin:0;
padding:0;
box-sizing:border-box;
font-family:Arial,Helvetica,sans-serif;
}
body{
background:#f2f4f7;
padding:20px;
}
.receipt{
width:210mm;
min-height:297mm;
margin:auto;
background:#fff;
padding:20px;
box-shadow:0 0 10px rgba(0,0,0,.15);
}
.header{
display:flex;
justify-content:space-between;
align-items:center;
border-bottom:2px solid #0b5ed7;
padding-bottom:10px;
margin-bottom:15px;
}
.logo{
width:90px;
height:90px;
display:flex;
align-items:center;
justify-content:flex-start;
}
.logo img{
width:90px;
height:90px;
object-fit:contain;
}
.hospital{
flex:1;
text-align:center;
}
.hospital h1{
font-size:28px;
color:#0b5ed7;
margin-bottom:4px;
}
.hospital p{
font-size:13px;
line-height:20px;
}
.receipt-title{
margin-top:8px;
font-size:20px;
font-weight:bold;
color:#c62828;
}
.info-table{
width:100%;
border-collapse:collapse;
margin-top:10px;
margin-bottom:15px;
}
.info-table td{
border:1px solid #ddd;
padding:7px;
font-size:13px;
}
.label{
background:#f5f5f5;
font-weight:bold;
width:15%;
}
.section-title{
background:#0b5ed7;
color:#fff;
padding:8px;
font-size:14px;
font-weight:bold;
margin-top:15px;
}
.payment-table{
width:100%;
border-collapse:collapse;
margin-top:10px;
}
.payment-table th{
background:#0b5ed7;
color:#fff;
border:1px solid #0b5ed7;
padding:9px;
font-size:13px;
text-align:left;
}
.payment-table td{
border:1px solid #ddd;
padding:9px;
font-size:13px;
}
.amount{
text-align:right;
}
.received-row td{
background:#eaf3ff;
font-weight:bold;
}
.balance-row td{
background:#f5f5f5;
font-weight:bold;
font-size:16px;
}
.remarks-box{
border:1px solid #ccc;
padding:10px;
min-height:60px;
font-size:13px;
line-height:20px;
}
.signatures{
width:100%;
margin-top:55px;
border-collapse:collapse;
}
.signatures td{
width:33.33%;
vertical-align:bottom;
font-size:13px;
}
.signature-line{
border-top:1px solid #000;
width:180px;
margin-top:40px;
padding-top:5px;
font-weight:bold;
}
.signature-center{
margin-left:auto;
margin-right:auto;
text-align:center;
}
.signature-right{
margin-left:auto;
text-align:right;
}
.footer-note{
margin-top:40px;
text-align:center;
font-size:12px;
color:#666;
border-top:1px dashed #999;
padding-top:10px;
line-height:20px;
}
.actions{
text-align:center;
margin-top:25px;
}
.print-btn{
padding:10px 35px;
background:#0b5ed7;
color:#fff;
border:none;
border-radius:5px;
font-size:15px;
cursor:pointer;
margin:5px;
}
.back-btn{
padding:10px 35px;
background:#6c757d;
color:#fff;
border:none;
border-radius:5px;
font-size:15px;
cursor:pointer;
margin:5px;
}
@media print{
@page{
size:A4;
margin:8mm;
}
body{
background:#fff;
padding:0;
margin:0;
}
.receipt{
box-shadow:none;
margin:0;
width:100%;
min-height:auto;
padding:0;
}
.actions{
display:none;
}
}
</style>
</head>
<body>
<div class="receipt">
<div class="header">
<div class="logo">
<?php
$logo_path='';
if(!empty($row['logo'])){
$logo_value=ltrim($row['logo'],'/');
$possible_path=$_SERVER['DOCUMENT_ROOT'].'/'.$logo_value;
if(file_exists($possible_path)){
$logo_path='/'.$logo_value;
}
}
if($logo_path!=''){
?>
<img src="<?= e($logo_path) ?>" alt="Hospital Logo">
<?php
}
?>
</div>
<div class="hospital">
<h1><?= e($row['hospital_name']) ?></h1>
<p>
<?= e($row['hospital_address']) ?>
<?php if(!empty($row['city'])){ ?>
, <?= e($row['city']) ?>
<?php } ?>
<?php if(!empty($row['state'])){ ?>
, <?= e($row['state']) ?>
<?php } ?>
<?php if(!empty($row['pincode'])){ ?>
- <?= e($row['pincode']) ?>
<?php } ?>
</p>
<p>
<b>Mobile :</b> <?= e($row['hospital_mobile'] ?? '') ?>
&nbsp;&nbsp;&nbsp;
<b>Phone :</b> <?= e($row['phone'] ?? '') ?>
&nbsp;&nbsp;&nbsp;
<b>Email :</b> <?= e($row['email'] ?? '') ?>
</p>
<div class="receipt-title">
IPD PAYMENT RECEIPT
</div>
</div>
</div>
<div class="section-title">
Receipt Information
</div>
<table class="info-table">
<tr>
<td class="label">Receipt No.</td>
<td><?= e($payment_no) ?></td>
<td class="label">Receipt Date</td>
<td><?= e($receipt_date) ?></td>
</tr>
<tr>
<td class="label">IPD Bill No.</td>
<td>
<?php if($has_bill){ ?>
<?= e($row['bill_no']) ?>
<?php }else{ ?>
Advance / Not Billed
<?php } ?>
</td>
<td class="label">Bill Date</td>
<td><?= e($bill_date) ?></td>
</tr>
<tr>
<td class="label">IPD No.</td>
<td><?= e($row['ipd_no'] ?? $row['admission_ipd_no'] ?? '-') ?></td>
<td class="label">UHID</td>
<td><?= e($row['uhid']) ?></td>
</tr>
</table>
<div class="section-title">
Patient Details
</div>
<table class="info-table">
<tr>
<td class="label">Patient</td>
<td><?= e($patient_name) ?></td>
<td class="label">Mobile</td>
<td><?= e($row['mobile']) ?></td>
</tr>
<tr>
<td class="label">Doctor</td>
<td><?= e($row['doctor_name']) ?></td>
<td class="label">Department</td>
<td><?= e($row['department_name']) ?></td>
</tr>
</table>
<div class="section-title">
Payment Details
</div>
<table class="payment-table">
<thead>
<tr>
<th>Particular</th>
<th style="text-align:right;">Amount (₹)</th>
</tr>
</thead>
<tbody>
<?php if($has_bill){ ?>
<tr>
<td>Bill Amount</td>
<td class="amount"><?= number_format((float)$bill_amount,2) ?></td>
</tr>
<tr>
<td>Previous Paid Amount</td>
<td class="amount"><?= number_format((float)$previous_paid,2) ?></td>
</tr>
<?php }else{ ?>
<tr>
<td>Payment Type</td>
<td class="amount">Advance</td>
</tr>
<?php } ?>
<tr class="received-row">
<td>Received Today (<?= e($row['payment_mode']) ?>)</td>
<td class="amount"><?= number_format((float)$payment_amount,2) ?></td>
</tr>
<tr class="balance-row">
<td>
<?php if($has_bill){ ?>
Balance After Payment
<?php }else{ ?>
Advance Balance / Credit
<?php } ?>
</td>
<td class="amount"><?= number_format((float)$balance,2) ?></td>
</tr>
</tbody>
</table>
<div class="section-title">
Transaction Details
</div>
<table class="info-table">
<tr>
<td class="label">Payment Mode</td>
<td><?= e($row['payment_mode']) ?></td>
<td class="label">Payment Status</td>
<td><?= e($payment_status) ?></td>
</tr>
<tr>
<td class="label">Transaction No.</td>
<td><?= e($transaction_no) ?></td>
<td class="label">IPD Bill</td>
<td>
<?php if($has_bill){ ?>
<?= e($row['bill_no']) ?>
<?php }else{ ?>
Advance
<?php } ?>
</td>
</tr>
<?php
if(!empty($payment_details) && is_array($payment_details)){
foreach($payment_details as $key=>$value){
if($value===null || $value===''){
continue;
}
$label=ucwords(str_replace('_',' ',$key));
?>
<tr>
<td class="label"><?= e($label) ?></td>
<td colspan="3"><?= e($value) ?></td>
</tr>
<?php
}
}
?>
</table>
<div class="section-title">
Remarks
</div>
<div class="remarks-box">
<?= nl2br(e($remarks)) ?>
</div>
<table class="signatures">
<tr>
<td>
<div class="signature-line">
Patient Signature
</div>
</td>
<td>
<div class="signature-line signature-center">
Cashier
</div>
</td>
<td>
<div class="signature-line signature-right">
Authorized Signatory
</div>
</td>
</tr>
</table>
<div class="footer-note">
<b>*** Computer Generated IPD Payment Receipt ***</b>
<br><br>
Thank You for Your Payment
<br>
Wish You Good Health
</div>
<div class="actions">
<button class="print-btn" onclick="window.print();">
🖨 Print Receipt
</button>
<button class="back-btn" onclick="closeReceipt();">
Back
</button>
</div>
</div>
<script>
function closeReceipt(){
if(window.parent && window.parent!==window){
if(typeof window.parent.closeReceiptPopup==='function'){
window.parent.closeReceiptPopup();
}else{
window.parent.location.reload();
}
}else{
history.back();
}
}
window.onload=function(){
setTimeout(function(){
window.print();
},500);
};
window.onafterprint=function(){
if(window.parent && window.parent!==window){
if(typeof window.parent.closeReceiptPopup==='function'){
window.parent.closeReceiptPopup();
}
}else{
history.back();
}
};
</script>
</body>
</html>