<?php
session_start();
date_default_timezone_set('Asia/Kolkata');
ini_set('display_errors',1);
error_reporting(E_ALL);
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if(!isset($_SESSION['user_id'])){
    header("Location:/login.php");
    exit;
}
$bill_id=(int)($_GET['bill_id'] ?? 0);
if($bill_id<=0){
    die("Invalid Bill ID");
}
/*==================================================
GET BILL HEADER
==================================================*/
$stmt=$conn->prepare("
SELECT
b.*,
a.opd_no,
a.appointment_code,
a.token_no,
a.first_name,
a.last_name,
a.title,
a.mobile,
a.phone,
a.email,
a.sex,
a.marital_status,
a.guardian_name,
a.address,
a.age_y,
a.age_m,
a.age_d,
a.appointment_date,
a.appointment_time,
pm.uhid,
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
bm.branch_code,
dm.department_name,
doc.full_name AS doctor_name,
cu.full_name AS created_by_name,
em.empanelment_name
FROM opd_bills b
LEFT JOIN appointments a
ON a.id=b.opd_id
LEFT JOIN patient_master pm
ON pm.id=b.patient_id
LEFT JOIN hospital_master hm
ON hm.id=b.hospital_id
LEFT JOIN branch_master bm
ON bm.id=b.branch_id
LEFT JOIN department_master dm
ON dm.id=b.department_id
LEFT JOIN users doc
ON doc.id=a.entity_id

LEFT JOIN users cu
ON cu.id=b.created_by

LEFT JOIN empanelment_master em
ON em.id=b.empanelment_id
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

$bill = array_merge([
    'doctor_name' => '-',
    'department_name' => '-',
    'empanelment_name' => '-',
    'hospital_name' => '-',
    'hospital_address' => '-',
    'hospital_phone' => '-',
    'hospital_email' => '-',
    'logo' => '',
    'mobile' => '-',
    'guardian_name' => '-',
    'address' => '-',
    'grand_total' => 0,
    'discount_amount' => 0,
    'net_payable' => 0,
    'paid_amount' => 0,
    'balance_amount' => 0
], $bill);
$stmt->close();
function e($value){
    return htmlspecialchars((string)($value ?: '-'), ENT_QUOTES, 'UTF-8');
}
/*==================================================
GET BILL SERVICES
==================================================*/
$stmt=$conn->prepare("
SELECT
bd.*,
sm.service_name,
sm.service_code
FROM opd_bill_details bd
LEFT JOIN service_master sm
ON sm.id=bd.service_id
WHERE bd.bill_id=?
ORDER BY bd.id
");
$stmt->bind_param("i",$bill_id);
$stmt->execute();
$services=$stmt->get_result();
$stmt->close();
/*==================================================
GET PAYMENT DETAILS
==================================================*/
$stmt=$conn->prepare("
SELECT
receipt_no,
payment_date,
payment_mode,
amount,
balance_after_payment,
bill_status,
transaction_id,
reference_no,
bank_name,
remarks
FROM opd_bill_payments
WHERE bill_id=?
ORDER BY id ASC
");

$stmt->bind_param("i",$bill_id);
$stmt->execute();
$payments=$stmt->get_result();
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>
Print OPD Bill
</title>
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
.bill{
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
.billtitle{
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
</style>
</head>
<body>
<div class="bill">
<div class="header">
<div class="logo">
<?php
if(!empty($bill['logo']) && file_exists($_SERVER['DOCUMENT_ROOT'].'/'.$bill['logo'])){
?>
<img src="<?= htmlspecialchars('/'.$bill['logo']) ?>" alt="Hospital Logo">
<?php
}
?>
</div>
<div class="hospital">
<h1><?= htmlspecialchars($bill['hospital_name']) ?></h1>
<p>
<?= htmlspecialchars($bill['hospital_address']) ?>
<?php if(!empty($bill['city'])){ ?>
, <?= htmlspecialchars($bill['city']) ?>
<?php } ?>
<?php if(!empty($bill['state'])){ ?>
, <?= htmlspecialchars($bill['state']) ?>
<?php } ?>
<?php if(!empty($bill['pincode'])){ ?>
- <?= htmlspecialchars($bill['pincode']) ?>
<?php } ?>
</p>
<p>
<b>Mobile :</b>
<?= htmlspecialchars($bill['hospital_mobile'] ?? '-') ?>
&nbsp;&nbsp;&nbsp;
<b>Phone :</b>
<?= htmlspecialchars($bill['hospital_phone'] ?? '-') ?>
&nbsp;&nbsp;&nbsp;
<b>Email :</b>
<?= htmlspecialchars($bill['hospital_email'] ?? '-') ?>
</p>
<div class="billtitle">
OPD BILL
</div>
</div>
</div>
<div class="section-title">
Bill Information
</div>
<table class="info-table">
<tr>
<td class="label">
Bill No.
</td>
<td>
<?= htmlspecialchars($bill['bill_no']) ?>
</td>
<td class="label">
Bill Date
</td>
<td>
<?= date("d-m-Y h:i A",strtotime($bill['bill_date'])) ?>
</td>
<tr>
<td class="label">
Created On
</td>
<td>
<?= date("d-m-Y h:i A",strtotime($bill['created_at'])) ?>
</td>
<td class="label">
Created By
</td>
<td>
<?= htmlspecialchars($bill['created_by_name'] ?? '-') ?>
</td>
</tr>
</tr>
<tr>
<td class="label">
OPD No.
</td>
<td>
<?= htmlspecialchars((string)($bill['opd_no'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
</td>
<td class="label">
UHID
</td>
<td>
<?= htmlspecialchars($bill['uhid'] ?? '-') ?>
</td>
</tr>
<tr>
<td class="label">
Appointment
</td>
<td>
<?= htmlspecialchars($bill['appointment_code'] ?? '-') ?>
</td>
<td class="label">
Token
</td>
<td>
<?= htmlspecialchars($bill['token_no'] ?? '-') ?>
</td>
</tr>
</table>
<div class="section-title">
Patient Details
</div>
<table class="info-table">
<tr>
<td class="label">
Patient
</td>
<td>
<?= htmlspecialchars(trim(($bill['title'] ?? '').' '.($bill['first_name'] ?? '').' '.($bill['last_name'] ?? ''))) ?>
</td>
<td class="label">
Gender
</td>
<td>
<?= htmlspecialchars($bill['sex'] ?? '-') ?>
</td>
</tr>
<tr>
<td class="label">
Age
</td>
<td>
<?= (int)($bill['age_y'] ?? 0) ?>Y
<?= (int)($bill['age_m'] ?? 0) ?>M
<?= (int)($bill['age_d'] ?? 0) ?>D
</td>
<td class="label">
Mobile
</td>
<td>
<?= htmlspecialchars($bill['mobile'] ?? '-') ?>
</td>
</tr>
<tr>
<td class="label">
Guardian
</td>
<td>
<?= htmlspecialchars($bill['guardian_name'] ?? '-') ?>
</td>
<td class="label">
Marital Status
</td>
<td>
<?= htmlspecialchars($bill['marital_status'] ?? '-') ?>
</td>
</tr>
<tr>
<td class="label">
Address
</td>
<td colspan="3">
<?= nl2br(htmlspecialchars($bill['address'] ?? '-')) ?>
</td>
</tr>
<tr>
<td class="label">
Doctor
</td>
<td>
<?= htmlspecialchars((string)($bill['doctor_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
</td>
<td class="label">
Department
</td>
<td>
<?= htmlspecialchars($bill['department_name'] ?? '-') ?>
</td>
</tr>
<tr>
<td class="label">
Empanelment
</td>
<td colspan="3">
<?= htmlspecialchars($bill['empanelment_name'] ?? '-') ?>
</td>
</tr>
</table>
<div class="section-title">
Services Details
</div>
<table class="info-table" style="margin-top:0;">
<thead>
<tr style="background:#0b5ed7;color:#fff;">
<th style="padding:8px;">#</th>
<th>Service</th>
<th>Qty</th>
<th>Charge</th>
<th>Emergency</th>
<th>Disc %</th>
<th>Disc Amt</th>
<th>Net Amount</th>
</tr>
</thead>
<tbody>
<?php
$sr=1;
while($service=$services->fetch_assoc()){
?>
<tr>
<td align="center">
<?= $sr++ ?>
</td>
<td>
<?= htmlspecialchars($service['service_name'] ?? 'Unknown Service') ?>
</td>
<td align="center">
<?= $service['qty'] ?>
</td>
<td align="right">
<?= number_format($service['charge'],2) ?>
</td>
<td align="right">
<?= number_format($service['emergency_charge'],2) ?>
</td>
<td align="right">
<?= number_format($service['discount_percent'],2) ?>
</td>
<td align="right">
<?= number_format($service['discount_amount'],2) ?>
</td>
<td align="right">
<b><?= number_format($service['net_amount'],2) ?></b>
</td>
</tr>
<?php } ?>
</tbody>
</table>
<br>
<table style="width:380px;margin-left:auto;border-collapse:collapse;">
<tr>
<td style="border:1px solid #ccc;padding:8px;font-weight:bold;">
Gross Total
</td>
<td style="border:1px solid #ccc;padding:8px;text-align:right;">
₹ <?= number_format($bill['grand_total'] ?? 0,2) ?>
</td>
</tr>
<tr>
<td style="border:1px solid #ccc;padding:8px;font-weight:bold;">
Discount
</td>
<td style="border:1px solid #ccc;padding:8px;text-align:right;">
<?= htmlspecialchars($bill['discount_name'] ?? '-') ?>
<br>
₹ <?= number_format((float)($bill['discount_amount'] ?? 0),2) ?>
</td>
</tr>
<tr>
<td style="border:1px solid #ccc;padding:8px;font-weight:bold;background:#f5f5f5;">
Net Payable
</td>
<td style="border:1px solid #ccc;padding:8px;text-align:right;background:#f5f5f5;font-size:18px;font-weight:bold;">
₹ <?= number_format((float)($bill['net_payable'] ?? 0),2) ?>
</td>
</tr>
<tr>
<td style="border:1px solid #ccc;padding:8px;font-weight:bold;">
Paid Amount
</td>
<td style="border:1px solid #ccc;padding:8px;text-align:right;">
₹ <?= number_format($bill['paid_amount'] ?? 0,2) ?>
</td>
</tr>
<tr>
<td style="border:1px solid #ccc;padding:8px;font-weight:bold;">
Balance
</td>
<td style="border:1px solid #ccc;padding:8px;text-align:right;">
₹ <?= number_format($bill['balance_amount'] ?? 0,2) ?>
</td>
</tr>
</table>
<div class="section-title">
Payment History
</div>

<table class="info-table">
<thead>
<tr style="background:#0b5ed7;color:#fff;">
<th>Receipt No.</th>
<th>Date</th>
<th>Payment Mode</th>
<th>Amount</th>
<th>Balance After</th>
<th>Status</th>
</tr>
</thead>
<tbody>

<?php while($payment=$payments->fetch_assoc()){ ?>

<tr>

<td>
<?= e($payment['receipt_no']) ?>
</td>

<td>
<?= date("d-m-Y h:i A",strtotime($payment['payment_date'])) ?>
</td>

<td>
<?= e($payment['payment_mode']) ?>
</td>

<td align="right">
₹ <?= number_format((float)$payment['amount'],2) ?>
</td>

<td align="right">
₹ <?= number_format((float)$payment['balance_after_payment'],2) ?>
</td>

<td>
<?= e($payment['bill_status']) ?>
</td>

</tr>

<?php } ?>

</tbody>
</table>
<?php
if(!empty($bill['remarks'])){
?>
<div class="section-title">
Remarks
</div>
<div style="
border:1px solid #ccc;
padding:10px;
min-height:70px;
font-size:13px;
line-height:22px;
margin-bottom:20px;
">
<?= nl2br(htmlspecialchars((string)($bill['remarks'] ?? ''), ENT_QUOTES, 'UTF-8')) ?>
</div>
<?php
}
?>
<br><br>
<table style="width:100%;margin-top:30px;">
<tr>
<td align="left" style="width:33%;">

_________________________<br>

<b>Patient Signature</b>
</td>
<td align="center" style="width:34%;">

_________________________<br>
<b>Cashier</b>
</td>
<td align="right" style="width:33%;">

_________________________<br>
<b>Authorized Signatory</b>
</td>
</tr>
</table>
<div style="
margin-top:40px;
text-align:center;
font-size:12px;
color:#666;
border-top:1px dashed #999;
padding-top:10px;
">
<b>
*** Computer Generated Bill ***
</b>
<br><br>
Thank You for Visiting
<br>
Wish You Good Health
</div>
<br>
<div style="text-align:center;">
<button
onclick="window.print();"
style="
padding:10px 35px;
background:#0b5ed7;
color:#fff;
border:none;
border-radius:5px;
font-size:15px;
cursor:pointer;
">
🖨 Print Bill
</button>
</div>
</div>
<style>
@media print{
body{
background:#fff;
padding:0;
}
button{
display:none;
}
.bill{
box-shadow:none;
margin:0;
width:100%;
padding:0;
}
@page{
size:A4;
margin:8mm;
}
}
</style>
<script>
window.onload=function(){
setTimeout(function(){
window.print();
},500);
};

window.onafterprint=function(){

if(window.parent && window.parent!==window){

window.parent.closeBillPopup();

window.parent.location.reload();

}else{

history.back();

}

};
</script>
</body>
</html>