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

$user_id=(int)$_SESSION['user_id'];
$ipd_admission_id=(int)($_GET['ipd_admission_id'] ?? 0);

if($ipd_admission_id<=0){
    die('Invalid IPD Admission.');
}

/*=========================================
GET IPD DETAILS
=========================================*/

$stmt=$conn->prepare("
SELECT
    ia.id,
    ia.appointment_id,
    ia.patient_id,
    ia.ipd_no,
    ia.file_no,
    ia.hospital_id,
    ia.branch_id,
    ia.doctor_id,
    ia.department_id,
    ia.admission_date,
    ia.admission_time,
    ia.admission_type,
    ia.status,
    ia.credit_limit,
    ia.payment_type,
    ia.is_emergency,
    ia.is_vip,
    ia.is_mlc,
    p.uhid,
    p.title,
    p.first_name,
    p.last_name,
    p.sex,
    p.marital_status,
    p.mobile,
    p.phone,
    p.email,
    p.dob,
    p.age_y,
    p.age_m,
    p.age_d,
    p.address,
    u.full_name AS doctor_name,
    d.department_name
FROM ipd_admissions ia
LEFT JOIN patient_master p ON p.id=ia.patient_id
LEFT JOIN users u ON u.id=ia.doctor_id
LEFT JOIN department_master d ON d.id=ia.department_id
WHERE ia.id=?
LIMIT 1
");

$stmt->bind_param("i",$ipd_admission_id);
$stmt->execute();
$result=$stmt->get_result();

if($result->num_rows===0){
    die('IPD Admission not found.');
}

$ipd=$result->fetch_assoc();
$stmt->close();

if($ipd['status']!=='Admitted'){
    die('Billing is allowed only for an admitted IPD patient.');
}

/*=========================================
ADVANCE RECEIVED
=========================================*/

$stmt=$conn->prepare("
SELECT
    COALESCE(SUM(payment_amount),0) AS total_advance
FROM ipd_payments
WHERE ipd_admission_id=?
AND ipd_bill_id IS NULL
");

$stmt->bind_param("i",$ipd_admission_id);
$stmt->execute();

$advanceRow=$stmt->get_result()->fetch_assoc();
$totalAdvance=(float)($advanceRow['total_advance'] ?? 0);

$stmt->close();

/*=========================================
EXISTING ACTIVE BILL
=========================================*/

$stmt=$conn->prepare("
SELECT
    id,
    bill_no,
    payment_status,
    net_amount,
    paid_amount,
    balance_amount
FROM ipd_bills
WHERE ipd_admission_id=?
AND payment_status<>'Cancelled'
ORDER BY id DESC
LIMIT 1
");

$stmt->bind_param("i",$ipd_admission_id);
$stmt->execute();

$existingBill=$stmt->get_result()->fetch_assoc();
$stmt->close();

$existingBillMessage=!empty($existingBill);

/*=========================================
PATIENT NAME
=========================================*/

$patientName=trim(
    ($ipd['title'] ?? '').' '.
    ($ipd['first_name'] ?? '').' '.
    ($ipd['last_name'] ?? '')
);

/*=========================================
SERVICE CATEGORIES
=========================================*/

$categories=[];

$res=mysqli_query($conn,"
SELECT DISTINCT
    category_type
FROM service_master
WHERE status='Active'
AND category_type IS NOT NULL
AND category_type<>''
ORDER BY category_type
");

if($res){
    while($cat=mysqli_fetch_assoc($res)){
        $categories[]=$cat;
    }
}

/*=========================================
DISCOUNTS
=========================================*/

$discounts=mysqli_query($conn,"
SELECT
    id,
    discount_name,
    receipt_type,
    receipt_value
FROM discount_master
WHERE status='Active'
AND receipt_type IS NOT NULL
ORDER BY discount_name
");

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>IPD Billing</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<link rel="icon" href="/assets/img/hms_logo.png">

<style>
*{
margin:0;
padding:0;
box-sizing:border-box;
}

body{
background:#eef2f7;
font-family:Arial,Helvetica,sans-serif;
font-size:13px;
padding:16px;
color:#1e293b;
}

.wrapper{
max-width:1500px;
margin:0 auto;
}

.card{
background:#fff;
border-radius:10px;
box-shadow:0 8px 20px rgba(0,0,0,.08);
margin-bottom:14px;
overflow:hidden;
}

.card-header{
background:#1976d2;
color:#fff;
padding:10px 16px;
font-size:15px;
font-weight:bold;
display:flex;
justify-content:space-between;
align-items:center;
}

.card-header i{
margin-right:6px;
}

.card-body{
padding:14px 16px;
}

.patient-table{
width:100%;
border-collapse:collapse;
font-size:12.5px;
}

.patient-table td{
border:1px solid #e5e7eb;
padding:5px 8px;
}

.patient-table td.label{
background:#f8fafc;
font-weight:bold;
width:90px;
white-space:nowrap;
}

.emergency{
background:#dc2626;
color:#fff;
padding:4px 10px;
border-radius:15px;
font-size:11px;
font-weight:bold;
display:inline-block;
}

.normal-tag{
background:#64748b;
color:#fff;
padding:4px 10px;
border-radius:15px;
font-size:11px;
font-weight:bold;
display:inline-block;
}

.ipd-summary{
display:grid;
grid-template-columns:repeat(5,1fr);
gap:10px;
margin-top:12px;
}

.summary-card{
border:1px solid #dbe3ef;
border-radius:8px;
padding:10px;
background:#f8fafc;
}

.summary-card .label{
font-size:11px;
color:#64748b;
margin-bottom:4px;
}

.summary-card .value{
font-size:16px;
font-weight:bold;
}

.credit-value{
color:#7b1fa2;
}

.advance-value{
color:#1565c0;
}

/* SAME SERVICE UI AS OPD */
.service-layout{
display:block;
width:100%;
overflow:hidden;
margin-top:6px;
}

.service-layout::after{
content:"";
display:block;
clear:both;
}

.left-panel{
height:540px;
}

 /* ----- bottom (compact) ----- */
.bottom-grid {
    display: grid;
    grid-template-columns: 350px 340px 330px;
    gap: 14px;
    position: relative;
    top: 12px;
}

.left-panel{
background:#fff;
border-radius:10px;
box-shadow:0 5px 15px rgba(0,0,0,.08);
width:28%;
display:flex;
flex-direction:column;
float:left;
margin-right:12px;
overflow:hidden;
}

.panel-header{
background:#0d6efd;
color:#fff;
padding:8px 14px;
font-weight:bold;
font-size:13px;
display:flex;
align-items:center;
gap:6px;
margin-bottom:2px;
}

.panel-body{
padding:10px 12px;
flex:1;
display:flex;
flex-direction:column;
overflow:hidden;
max-height:94vh;
min-height:94vh;
}

.filter-group{
display:flex;
gap:8px;
margin-bottom:8px;
flex-wrap:wrap;
}

.filter-group select,
.filter-group input{
flex:1;
min-width:80px;
padding:5px 10px;
border:1px solid #d1d5db;
border-radius:6px;
font-size:12px;
background:#fff;
}

.service-list{
flex:1;
overflow-y:auto;
border:1px solid #e5e7eb;
border-radius:6px;
background:#fafcff;
}

.service-item{
display:flex;
justify-content:space-between;
align-items:center;
padding:8px 12px;
border-bottom:1px solid #eee;
cursor:default;
}

.service-item:hover{
background:#f5f8ff;
}

.service-item .info{
font-size:12.5px;
}

.service-item .info b{
font-weight:600;
}

.service-item .info small{
display:block;
color:#475569;
font-size:11px;
margin-top:1px;
}

.add-btn{
background:#28a745;
color:#fff;
border:none;
width:26px;
height:26px;
border-radius:6px;
cursor:pointer;
font-size:15px;
font-weight:bold;
display:inline-flex;
align-items:center;
justify-content:center;
transition:0.15s;
}

.add-btn:hover{
background:#1e7e34;
}

/* SAME SELECTED SERVICE UI AS OPD */

.right-panel {
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 5px 15px rgba(0,0,0,.08);
      display: flex;
      flex-direction: column;
      overflow: hidden;
      max-height: 540px;
      width: 70%;
    }
.table-wrap{
overflow:auto;
flex:1;
padding:0 4px 4px 4px;
min-height:300px;
max-height:300px;
}

#selectedServiceTable{
width:100%;
border-collapse:collapse;
font-size:12px;
min-width:780px;
}

#selectedServiceTable thead{
position:sticky;
top:0;
z-index:5;
}

#selectedServiceTable th{
background:#1976d2;
color:#fff;
padding:8px 6px;
font-weight:bold;
text-align:left;
white-space:nowrap;
border:1px solid #1a6bb0;
}

#selectedServiceTable td{
padding:5px 4px;
border-bottom:1px solid #e5e7eb;
vertical-align:middle;
background:#fff;
}

#selectedServiceTable input,
#selectedServiceTable select{
border:1px solid #d1d5db;
border-radius:4px;
padding:4px 6px;
font-size:14.5px;
background:#fff;
width:100%;
transition:0.1s;
}

#selectedServiceTable input:focus,
#selectedServiceTable select:focus{
border-color:#1976d2;
outline:none;
box-shadow:0 0 0 2px rgba(25,118,210,.15);
}

#selectedServiceTable input[readonly]{
background:#f5f5f5;
border-color:#e0e0e0;
}

.removeService{
background:#dc3545;
color:#fff;
border:none;
border-radius:4px;
width:24px;
height:24px;
cursor:pointer;
font-size:12px;
display:inline-flex;
align-items:center;
justify-content:center;
transition:0.15s;
}

.removeService:hover{
background:#b02a37;
}



.remarks-box{
background:#fff;
border-radius:10px;
box-shadow:0 5px 15px rgba(0,0,0,.08);
padding:14px 16px;
}

.remarks-box h3{
font-size:13px;
font-weight:bold;
margin-bottom:8px;
color:#0f172a;
display:flex;
align-items:center;
gap:6px;
}

.remarks-box textarea{
width:100%;
height:300px;
resize:vertical;
border:1px solid #d1d5db;
border-radius:6px;
padding:8px 10px;
font-family:Arial,sans-serif;
font-size:12px;
background:#fafcff;
}

.summary-box{
background:#fff;
border-radius:10px;
box-shadow:0 5px 15px rgba(0,0,0,.08);
padding:14px 16px;
}

.summary-box h3{
font-size:13px;
font-weight:bold;
margin-bottom:12px;
color:#0f172a;
display:flex;
align-items:center;
gap:6px;
}

.summary-grid{
display:grid;
grid-template-columns:1fr 1fr;
gap:8px 12px;
}

.form-control{
width:100%;
padding:8px;
border:1px solid #d1d5db;
border-radius:6px;
margin-top:4px;
font-size:12px;
margin-bottom:8px;
}

.summary-grid .full{
grid-column:1/-1;
}

.summary-grid label{
font-size:10px;
font-weight:bold;
color:#475569;
display:block;
margin-bottom:1px;
text-transform:uppercase;
letter-spacing:.3px;
}

.summary-grid input,
.summary-grid select{
width:100%;
padding:5px 8px;
border:1px solid #d1d5db;
border-radius:6px;
font-size:12px;
background:#fff;
}

.summary-grid input[readonly]{
background:#f5f5f5;
}

.save-btn-wrap{
grid-column:1/-1;
margin-top:10px;
text-align:center;
}

.save-btn{
background:#198754;
color:#fff;
border:none;
padding:10px 28px;
border-radius:8px;
font-weight:bold;
font-size:14px;
cursor:pointer;
transition:.15s;
display:inline-flex;
align-items:center;
gap:8px;
}

.save-btn:hover{
background:#146c43;
}

/* PAYMENT DETAILS - SAME BOX STYLE AS OPD */

.paymentFields{
display:none;
}

.credit-message{
display:none;
padding:12px;
background:#fff3cd;
border:1px solid #ffecb5;
border-radius:6px;
color:#664d03;
font-weight:bold;
font-size:12px;
margin-top:8px;
line-height:1.5;
}

/* responsive */

@media(max-width:1000px){

.service-layout{
grid-template-columns:1fr;
gap:12px;
}

.bottom-grid{
grid-template-columns:1fr;
}

.left-panel,
.right-panel{
max-height:400px;
width:100%;
float:none;
display:flex;
}

}

@media(max-width:600px){

.patient-table td{
padding:4px 6px;
font-size:11px;
}

.patient-table td.label{
width:60px;
}

.ipd-summary{
grid-template-columns:1fr 1fr;
}

}

::-webkit-scrollbar{
width:5px;
height:5px;
}

::-webkit-scrollbar-track{
background:#eef2f7;
border-radius:8px;
}

::-webkit-scrollbar-thumb{
background:#cbd5e1;
border-radius:8px;
}

</style>
</head>

<body>

<form id="billForm" method="post" action="save_bill.php">

<input type="hidden" name="ipd_admission_id" value="<?= $ipd_admission_id ?>">
<input type="hidden" name="appointment_id" value="<?= (int)$ipd['appointment_id'] ?>">
<input type="hidden" name="patient_id" value="<?= (int)$ipd['patient_id'] ?>">
<input type="hidden" name="ipd_no" value="<?= htmlspecialchars($ipd['ipd_no']) ?>">
<input type="hidden" name="hospital_id" value="<?= (int)$ipd['hospital_id'] ?>">
<input type="hidden" name="branch_id" value="<?= (int)$ipd['branch_id'] ?>">
<input type="hidden" name="doctor_id" value="<?= (int)$ipd['doctor_id'] ?>">
<input type="hidden" name="department_id" value="<?= (int)$ipd['department_id'] ?>">
<input type="hidden" name="is_emergency" value="<?= (int)$ipd['is_emergency'] ?>">
<input type="hidden" name="user_id" value="<?= $user_id ?>">

<div class="wrapper">

<!-- PATIENT DETAILS - IPD DATA -->

<div class="card">

<div class="card-header">

<span>
<i class="fas fa-hospital"></i> IPD Billing
</span>

<span>
<?= htmlspecialchars($ipd['ipd_no']) ?>
</span>

</div>

<div class="card-body">

<table class="patient-table">

<tr>

<td class="label">IPD No.</td>
<td><strong><?= htmlspecialchars($ipd['ipd_no']) ?></strong></td>

<td class="label">UHID</td>
<td><?= htmlspecialchars($ipd['uhid'] ?? '') ?></td>

<td class="label">File No.</td>
<td><?= htmlspecialchars($ipd['file_no'] ?? '') ?></td>

</tr>

<tr>

<td class="label">Patient</td>
<td><strong><?= htmlspecialchars($patientName) ?></strong></td>

<td class="label">Age</td>
<td>
<?= (int)($ipd['age_y'] ?? 0) ?>Y
<?= (int)($ipd['age_m'] ?? 0) ?>M
<?= (int)($ipd['age_d'] ?? 0) ?>D
</td>

<td class="label">Gender</td>
<td><?= htmlspecialchars($ipd['sex'] ?? '') ?></td>

</tr>

<tr>

<td class="label">Mobile</td>
<td><?= htmlspecialchars($ipd['mobile'] ?? '') ?></td>

<td class="label">Doctor</td>
<td><?= htmlspecialchars($ipd['doctor_name'] ?? '') ?></td>

<td class="label">Department</td>
<td><?= htmlspecialchars($ipd['department_name'] ?? '') ?></td>

</tr>

<tr>

<td class="label">Admission Date</td>
<td><?= date('d-m-Y',strtotime($ipd['admission_date'])) ?></td>

<td class="label">Admission Type</td>
<td><?= htmlspecialchars($ipd['admission_type'] ?? '') ?></td>

<td class="label">Emergency</td>

<td>

<?php if((int)$ipd['is_emergency']===1){ ?>

<span class="emergency">EMERGENCY</span>

<?php }else{ ?>

<span class="normal-tag">NORMAL</span>

<?php } ?>

</td>

</tr>

</table>

<div class="ipd-summary">

<div class="summary-card">

<div class="label">Credit Limit</div>

<div class="value credit-value">
₹ <?= number_format((float)$ipd['credit_limit'],2) ?>
</div>

</div>

<div class="summary-card">

<div class="label">Advance Received</div>

<div class="value advance-value">
₹ <?= number_format($totalAdvance,2) ?>
</div>

</div>

<div class="summary-card">

<div class="label">Payment Type</div>

<div class="value">
<?= htmlspecialchars($ipd['payment_type'] ?? '') ?>
</div>

</div>
<div class="summary-card">
<div class="label">Admission Status</div>
<div class="value">
<?= htmlspecialchars($ipd['status'] ?? '') ?>
</div>
</div>
</div>
</div>
</div>
<!-- AVAILABLE SERVICES -->
<div class="left-panel">
<div class="panel-header">
<i class="fas fa-list-ul"></i>
Available Services
</div>
<div class="panel-body">
<div class="filter-group">
<select id="categoryFilter" onchange="filterCategory()">
<option value="">
All Categories
</option>
<?php foreach($categories as $cat){ ?>
<option value="<?= htmlspecialchars($cat['category_type'] ?? '') ?>">
<?= htmlspecialchars($cat['category_type'] ?? '') ?>
</option>
<?php } ?>
</select>
<input
type="text"
id="serviceSearch"
placeholder="Search..."
onkeyup="filterServices">
</div>
<div class="service-list" id="service_list">
<?php

$serviceRes=mysqli_query(
$conn,
"
SELECT
id,
service_name,
amount,
category_type
FROM service_master
WHERE status='Active'
ORDER BY service_name
"
);

while($service=mysqli_fetch_assoc($serviceRes)){

?>

<div
class="service-item"
data-category="<?= htmlspecialchars($service['category_type'] ?? '') ?>">

<div class="info">

<b>
<?= htmlspecialchars($service['service_name']) ?>
</b>

<small>
₹ <?= number_format((float)$service['amount'],2) ?>
</small>

</div>

<button
type="button"
class="add-btn"
data-id="<?= (int)$service['id'] ?>"
data-name="<?= htmlspecialchars($service['service_name']) ?>"
data-charge="<?= (float)$service['amount'] ?>">

+

</button>

</div>

<?php } ?>

</div>

</div>

</div>

<!-- SELECTED SERVICES -->

<div class="right-panel">

<div class="panel-header">

<i class="fas fa-clipboard-list"></i>
Selected Services

</div>

<div class="table-wrap">

<table id="selectedServiceTable">
<thead>
<tr>
<th style="min-width:120px;">Service</th>
<th style="width:90px;">Charges</th>
<th style="width:90px;">Unit</th>
<th style="width:90px;">Emr. Chrg</th>
<th style="width:90px;">Disc. Type</th>
<th style="width:90px;">Disc %</th>
<th style="width:90px;">Disc Amt</th>
<th style="width:90px;">Net Amt</th>
<th style="width:90px;">Bal Amt</th>
<th style="width:90px;">Del</th>
</tr>
</thead>
<tbody id="selectedServicesBody">
    <?php

$stmt=$conn->prepare("
SELECT
    ps.service_id,
    sm.service_name,
    sm.amount
FROM prescribed_services ps
INNER JOIN service_master sm
    ON sm.id=ps.service_id
WHERE
    ps.appointment_id=?
AND
    ps.billing_status='Pending'
AND NOT EXISTS(
    SELECT 1
    FROM ipd_bill_items ibi
    INNER JOIN ipd_bills ib
        ON ib.id=ibi.ipd_bill_id
    WHERE
        ib.ipd_admission_id=?
    AND ibi.item_id=ps.service_id
    AND ibi.item_type='Service'
    AND ib.payment_status<>'Cancelled'
)
ORDER BY ps.id ASC
");

$stmt->bind_param(
    "ii",
    $ipd['appointment_id'],
    $ipd_admission_id
);

$stmt->execute();

$serviceResult=$stmt->get_result();

while($srv=$serviceResult->fetch_assoc()){

?>

<tr>

<td>

<input
type="hidden"
name="service_id[]"
value="<?= (int)$srv['service_id'] ?>">

<?= htmlspecialchars($srv['service_name']) ?>

</td>

<td>

<input
type="number"
class="charges"
name="charges[]"
value="<?= (float)$srv['amount'] ?>">

</td>

<td>

<input
type="number"
class="unit"
name="unit[]"
value="1"
min="1">

</td>

<td>

<input
type="number"
class="emr_charge"
name="emr_charge[]"
value="0">

</td>

<td>

<select
class="discount_type"
name="discount_type[]">

<option value="None">
None
</option>

<option value="%">
%
</option>

<option value="Flat">
Flat
</option>

</select>

</td>

<td>

<input
type="number"
class="discount_percent"
name="discount_percent[]"
value="0">

</td>

<td>

<input
type="number"
class="discount_amount"
name="discount_amount[]"
value="0">

</td>

<td>

<input
type="number"
class="net_amount"
name="net_amount[]"
value="<?= (float)$srv['amount'] ?>"
readonly>
</td>
<td>
<input
type="number"
class="balance_amount"
name="balance_amount[]"
value="<?= (float)$srv['amount'] ?>"
readonly>
</td>
<td>
<button
type="button"
class="removeService">
✖
</button>
</td>
</tr>
<?php
}
$stmt->close();
?>
</tbody>
</table>
</div>
</div>
<!-- SAME THREE COLUMN BOTTOM UI AS OPD -->

<div class="bottom-grid">

<!-- REMARKS -->

<div class="remarks-box">

<h3>
<i class="far fa-sticky-note"></i>
Remarks
</h3>

<textarea
name="remarks"
id="remarks"
placeholder="Add notes..."></textarea>

</div>

<!-- PAYMENT DETAILS -->

<div class="remarks-box">

<h3>
<i class="fas fa-money-check-alt"></i>
Payment Details
</h3>

<div style="margin-bottom:10px;">

<label style="font-size:12px;font-weight:bold;">
Receipt Date
</label>

<input
type="datetime-local"
name="receipt_date"
required
class="form-control"
value="<?= date('Y-m-d\TH:i') ?>">

</div>

<div style="margin-bottom:10px;">

<label style="font-size:12px;font-weight:bold;">
Payment Mode
</label>

<select
id="payment_mode"
name="payment_mode"
style="width:100%;padding:8px;"
onchange="togglePaymentFields()">

<option
value="Cash"
<?= (($ipd['payment_type'] ?? '')==='Cash') ? 'selected' : '' ?>>
Cash
</option>

<option value="UPI">UPI</option>
<option value="Card">Card</option>
<option value="Cheque">Cheque</option>
<option value="NEFT">NEFT</option>

<option
value="Credit"
<?= (($ipd['payment_type'] ?? '')==='Credit') ? 'selected' : '' ?>>
Credit (Due)
</option>

<option value="ECS">ECS</option>

</select>

</div>

<div id="creditMessage" class="credit-message">

<i class="fas fa-info-circle"></i>

Patient is on Credit. No payment options are required at this time.

</div>

<!-- CASH -->

<div id="cashFields" class="paymentFields">

<label style="font-size:12px;font-weight:bold;">
Remarks
</label>

<input
type="text"
name="cash_remarks"
class="form-control"
placeholder="Cash Remarks">

</div>

<!-- UPI -->

<div id="upiFields" class="paymentFields">

<label style="font-size:12px;font-weight:bold;">
UPI App
</label>

<select name="upi_app" class="form-control">

<option value="">Select</option>
<option>PhonePe</option>
<option>Google Pay</option>
<option>Paytm</option>
<option>BHIM</option>
<option>Other</option>

</select>

<label style="margin-top:8px;font-size:12px;font-weight:bold;">
Transaction ID
</label>

<input
type="text"
name="upi_txn_id"
class="form-control">

</div>

<!-- CARD -->

<div id="cardFields" class="paymentFields">

<label style="font-size:12px;font-weight:bold;">
Card Type
</label>

<select name="card_type" class="form-control">

<option value="">
Select Card Type
</option>

<option value="Credit Card">
Credit Card
</option>

<option value="Debit Card">
Debit Card
</option>

</select>

<label style="margin-top:8px;font-size:12px;font-weight:bold;">
Bank Name
</label>

<input
type="text"
name="card_bank_name"
class="form-control"
placeholder="Enter Bank Name">

<label style="margin-top:8px;font-size:12px;font-weight:bold;">
Last 4 Digits
</label>

<input
type="text"
name="card_last4"
class="form-control"
maxlength="4"
placeholder="1234">

<label style="margin-top:8px;font-size:12px;font-weight:bold;">
Approval / Auth Code
</label>

<input
type="text"
name="card_auth_code"
class="form-control"
placeholder="POS Approval Code">

</div>

<!-- CHEQUE -->

<div id="chequeFields" class="paymentFields">

<label style="font-size:12px;font-weight:bold;">
Cheque No.
</label>

<input
type="text"
name="cheque_no"
class="form-control">

<label style="margin-top:8px;font-size:12px;font-weight:bold;">
Bank Name
</label>

<input
type="text"
name="cheque_bank"
class="form-control">

<label style="margin-top:8px;font-size:12px;font-weight:bold;">
Cheque Date
</label>

<input
type="date"
name="cheque_date"
class="form-control">

</div>

<!-- NEFT -->

<div id="neftFields" class="paymentFields">

<label style="font-size:12px;font-weight:bold;">
Reference No.
</label>

<input
type="text"
name="neft_ref"
class="form-control">

<label style="margin-top:8px;font-size:12px;font-weight:bold;">
Bank Name
</label>

<input
type="text"
name="neft_bank"
class="form-control">

</div>

<!-- ECS -->

<div id="ecsFields" class="paymentFields">

<label style="font-size:12px;font-weight:bold;">
Bank Name
</label>

<input
type="text"
name="ecs_bank_name"
class="form-control"
placeholder="Enter Bank Name">

<label style="margin-top:8px;font-size:12px;font-weight:bold;">
Reference No.
</label>

<input
type="text"
name="ecs_reference_no"
class="form-control"
placeholder="Enter ECS Reference No.">

<label style="margin-top:8px;font-size:12px;font-weight:bold;">
Transaction Date
</label>

<input
type="date"
name="ecs_transaction_date"
class="form-control">

</div>

</div>

<!-- BILLING SUMMARY -->

<div class="summary-box">

<h3>
<i class="fas fa-calculator"></i>
Billing Summary
</h3>

<div class="summary-grid">

<div class="full">

<label>
Total
</label>

<input
type="number"
id="grand_total"
name="grand_total"
value="0"
readonly>

</div>

<div>

<label>
Discount
</label>

<select
id="bill_discount_master"
name="bill_discount_master">

<option value="">
No Discount
</option>

<?php while($d=mysqli_fetch_assoc($discounts)){ ?>

<option
value="<?= $d['id'] ?>"
data-name="<?= htmlspecialchars($d['discount_name']) ?>"
data-type="<?= htmlspecialchars($d['receipt_type']) ?>"
data-value="<?= $d['receipt_value'] ?>">

<?= htmlspecialchars($d['discount_name']) ?>

</option>

<?php } ?>

</select>

</div>

<div>

<label>
Disc Amt
</label>

<input
type="number"
id="bill_discount_amount"
name="bill_discount_amount"
value="0"
readonly>

</div>

<div class="full">

<label>
Net Payable
</label>

<input
type="number"
id="net_payable"
name="net_payable"
value="0"
readonly>

</div>

<div>

<label>
Received
</label>

<input
type="number"
id="received_amount"
name="received_amount"
value="0"
min="0"
step="0.01">

</div>

<div>

<label>
Balance
</label>

<input
type="number"
id="balance_amount"
name="balance_amount"
value="0"
readonly>

</div>

<div class="full">

<label>
Paid Amount
</label>

<input
type="number"
id="paid_amount"
name="paid_amount"
value="0"
readonly>

</div>

<div class="save-btn-wrap">

<button
type="button"
id="saveBill"
class="save-btn">

<i class="fas fa-save"></i>
Save Bill

</button>

</div>

</div>

</div>

</div>

</div>

</form>

<script>

/*=========================================
SERVICE FILTER
=========================================*/

function filterServices(){

let input=
document
.getElementById('serviceSearch')
.value
.toLowerCase();

document
.querySelectorAll('.service-item')
.forEach(function(item){

let txt=
item.innerText.toLowerCase();

item.style.display=
txt.indexOf(input)>-1
?'flex'
:'none';

});

}

function filterCategory(){

let cat=
document
.getElementById('categoryFilter')
.value;

document
.querySelectorAll('.service-item')
.forEach(function(item){

let match=
cat==='' ||
item.dataset.category===cat;

item.style.display=
match
?'flex'
:'none';

});

}

/*=========================================
ROW CALCULATION
=========================================*/

function calculateRow(row){

let charge=
parseFloat(
row.querySelector('.charges')?.value
)||0;

let qty=
parseFloat(
row.querySelector('.unit')?.value
)||1;

let emr=
parseFloat(
row.querySelector('.emr_charge')?.value
)||0;

let type=
row.querySelector('.discount_type')?.value
||'None';

let discPercent=
parseFloat(
row.querySelector('.discount_percent')?.value
)||0;

let discAmount=
parseFloat(
row.querySelector('.discount_amount')?.value
)||0;

let total=
(charge*qty)+emr;

if(type==='%'){

discAmount=
(total*discPercent)/100;

row.querySelector(
'.discount_amount'
).value=
discAmount.toFixed(2);

}else if(type==='Flat'){

discPercent=0;

row.querySelector(
'.discount_percent'
).value=0;

}else{

discPercent=0;
discAmount=0;

row.querySelector(
'.discount_percent'
).value=0;

row.querySelector(
'.discount_amount'
).value=0;

}

if(discAmount>total){
discAmount=total;
}

let net=
total-discAmount;

if(net<0){
net=0;
}

row.querySelector(
'.net_amount'
).value=
net.toFixed(2);

row.querySelector(
'.balance_amount'
).value=
net.toFixed(2);

}

/*=========================================
BILL CALCULATION
=========================================*/

function calculateBill(){

let grandTotal=0;

document
.querySelectorAll(
'#selectedServicesBody tr'
)
.forEach(function(row){

calculateRow(row);

grandTotal+=
parseFloat(
row.querySelector(
'.net_amount'
)?.value
)||0;

});

document
.getElementById(
'grand_total'
).value=
grandTotal.toFixed(2);

let discount=
document.getElementById(
'bill_discount_master'
);

let selected=
discount.options[
discount.selectedIndex
];

let discType=
selected?.dataset.type||'';

let discValue=
parseFloat(
selected?.dataset.value||0
);

let discAmount=0;

if(discType==='Percentage'){

discAmount=
(grandTotal*discValue)/100;

}else if(discType==='Flat'){

discAmount=discValue;

}

if(discAmount>grandTotal){
discAmount=grandTotal;
}

document
.getElementById(
'bill_discount_amount'
).value=
discAmount.toFixed(2);

let net=
grandTotal-discAmount;

if(net<0){
net=0;
}

document
.getElementById(
'net_payable'
).value=
net.toFixed(2);

let received=
parseFloat(
document
.getElementById(
'received_amount'
).value
)||0;

if(received<0){
received=0;
}

if(received>net){

received=net;

document
.getElementById(
'received_amount'
).value=
received.toFixed(2);

}

let balance=
net-received;

if(balance<0){
balance=0;
}

document
.getElementById(
'balance_amount'
).value=
balance.toFixed(2);

document
.getElementById(
'paid_amount'
).value=
received.toFixed(2);

}

/*=========================================
ADD SERVICE
=========================================*/

document.addEventListener(
'click',
function(e){

let btn=
e.target.closest('.add-btn');

if(!btn){
return;
}

let id=btn.dataset.id;
let name=btn.dataset.name;
let charge=
parseFloat(btn.dataset.charge)||0;

let tbody=
document.getElementById(
'selectedServicesBody'
);

let existing=
Array.from(
tbody.querySelectorAll(
'input[name="service_id[]"]'
)
).find(function(input){

return input.value==id;

});

if(existing){

let row=
existing.closest('tr');

let unitInput=
row.querySelector('.unit');

unitInput.value=
parseInt(
unitInput.value||1
)+1;

calculateRow(row);
calculateBill();

return;

}

let html=`
<tr>

<td>

<input
type="hidden"
name="service_id[]"
value="${id}">

${name}

</td>

<td>

<input
type="number"
class="charges"
name="charges[]"
value="${charge.toFixed(2)}"
style="width:90px;"
min="0"
step="0.01">

</td>

<td>

<input
type="number"
class="unit"
name="unit[]"
value="1"
min="1"
style="width:48px;">

</td>

<td>

<input
type="number"
class="emr_charge"
name="emr_charge[]"
value="0"
style="width:90px;"
min="0"
step="0.01">

</td>

<td>

<select
class="discount_type"
name="discount_type[]"
style="width:85px;">

<option value="None">
None
</option>

<option value="%">
%
</option>

<option value="Flat">
Flat
</option>

</select>

</td>

<td>

<input
type="number"
class="discount_percent"
name="discount_percent[]"
value="0"
style="width:55px;"
min="0"
step="0.01">

</td>

<td>

<input
type="number"
class="discount_amount"
name="discount_amount[]"
value="0"
style="width:90px;"
min="0"
step="0.01">

</td>

<td>

<input
type="number"
class="net_amount"
name="net_amount[]"
value="${charge.toFixed(2)}"
readonly
style="width:70px;background:#f5f5f5;">

</td>

<td>

<input
type="number"
class="balance_amount"
name="balance_amount[]"
value="${charge.toFixed(2)}"
readonly
style="width:70px;background:#f5f5f5;">

</td>

<td>

<button
type="button"
class="removeService">

<i class="fas fa-times"></i>

</button>

</td>

</tr>
`;

tbody.insertAdjacentHTML(
'beforeend',
html
);

let newRow=
tbody.lastElementChild;

calculateRow(newRow);
calculateBill();

}
);

/*=========================================
REMOVE SERVICE
=========================================*/

document.addEventListener(
'click',
function(e){

if(
!e.target.closest('.removeService')
){
return;
}

if(confirm('Remove this service?')){

e.target
.closest('tr')
.remove();

calculateBill();

}

}
);

/*=========================================
INPUT EVENTS
=========================================*/

document.addEventListener(
'input',
function(e){

if(
e.target.classList.contains('charges') ||
e.target.classList.contains('unit') ||
e.target.classList.contains('emr_charge') ||
e.target.classList.contains('discount_percent') ||
e.target.classList.contains('discount_amount')
){

let row=
e.target.closest('tr');

if(row){
calculateRow(row);
calculateBill();
}

}

if(
e.target.id==='received_amount'
){

calculateBill();

}

}
);

/*=========================================
CHANGE EVENTS
=========================================*/

document.addEventListener(
'change',
function(e){

if(
e.target.classList.contains('discount_type')
){

let row=
e.target.closest('tr');

if(row){
calculateRow(row);
calculateBill();
}

}

if(
e.target.id==='bill_discount_master'
){

calculateBill();

}

}
);

/*=========================================
PAYMENT MODE
=========================================*/

function togglePaymentFields(){

document
.querySelectorAll('.paymentFields')
.forEach(function(div){

div.style.display='none';

});

let mode=
document
.getElementById('payment_mode')
.value;

let creditMessage=
document
.getElementById('creditMessage');

creditMessage.style.display='none';

if(mode==='Cash'){

document
.getElementById('cashFields')
.style.display='block';

}

if(mode==='UPI'){

document
.getElementById('upiFields')
.style.display='block';

}

if(mode==='Card'){

document
.getElementById('cardFields')
.style.display='block';

}

if(mode==='Cheque'){

document
.getElementById('chequeFields')
.style.display='block';

}

if(mode==='NEFT'){

document
.getElementById('neftFields')
.style.display='block';

}

if(mode==='ECS'){

document
.getElementById('ecsFields')
.style.display='block';

}

if(mode==='Credit'){

creditMessage.style.display='block';

}

let receivedField=
document.getElementById(
'received_amount'
);

if(mode==='Credit'){

receivedField.value='0';
receivedField.readOnly=true;

calculateBill();

}else{

receivedField.readOnly=false;

}

}

/*=========================================
SAVE BILL
=========================================*/

document
.getElementById('saveBill')
.addEventListener(
'click',
function(){

calculateBill();

let rows=
document.querySelectorAll(
'#selectedServicesBody tr'
);

if(rows.length===0){

alert(
'Please add at least one service.'
);

return;

}

let receivedAmount=
parseFloat(
document
.getElementById(
'received_amount'
).value
)||0;

let netPayable=
parseFloat(
document
.getElementById(
'net_payable'
).value
)||0;

let paymentMode=
document
.getElementById(
'payment_mode'
).value;

if(receivedAmount<0){

alert(
"Received Amount can't be less than 0."
);

return;

}

if(
paymentMode!=='Credit' &&
receivedAmount<=0
){

alert(
'Received Amount should be greater than 0.'
);

document
.getElementById(
'received_amount'
)
.focus();

return;

}

if(receivedAmount>netPayable){

alert(
"Received Amount can't be greater than Net Payable."
);

document
.getElementById(
'received_amount'
)
.focus();

return;

}

if(
paymentMode==='Credit' &&
receivedAmount>0
){

alert(
'Credit mode should not have a received amount.'
);

return;

}

if(netPayable<=0){

alert(
'Bill amount must be greater than zero.'
);

return;

}

if(!confirm('Save this IPD Bill?')){

return;

}

this.disabled=true;

this.innerHTML=
'<i class="fas fa-spinner fa-spin"></i> Saving...';

document
.getElementById(
'billForm'
)
.submit();

});

/*=========================================
INITIAL
=========================================*/

togglePaymentFields();
calculateBill();

</script>

</body>
</html>