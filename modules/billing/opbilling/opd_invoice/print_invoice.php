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

$opd_id=(int)($_GET['opd_id'] ?? 0);

if($opd_id<=0){
die("Invalid OPD ID.");
}

function e($value){
return htmlspecialchars((string)($value ?? '-'),ENT_QUOTES,'UTF-8');
}

function money($amount){
return number_format((float)($amount ?? 0),2);
}

/*==================================================
LOAD OPD / PATIENT / HOSPITAL DETAILS
==================================================*/
$stmt=$conn->prepare("
SELECT
a.id AS opd_id,
a.opd_no,
a.appointment_date,
a.appointment_time,
a.net_payable AS consultation_total,
a.paid_amount AS consultation_paid,
a.balance_amount AS consultation_pending,
a.payment_mode AS consultation_payment_mode,
a.status AS consultation_status,
p.uhid,
CONCAT_WS(' ',a.title,a.first_name,a.last_name) AS patient_name,
a.mobile AS patient_mobile,
u.full_name AS doctor_name,
d.department_name,
COALESCE(bh.hospital_id,0) AS hospital_id,
COALESCE(bh.branch_id,0) AS branch_id,
hm.hospital_name,
hm.hospital_code,
hm.address AS hospital_address,
hm.phone AS hospital_phone,
hm.mobile AS hospital_mobile,
hm.email AS hospital_email,
hm.logo AS hospital_logo,
bm.branch_name,
bm.branch_code
FROM appointments a
LEFT JOIN patient_master p
ON p.id=a.patient_id
LEFT JOIN users u
ON u.id=a.entity_id
LEFT JOIN department_master d
ON d.id=a.department_id
LEFT JOIN(
SELECT
opd_id,
MAX(hospital_id) AS hospital_id,
MAX(branch_id) AS branch_id
FROM opd_bills
WHERE status NOT IN('Cancelled','Refunded')
GROUP BY opd_id
) bh
ON bh.opd_id=a.id
LEFT JOIN hospital_master hm
ON hm.id=bh.hospital_id
LEFT JOIN branch_master bm
ON bm.id=bh.branch_id
WHERE a.id=?
AND a.type='doctor'
LIMIT 1
");

if(!$stmt){
die("Database error: ".e($conn->error));
}

$stmt->bind_param("i",$opd_id);
$stmt->execute();

$result=$stmt->get_result();

if($result->num_rows===0){
$stmt->close();
die("OPD record not found.");
}

$opd=$result->fetch_assoc();
$stmt->close();

/*==================================================
LOAD SERVICE BILL DETAILS
==================================================*/
$stmt=$conn->prepare("
SELECT
b.id AS bill_id,
b.bill_no,
b.bill_date,
b.grand_total,
b.discount_amount,
b.net_payable,
b.paid_amount,
b.balance_amount,
b.status,
bd.id AS detail_id,
bd.service_id,
bd.charge,
bd.qty,
bd.emergency_charge,
bd.discount_type,
bd.discount_percent,
bd.discount_amount AS item_discount_amount,
bd.net_amount,
bd.balance_amount AS item_balance_amount,
sm.service_name
FROM opd_bills b
LEFT JOIN opd_bill_details bd
ON bd.bill_id=b.id
AND bd.status='Active'
LEFT JOIN service_master sm
ON sm.id=bd.service_id
WHERE b.opd_id=?
AND b.status NOT IN('Cancelled','Refunded')
ORDER BY b.bill_date ASC,b.id ASC,bd.id ASC
");

if(!$stmt){
die("Database error: ".e($conn->error));
}

$stmt->bind_param("i",$opd_id);
$stmt->execute();
$result=$stmt->get_result();

$service_rows=[];
$service_bills=[];

while($row=$result->fetch_assoc()){

$service_rows[]=$row;

$bill_id=(int)$row['bill_id'];

if(!isset($service_bills[$bill_id])){

$service_bills[$bill_id]=[
'bill_id'=>$bill_id,
'bill_no'=>$row['bill_no'],
'bill_date'=>$row['bill_date'],
'grand_total'=>(float)$row['grand_total'],
'discount_amount'=>(float)$row['discount_amount'],
'net_payable'=>(float)$row['net_payable'],
'paid_amount'=>(float)$row['paid_amount'],
'balance_amount'=>(float)$row['balance_amount'],
'status'=>$row['status'],
'items'=>[]
];

}

if(!empty($row['detail_id'])){

$service_bills[$bill_id]['items'][]=$row;

}

}

$stmt->close();

/*==================================================
CALCULATE CONSOLIDATED TOTALS
==================================================*/
$consultation_total=(float)$opd['consultation_total'];
$consultation_paid=(float)$opd['consultation_paid'];
$consultation_pending=(float)$opd['consultation_pending'];

$service_total=0;
$service_paid=0;
$service_pending=0;

foreach($service_bills as $bill){

$service_total+=(float)$bill['net_payable'];
$service_paid+=(float)$bill['paid_amount'];
$service_pending+=(float)$bill['balance_amount'];

}

$total_charge=$consultation_total+$service_total;
$total_received=$consultation_paid+$service_paid;
$total_pending=$consultation_pending+$service_pending;

if($total_pending<0){
$total_pending=0;
}

$invoice_status=$total_pending<=0?'PAID':'PENDING';

/*==================================================
LOAD SERVICE PAYMENTS
==================================================*/
$stmt=$conn->prepare("
SELECT
pay.id,
pay.bill_id,
pay.receipt_no,
pay.payment_date,
pay.payment_mode,
pay.amount,
pay.balance_after_payment,
pay.bill_status,
pay.transaction_type,
pay.reference_no,
pay.transaction_id,
pay.remarks,
b.bill_no
FROM opd_bill_payments pay
INNER JOIN opd_bills b
ON b.id=pay.bill_id
WHERE b.opd_id=?
AND pay.status='Success'
ORDER BY pay.payment_date ASC,pay.id ASC
");

$payments=[];

if($stmt){

$stmt->bind_param("i",$opd_id);
$stmt->execute();
$result=$stmt->get_result();

while($row=$result->fetch_assoc()){
$payments[]=$row;
}

$stmt->close();

}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>OPD Invoice - <?= e($opd['opd_no']) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">

<style>
*{
box-sizing:border-box;
}

body{
margin:0;
padding:20px;
font-family:Arial,Helvetica,sans-serif;
background:#f1f3f5;
color:#111;
}

.invoice{
width:210mm;
min-height:297mm;
margin:auto;
background:#fff;
border:1px solid #222;
padding:12mm;
}

.header{
display:flex;
align-items:center;
gap:15px;
padding-bottom:12px;
border-bottom:2px solid #111;
}

.logo-box{
width:90px;
text-align:center;
}

.logo-box img{
max-width:80px;
max-height:80px;
object-fit:contain;
}

.hospital-box{
flex:1;
text-align:center;
}

.hospital-box h1{
margin:0;
font-size:25px;
font-weight:700;
}

.hospital-box p{
margin:3px 0;
font-size:12px;
line-height:1.4;
}

.status-box{
width:115px;
text-align:right;
}

.status{
display:inline-block;
padding:8px 15px;
border:2px solid;
border-radius:5px;
font-size:14px;
font-weight:bold;
}

.status-paid{
color:#198754;
border-color:#198754;
}

.status-pending{
color:#dc3545;
border-color:#dc3545;
}

.invoice-title{
text-align:center;
font-size:20px;
font-weight:bold;
letter-spacing:1px;
margin:15px 0;
text-decoration:underline;
}

.info-table{
width:100%;
border-collapse:collapse;
margin-bottom:15px;
}

.info-table td{
border:1px solid #222;
padding:7px 8px;
font-size:12px;
width:25%;
}

.info-label{
font-weight:bold;
background:#f1f1f1;
}

.section-title{
font-size:15px;
font-weight:bold;
margin-top:16px;
margin-bottom:6px;
padding:7px 10px;
background:#e9ecef;
border-left:4px solid #1976d2;
}

.invoice-table{
width:100%;
border-collapse:collapse;
margin-bottom:15px;
}

.invoice-table th{
background:#1976d2;
color:#fff;
border:1px solid #222;
padding:8px 6px;
font-size:12px;
text-align:left;
}

.invoice-table td{
border:1px solid #222;
padding:7px 6px;
font-size:12px;
vertical-align:top;
}

.text-right{
text-align:right;
}

.text-center{
text-align:center;
}

.summary-table{
width:360px;
margin-left:auto;
border-collapse:collapse;
margin-top:15px;
}

.summary-table td{
border:1px solid #222;
padding:8px;
font-size:13px;
}

.summary-table td:last-child{
text-align:right;
font-weight:bold;
}

.pending-amount{
color:#dc3545;
}

.paid-amount{
color:#198754;
}

.payment-table{
width:100%;
border-collapse:collapse;
margin-top:5px;
}

.payment-table th{
background:#555;
color:#fff;
border:1px solid #222;
padding:7px;
font-size:11px;
}

.payment-table td{
border:1px solid #222;
padding:7px;
font-size:11px;
}

.footer{
margin-top:45px;
display:flex;
justify-content:space-between;
align-items:flex-end;
}

.signature{
width:220px;
text-align:center;
font-size:12px;
}

.signature-line{
border-top:1px solid #222;
margin-bottom:7px;
}

.note{
margin-top:25px;
padding-top:10px;
border-top:1px solid #aaa;
text-align:center;
font-size:11px;
font-style:italic;
}

.actions{
text-align:center;
margin-top:20px;
}

.actions button{
padding:9px 22px;
margin:4px;
border:none;
border-radius:5px;
cursor:pointer;
font-size:14px;
}

.print-btn{
background:#1976d2;
color:#fff;
}

.close-btn{
background:#6c757d;
color:#fff;
}

@media print{

@page{
size:A4;
margin:8mm;
}

body{
padding:0;
background:#fff;
}

.invoice{
width:100%;
min-height:auto;
margin:0;
padding:0;
border:none;
}

.actions{
display:none;
}

}

</style>
</head>

<body>

<div class="invoice">

<div class="header">

<div class="logo-box">
<?php
$logo_path=trim((string)($opd['hospital_logo'] ?? ''));

if($logo_path!=''){

if(
strpos($logo_path,'http://')===0 ||
strpos($logo_path,'https://')===0 ||
strpos($logo_path,'/')===0
){
$logo_src=$logo_path;
}else{
$logo_src='/'.$logo_path;
}

}else{
$logo_src='/assets/images/logo.png';
}
?>

<img
src="<?= e($logo_src) ?>"
alt="Hospital Logo"
onerror="this.onerror=null;this.src='/assets/images/logo.png';">
</div>

<div class="hospital-box">

<h1>
<?= e($opd['hospital_name']) ?>
</h1>

<p>
<?= e($opd['hospital_address']) ?>
</p>

<p>

<?php if(!empty($opd['hospital_phone'])){ ?>

Phone: <?= e($opd['hospital_phone']) ?>

<?php } ?>

<?php if(!empty($opd['hospital_mobile'])){ ?>

&nbsp; | &nbsp;
Mobile: <?= e($opd['hospital_mobile']) ?>

<?php } ?>

<?php if(!empty($opd['hospital_email'])){ ?>

&nbsp; | &nbsp;
<?= e($opd['hospital_email']) ?>

<?php } ?>

</p>

</div>

<div class="status-box">

<span class="status <?= $invoice_status=='PAID'?'status-paid':'status-pending' ?>">

<?= $invoice_status ?>

</span>

</div>

</div>

<div class="invoice-title">

OPD INVOICE

</div>

<table class="info-table">

<tr>

<td class="info-label">
OPD No.
</td>

<td>
<?= e($opd['opd_no']) ?>
</td>

<td class="info-label">
Invoice Date
</td>

<td>
<?= date('d-M-Y') ?>
</td>

</tr>

<tr>

<td class="info-label">
UHID
</td>

<td>
<?= e($opd['uhid']) ?>
</td>

<td class="info-label">
Visit Date
</td>

<td>
<?= !empty($opd['appointment_date']) ? date('d-M-Y',strtotime($opd['appointment_date'])) : '-' ?>
</td>

</tr>

<tr>

<td class="info-label">
Patient Name
</td>

<td>
<?= e($opd['patient_name']) ?>
</td>

<td class="info-label">
Mobile
</td>

<td>
<?= e($opd['patient_mobile']) ?>
</td>

</tr>

<tr>

<td class="info-label">
Doctor
</td>

<td>
<?= e($opd['doctor_name']) ?>
</td>

<td class="info-label">
Department
</td>

<td>
<?= e($opd['department_name']) ?>
</td>

</tr>

</table>

<div class="section-title">

CONSULTATION

</div>

<table class="invoice-table">

<thead>

<tr>
<th style="width:6%;">#</th>
<th>Particular</th>
<th class="text-right" style="width:18%;">Charge</th>
<th class="text-right" style="width:18%;">Received</th>
<th class="text-right" style="width:18%;">Pending</th>
</tr>

</thead>

<tbody>

<tr>

<td class="text-center">
1
</td>

<td>
Doctor Consultation
<?= !empty($opd['doctor_name']) ? ' - '.e($opd['doctor_name']) : '' ?>
</td>

<td class="text-right">
₹<?= money($consultation_total) ?>
</td>

<td class="text-right paid-amount">
₹<?= money($consultation_paid) ?>
</td>

<td class="text-right <?= $consultation_pending>0?'pending-amount':'paid-amount' ?>">
₹<?= money($consultation_pending) ?>
</td>

</tr>

</tbody>

</table>

<div class="section-title">

SERVICE BILLING

</div>

<table class="invoice-table">

<thead>

<tr>
<th style="width:5%;">#</th>
<th>Bill No.</th>
<th>Service</th>
<th class="text-center">Qty</th>
<th class="text-right">Amount</th>
<th class="text-right">Received</th>
<th class="text-right">Pending</th>
</tr>

</thead>

<tbody>

<?php
$service_counter=1;

if(!empty($service_bills)){

foreach($service_bills as $bill){

if(!empty($bill['items'])){

foreach($bill['items'] as $item){

$item_amount=(float)$item['net_amount'];

?>

<tr>

<td class="text-center">
<?= $service_counter++ ?>
</td>

<td>
<?= e($bill['bill_no']) ?>
</td>

<td>
<?= e($item['service_name'] ?: 'Service') ?>
</td>

<td class="text-center">
<?= (int)$item['qty'] ?>
</td>

<td class="text-right">
₹<?= money($item_amount) ?>
</td>

<?php
$received_display='-';
$pending_display='-';

if(count($bill['items'])===1){

$received_display='₹'.money($bill['paid_amount']);
$pending_display='₹'.money($bill['balance_amount']);

}
?>

<td class="text-right paid-amount">
<?= $received_display ?>
</td>

<td class="text-right <?= $bill['balance_amount']>0?'pending-amount':'paid-amount' ?>">
<?= $pending_display ?>
</td>

</tr>

<?php

}

}else{

?>

<tr>

<td class="text-center">
<?= $service_counter++ ?>
</td>

<td>
<?= e($bill['bill_no']) ?>
</td>

<td>
Service Bill
</td>

<td class="text-center">
-
</td>

<td class="text-right">
₹<?= money($bill['net_payable']) ?>
</td>

<td class="text-right paid-amount">
₹<?= money($bill['paid_amount']) ?>
</td>

<td class="text-right <?= $bill['balance_amount']>0?'pending-amount':'paid-amount' ?>">
₹<?= money($bill['balance_amount']) ?>
</td>

</tr>

<?php

}

}

}else{

?>

<tr>

<td colspan="7" class="text-center">
No service billing found.
</td>

</tr>

<?php } ?>

</tbody>

</table>

<table class="summary-table">

<tr>
<td>
Consultation Charges
</td>
<td>
₹<?= money($consultation_total) ?>
</td>
</tr>

<tr>
<td>
Service Charges
</td>
<td>
₹<?= money($service_total) ?>
</td>
</tr>

<tr>
<td>
<strong>Total Charges</strong>
</td>
<td>
₹<?= money($total_charge) ?>
</td>
</tr>

<tr>
<td>
Total Received
</td>
<td class="paid-amount">
₹<?= money($total_received) ?>
</td>
</tr>

<tr>
<td>
<strong>Total Pending</strong>
</td>
<td class="<?= $total_pending>0?'pending-amount':'paid-amount' ?>">
₹<?= money($total_pending) ?>
</td>
</tr>

</table>

<?php if(!empty($payments)){ ?>

<div class="section-title">

PAYMENT HISTORY

</div>

<table class="payment-table">

<thead>

<tr>
<th>#</th>
<th>Date</th>
<th>Receipt No.</th>
<th>Bill No.</th>
<th>Payment Mode</th>
<th>Transaction / Reference</th>
<th class="text-right">Amount</th>
<th class="text-right">Balance</th>
</tr>

</thead>

<tbody>

<?php
$payment_counter=1;

foreach($payments as $payment){

$reference=$payment['transaction_id'];

if(empty($reference)){
$reference=$payment['reference_no'];
}

?>

<tr>

<td>
<?= $payment_counter++ ?>
</td>

<td>
<?= !empty($payment['payment_date']) ? date('d-M-Y h:i A',strtotime($payment['payment_date'])) : '-' ?>
</td>

<td>
<?= e($payment['receipt_no']) ?>
</td>

<td>
<?= e($payment['bill_no']) ?>
</td>

<td>
<?= e($payment['payment_mode']) ?>
</td>

<td>
<?= e($reference ?: '-') ?>
</td>

<td class="text-right">
₹<?= money($payment['amount']) ?>
</td>

<td class="text-right">
₹<?= money($payment['balance_after_payment']) ?>
</td>

</tr>

<?php } ?>

</tbody>

</table>

<?php } ?>

<div class="footer">

<div class="signature">

<div class="signature-line"></div>

Prepared By

</div>

<div class="signature">

<div class="signature-line"></div>

Authorized Signatory

</div>

</div>

<div class="note">

This is a consolidated OPD invoice. Payment status is calculated from the consultation and all active OPD service bills.

</div>

<div class="actions">

<button
type="button"
class="print-btn"
onclick="window.print()">

🖨 Print Invoice

</button>

<button
type="button"
class="close-btn"
onclick="closeInvoicePopup()">

Close

</button>

</div>

</div>

<script>

function closeInvoicePopup(){

if(window.parent && window.parent!==window){

window.parent.closeBillPopup();

}else{

window.close();

}

}

</script>

</body>
</html>