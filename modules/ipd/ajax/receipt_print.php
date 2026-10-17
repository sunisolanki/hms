<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

session_start();

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$payment_id=(int)($_GET['payment_id'] ?? 0);

if($payment_id<=0){
die('Invalid receipt.');
}

/* GET PAYMENT + IPD + PATIENT DETAILS */

$stmt=$conn->prepare("
SELECT
p.id AS payment_id,
p.payment_no,
p.payment_date,
p.payment_amount,
p.payment_type,
p.payment_mode,
p.transaction_no,
p.payment_details,
p.remarks AS payment_remarks,

b.id AS bill_id,
b.bill_no,

a.id AS ipd_admission_id,
a.ipd_no,
a.admission_date,

pat.id AS patient_id,
CONCAT_WS(' ',pat.first_name,pat.last_name) AS patient_name,
pat.age_y AS patient_age,
pat.sex AS patient_gender,
pat.mobile AS patient_mobile,

hm.hospital_name,
hm.hospital_code,
hm.address AS hospital_address,
hm.mobile AS hospital_mobile,
hm.phone AS hospital_phone,

bm.branch_name,
bm.branch_code,
bm.address AS branch_address,
bm.mobile AS branch_mobile

FROM ipd_payments p

INNER JOIN ipd_bills b
ON b.id=p.ipd_bill_id

INNER JOIN ipd_admissions a
ON a.id=p.ipd_admission_id

LEFT JOIN patient_master pat
ON pat.id=p.patient_id

LEFT JOIN hospital_master hm
ON hm.id=a.hospital_id

LEFT JOIN branch_master bm
ON bm.id=a.branch_id

WHERE p.id=?

LIMIT 1
");

if(!$stmt){
die('Database error: '.$conn->error);
}

$stmt->bind_param("i",$payment_id);
$stmt->execute();

$result=$stmt->get_result();
$receipt=$result->fetch_assoc();

$stmt->close();

if(!$receipt){
die('Receipt not found.');
}

/* GET ALL SERVICES / CHARGES OF THIS RECEIPT */

$items=[];

$stmt=$conn->prepare("
SELECT
bi.id,
bi.item_type,
bi.item_name,
bi.quantity,
bi.rate,
bi.amount,
bi.discount_amount,
bi.net_amount

FROM ipd_payment_items pi

INNER JOIN ipd_bill_items bi
ON bi.id=pi.ipd_bill_item_id

WHERE pi.payment_id=?

ORDER BY pi.id ASC
");

if(!$stmt){
die('Item query error: '.$conn->error);
}

$stmt->bind_param("i",$payment_id);
$stmt->execute();

$result=$stmt->get_result();

$receipt_total=0;

while($row=$result->fetch_assoc()){

$items[]=$row;

$receipt_total+=(float)($row['net_amount'] ?? 0);

}

$stmt->close();

/*
CURRENT RECEIPT BALANCE

Example:
Selected Services = ₹300
Received Amount = ₹100

Receipt Balance = ₹200
*/

$received_amount=(float)($receipt['payment_amount'] ?? 0);

$receipt_balance=max(
0,
$receipt_total-$received_amount
);

$patient_name=$receipt['patient_name'] ?? '';
$patient_age=$receipt['patient_age'] ?? '';
$patient_gender=$receipt['patient_gender'] ?? '';
$patient_mobile=$receipt['patient_mobile'] ?? '';

$hospital_name=$receipt['hospital_name'] ?? 'Hospital';
$branch_name=$receipt['branch_name'] ?? '';

$hospital_address=
!empty($receipt['branch_address'])
? $receipt['branch_address']
: ($receipt['hospital_address'] ?? '');

$hospital_mobile=
!empty($receipt['branch_mobile'])
? $receipt['branch_mobile']
: ($receipt['hospital_mobile'] ?? '');

$hospital_phone=$receipt['hospital_phone'] ?? '';
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">

<title>
IPD Payment Receipt - <?= htmlspecialchars($receipt['payment_no'] ?? '') ?>
</title>

<style>

*{
box-sizing:border-box;
}

body{
margin:0;
padding:20px;
font-family:Arial,sans-serif;
background:#f5f5f5;
color:#222;
}

.receipt-container{
width:850px;
max-width:100%;
margin:auto;
background:#fff;
border:1px solid #ccc;
padding:25px;
}

.receipt-header{
display:flex;
justify-content:space-between;
align-items:center;
border-bottom:2px solid #1976d2;
padding-bottom:15px;
margin-bottom:15px;
}

.hospital-info h1{
margin:0 0 5px;
font-size:24px;
color:#1976d2;
}

.hospital-info p{
margin:3px 0;
font-size:13px;
}

.receipt-title{
text-align:right;
}

.receipt-title h2{
margin:0;
color:#1976d2;
font-size:22px;
}

.receipt-title p{
margin:5px 0;
font-size:13px;
}

.section{
margin-top:18px;
}

.section-title{
background:#eef6ff;
color:#1976d2;
font-weight:bold;
padding:8px 10px;
border-left:4px solid #1976d2;
margin-bottom:10px;
}

.info-grid{
display:grid;
grid-template-columns:1fr 1fr;
gap:8px 30px;
font-size:14px;
}

.info-row{
display:flex;
border-bottom:1px dotted #ddd;
padding:5px 0;
}

.info-label{
font-weight:bold;
width:145px;
flex-shrink:0;
}

.service-table{
width:100%;
border-collapse:collapse;
font-size:14px;
}

.service-table th{
background:#1976d2;
color:#fff;
padding:9px;
text-align:left;
}

.service-table td{
padding:9px;
border-bottom:1px solid #ddd;
}

.service-table th:nth-child(3),
.service-table th:nth-child(4),
.service-table th:nth-child(5),
.service-table td:nth-child(3),
.service-table td:nth-child(4),
.service-table td:nth-child(5){
text-align:right;
}

.amount-box{
margin-top:20px;
border:1px solid #1976d2;
background:#f8fbff;
}

.amount-row{
display:flex;
justify-content:space-between;
padding:12px 15px;
border-bottom:1px solid #dcecff;
font-size:15px;
}

.amount-row:last-child{
border-bottom:none;
}

.received-amount{
background:#1976d2;
color:#fff;
font-size:18px;
font-weight:bold;
}

.balance-amount{
background:#fff7ed;
color:#c2410c;
font-weight:bold;
font-size:16px;
}

.remarks{
margin-top:20px;
font-size:14px;
}

.signature-area{
margin-top:60px;
display:flex;
justify-content:space-between;
font-size:14px;
}

.signature-box{
width:200px;
text-align:center;
border-top:1px solid #555;
padding-top:7px;
}

.no-print{
text-align:center;
margin-bottom:20px;
}

.print-btn{
background:#1976d2;
color:#fff;
border:none;
padding:10px 25px;
border-radius:5px;
font-size:14px;
cursor:pointer;
}

@media print{

body{
background:#fff;
padding:0;
}

.no-print{
display:none;
}

.receipt-container{
width:100%;
border:none;
padding:10px;
}

}

</style>
</head>

<body>

<div class="no-print">

<button
type="button"
class="print-btn"
onclick="window.print()">

Print Receipt

</button>

</div>

<div class="receipt-container">

<div class="receipt-header">

<div class="hospital-info">

<h1><?= htmlspecialchars($hospital_name) ?></h1>

<?php if($branch_name!=''){ ?>

<p>
<strong><?= htmlspecialchars($branch_name) ?></strong>
</p>

<?php } ?>

<?php if($hospital_address!=''){ ?>

<p>
<?= nl2br(htmlspecialchars($hospital_address)) ?>
</p>

<?php } ?>

<?php if($hospital_mobile!='' || $hospital_phone!=''){ ?>

<p>
Phone:
<?= htmlspecialchars(trim($hospital_mobile.' '.$hospital_phone)) ?>
</p>

<?php } ?>

</div>

<div class="receipt-title">

<h2>IPD PAYMENT RECEIPT</h2>

<p>
<strong>Receipt No:</strong>
<?= htmlspecialchars($receipt['payment_no'] ?? '') ?>
</p>

<p>
<strong>Date:</strong>
<?= !empty($receipt['payment_date']) ? date('d-m-Y',strtotime($receipt['payment_date'])) : '' ?>
</p>

</div>

</div>

<div class="section">

<div class="section-title">
Patient Details
</div>

<div class="info-grid">

<div class="info-row">

<div class="info-label">
Patient Name
</div>

<div>
<?= htmlspecialchars($patient_name) ?>
</div>

</div>

<div class="info-row">

<div class="info-label">
IPD No.
</div>

<div>
<?= htmlspecialchars($receipt['ipd_no'] ?? '') ?>
</div>

</div>

<div class="info-row">

<div class="info-label">
Age / Gender
</div>

<div>

<?= htmlspecialchars((string)$patient_age) ?>

<?= $patient_age!=='' && $patient_gender!=='' ? ' / ' : '' ?>

<?= htmlspecialchars($patient_gender) ?>

</div>

</div>

<div class="info-row">

<div class="info-label">
Mobile
</div>

<div>
<?= htmlspecialchars($patient_mobile) ?>
</div>

</div>

<div class="info-row">

<div class="info-label">
Bill No.
</div>

<div>
<?= htmlspecialchars($receipt['bill_no'] ?? '') ?>
</div>

</div>

<div class="info-row">

<div class="info-label">
Admission Date
</div>

<div>

<?= !empty($receipt['admission_date']) ? date('d-m-Y',strtotime($receipt['admission_date'])) : '' ?>

</div>

</div>

</div>

</div>

<div class="section">

<div class="section-title">
Service / Charge Details
</div>

<?php if(count($items)>0){ ?>

<table class="service-table">

<thead>

<tr>

<th style="width:60px;">
#
</th>

<th>
Service / Charge
</th>

<th>
Qty
</th>

<th>
Rate
</th>

<th>
Amount
</th>

</tr>

</thead>

<tbody>

<?php foreach($items as $index=>$item){ ?>

<tr>

<td>
<?= $index+1 ?>
</td>

<td>

<strong>
<?= htmlspecialchars($item['item_name'] ?? '') ?>
</strong>

<?php if(!empty($item['item_type'])){ ?>

<br>

<small>
<?= htmlspecialchars($item['item_type']) ?>
</small>

<?php } ?>

</td>

<td>
<?= number_format((float)($item['quantity'] ?? 0),2) ?>
</td>

<td>
₹ <?= number_format((float)($item['rate'] ?? 0),2) ?>
</td>

<td>
₹ <?= number_format((float)($item['net_amount'] ?? 0),2) ?>
</td>

</tr>

<?php } ?>

</tbody>

</table>

<?php }else{ ?>

<div style="padding:15px;text-align:center;border:1px solid #ddd;">
No services or charges linked with this receipt.
</div>

<?php } ?>

</div>

<div class="section">

<div class="section-title">
Payment Details
</div>

<div class="info-grid">

<div class="info-row">

<div class="info-label">
Payment Type
</div>

<div>
<?= htmlspecialchars($receipt['payment_type'] ?? '') ?>
</div>

</div>

<div class="info-row">

<div class="info-label">
Payment Mode
</div>

<div>
<?= htmlspecialchars($receipt['payment_mode'] ?? '') ?>
</div>

</div>

<?php if(!empty($receipt['transaction_no'])){ ?>

<div class="info-row">

<div class="info-label">
Transaction No.
</div>

<div>
<?= htmlspecialchars($receipt['transaction_no']) ?>
</div>

</div>

<?php } ?>

</div>

</div>

<div class="amount-box">

<div class="amount-row">

<span>
Selected Services Total
</span>

<strong>
₹ <?= number_format($receipt_total,2) ?>
</strong>

</div>

<div class="amount-row received-amount">

<span>
Received Amount
</span>

<strong>
₹ <?= number_format($received_amount,2) ?>
</strong>

</div>

<div class="amount-row balance-amount">

<span>
Balance Amount
</span>

<strong>
₹ <?= number_format($receipt_balance,2) ?>
</strong>

</div>

</div>

<?php if(!empty($receipt['payment_details']) || !empty($receipt['payment_remarks'])){ ?>

<div class="remarks">

<div class="section-title">
Remarks
</div>

<?php if(!empty($receipt['payment_details'])){ ?>

<p>

<strong>
Payment Details:
</strong>

<?= nl2br(htmlspecialchars($receipt['payment_details'])) ?>

</p>

<?php } ?>

<?php if(!empty($receipt['payment_remarks'])){ ?>

<p>

<strong>
Remarks:
</strong>

<?= nl2br(htmlspecialchars($receipt['payment_remarks'])) ?>

</p>

<?php } ?>

</div>

<?php } ?>

<div class="signature-area">

<div class="signature-box">
Received By
</div>

<div class="signature-box">
Authorized Signature
</div>

</div>

</div>

<script>

window.onload=function(){

setTimeout(function(){

window.print();

},300);

};

</script>

</body>
</html>