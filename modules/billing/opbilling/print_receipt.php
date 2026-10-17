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
$receipt_no=trim($_GET['receipt_no'] ?? '');
if($receipt_no==''){
die("Invalid Receipt Number.");
}
/*==================================================
LOAD RECEIPT
==================================================*/
$stmt=$conn->prepare("
SELECT
pay.*,
b.bill_no,
b.bill_date,
b.grand_total,
b.discount_amount,
b.net_payable,
b.paid_amount,
b.balance_amount,
a.opd_no,
a.appointment_code,
a.token_no,
a.title,
a.first_name,
a.last_name,
a.mobile AS patient_mobile,
a.phone AS patient_phone,
a.email AS patient_email,
a.sex,
a.marital_status,
a.guardian_name,
a.address,
a.age_y,
a.age_m,
a.age_d,
a.appointment_date,
a.appointment_time,
p.uhid,
doc.full_name AS doctor_name,
d.department_name,
hm.hospital_name,
hm.hospital_code,
hm.address AS hospital_address,
hm.city,
hm.state,
hm.pincode,
hm.mobile AS hospital_mobile,
hm.phone AS hospital_phone,
hm.email AS hospital_email,
hm.logo,
bm.branch_name,
bm.branch_code
FROM opd_bill_payments pay
INNER JOIN opd_bills b
ON b.id=pay.bill_id
INNER JOIN appointments a
ON a.id=b.opd_id
LEFT JOIN patient_master p
ON p.id=b.patient_id
LEFT JOIN users doc
ON doc.id=a.entity_id
LEFT JOIN department_master d
ON d.id=b.department_id
LEFT JOIN hospital_master hm
ON hm.id=b.hospital_id
LEFT JOIN branch_master bm
ON bm.id=b.branch_id
WHERE pay.receipt_no=?
LIMIT 1
");
$stmt->bind_param("s",$receipt_no);
$stmt->execute();
$result=$stmt->get_result();
if($result->num_rows==0){
die("Receipt not found.");
}
$row=$result->fetch_assoc();
$stmt->close();
$row=array_merge([
'receipt_no'=>'-',
'payment_date'=>null,
'amount'=>0,
'balance_after_payment'=>0,
'bill_status'=>'-',
'transaction_id'=>'-',
'reference_no'=>'-',
'bank_name'=>'-',
'remarks'=>'-',
'payment_mode'=>'-',
'bill_no'=>'-',
'bill_date'=>null,
'grand_total'=>0,
'discount_amount'=>0,
'net_payable'=>0,
'paid_amount'=>0,
'balance_amount'=>0,
'opd_no'=>'-',
'appointment_code'=>'-',
'token_no'=>'-',
'title'=>'',
'first_name'=>'',
'last_name'=>'',
'patient_mobile'=>'-',
'sex'=>'-',
'marital_status'=>'-',
'guardian_name'=>'-',
'address'=>'-',
'age_y'=>0,
'age_m'=>0,
'age_d'=>0,
'uhid'=>'-',
'doctor_name'=>'-',
'department_name'=>'-',
'hospital_name'=>'-',
'hospital_address'=>'-',
'city'=>'',
'state'=>'',
'pincode'=>'',
'hospital_mobile'=>'-',
'hospital_phone'=>'-',
'hospital_email'=>'-',
'logo'=>''
],$row);
function e($value){
return htmlspecialchars((string)($value ?: '-'),ENT_QUOTES,'UTF-8');
}
$previous_paid=(float)$row['paid_amount']-(float)$row['amount'];
if($previous_paid<0){
$previous_paid=0;
}
$receipt_date='-';
if(!empty($row['payment_date'])){
$receipt_date=date("d-m-Y h:i A",strtotime($row['payment_date']));
}
$bill_date='-';
if(!empty($row['bill_date'])){
$bill_date=date("d-m-Y h:i A",strtotime($row['bill_date']));
}
$patient_name=trim(
($row['title'] ?? '').' '.
($row['first_name'] ?? '').' '.
($row['last_name'] ?? '')
);
if($patient_name==''){
$patient_name='-';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payment Receipt</title>
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
.info-table th{
border:1px solid #ddd;
padding:8px;
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
.summary-table{
width:400px;
margin-left:auto;
margin-top:20px;
border-collapse:collapse;
}
.summary-table td{
border:1px solid #ccc;
padding:9px;
font-size:13px;
}
.summary-label{
font-weight:bold;
background:#f5f5f5;
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
<b>Mobile :</b> <?= e($row['hospital_mobile']) ?>
&nbsp;&nbsp;&nbsp;
<b>Phone :</b> <?= e($row['hospital_phone']) ?>
&nbsp;&nbsp;&nbsp;
<b>Email :</b> <?= e($row['hospital_email']) ?>
</p>
<div class="receipt-title">
PAYMENT RECEIPT
</div>
</div>
</div>
<div class="section-title">
Receipt Information
</div>
<table class="info-table">
<tr>
<td class="label">Receipt No.</td>
<td><?= e($row['receipt_no']) ?></td>
<td class="label">Receipt Date</td>
<td><?= e($receipt_date) ?></td>
</tr>
<tr>
<td class="label">Bill No.</td>
<td><?= e($row['bill_no']) ?></td>
<td class="label">Bill Date</td>
<td><?= e($bill_date) ?></td>
</tr>
<tr>
<td class="label">OPD No.</td>
<td><?= e($row['opd_no']) ?></td>
<td class="label">UHID</td>
<td><?= e($row['uhid']) ?></td>
</tr>
<tr>
<td class="label">Appointment</td>
<td><?= e($row['appointment_code']) ?></td>
<td class="label">Token</td>
<td><?= e($row['token_no']) ?></td>
</tr>
</table>
<div class="section-title">
Patient Details
</div>
<table class="info-table">
<tr>
<td class="label">Patient</td>
<td><?= e($patient_name) ?></td>
<td class="label">Gender</td>
<td><?= e($row['sex']) ?></td>
</tr>
<tr>
<td class="label">Age</td>
<td>
<?= (int)$row['age_y'] ?>Y
<?= (int)$row['age_m'] ?>M
<?= (int)$row['age_d'] ?>D
</td>
<td class="label">Mobile</td>
<td><?= e($row['patient_mobile']) ?></td>
</tr>
<tr>
<td class="label">Guardian</td>
<td><?= e($row['guardian_name']) ?></td>
<td class="label">Marital Status</td>
<td><?= e($row['marital_status']) ?></td>
</tr>
<tr>
<td class="label">Doctor</td>
<td><?= e($row['doctor_name']) ?></td>
<td class="label">Department</td>
<td><?= e($row['department_name']) ?></td>
</tr>
<tr>
<td class="label">Address</td>
<td colspan="3"><?= nl2br(e($row['address'])) ?></td>
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
<tr>
<td>Gross Total</td>
<td class="amount"><?= number_format((float)$row['grand_total'],2) ?></td>
</tr>
<tr>
<td>Discount</td>
<td class="amount"><?= number_format((float)$row['discount_amount'],2) ?></td>
</tr>
<tr>
<td><b>Net Payable</b></td>
<td class="amount"><b><?= number_format((float)$row['net_payable'],2) ?></b></td>
</tr>
<tr>
<td>Previous Paid Amount</td>
<td class="amount"><?= number_format((float)$previous_paid,2) ?></td>
</tr>
<tr class="received-row">
<td>Received Today (<?= e($row['payment_mode']) ?>)</td>
<td class="amount"><?= number_format((float)$row['amount'],2) ?></td>
</tr>
<tr class="balance-row">
<td>Balance After Payment</td>
<td class="amount"><?= number_format((float)$row['balance_after_payment'],2) ?></td>
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
<td><?= e($row['bill_status']) ?></td>
</tr>
<tr>
<td class="label">Transaction ID</td>
<td><?= e($row['transaction_id']) ?></td>
<td class="label">Reference No.</td>
<td><?= e($row['reference_no']) ?></td>
</tr>
<tr>
<td class="label">Bank Name</td>
<td colspan="3"><?= e($row['bank_name']) ?></td>
</tr>
</table>
<div class="section-title">
Remarks
</div>
<div class="remarks-box">
<?= nl2br(e($row['remarks'])) ?>
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
<b>*** Computer Generated Payment Receipt ***</b>
<br><br>
Thank You for Your Payment
<br>
Wish You Good Health
</div>
<div class="actions">
<button class="print-btn" onclick="window.print();">
🖨 Print Receipt
</button>
<button class="back-btn" onclick="history.back();">
Back
</button>
</div>
</div>
<script>
window.onload=function(){
setTimeout(function(){
window.print();
},500);
};
window.onafterprint=function(){
if(window.parent && window.parent!==window){
if(typeof window.parent.closeBillPopup==='function'){
window.parent.closeBillPopup();
}
window.parent.location.reload();
}else{
history.back();
}
};
</script>
</body>
</html>