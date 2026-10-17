<!--public_html/modules/billing/ipdbilling/ipd_invoice/index.php  -->
<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
session_start();
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/ipd_room_charges.php';
if(!isset($_SESSION['user_id'])){
header("Location:/login.php");
exit;
}
$search=trim($_GET['search'] ?? '');
$status_filter=trim($_GET['status'] ?? '');
function e($value){
return htmlspecialchars((string)($value ?? ''),ENT_QUOTES,'UTF-8');
}
$sql="
SELECT
ia.id AS ipd_admission_id,
ia.ipd_no,
ia.admission_date,
ia.patient_id,
ia.doctor_id,
ia.department_id,
p.uhid,
CONCAT_WS(' ',p.title,p.first_name,p.last_name) AS patient_name,
p.mobile,
u.full_name AS doctor_name,
d.department_name,

COALESCE(b.total_bills,0) AS total_bills,
COALESCE(b.total_charge,0) AS ipd_bill_amount,

COALESCE(dv.total_doctor_visit,0) AS doctor_visit_amount,

COALESCE(ph.total_pharmacy,0) AS pharmacy_amount,

COALESCE(b.total_charge,0) AS total_charge,

COALESCE(pay.total_advance,0) AS total_advance,
COALESCE(pay.total_payments,0) AS total_payments,
COALESCE(pay.total_payments,0) AS total_received

FROM ipd_admissions ia

LEFT JOIN patient_master p
ON p.id=ia.patient_id

LEFT JOIN users u
ON u.id=ia.doctor_id

LEFT JOIN department_master d
ON d.id=ia.department_id

LEFT JOIN(
    SELECT
        ipd_admission_id,
        COUNT(*) AS total_bills,
        SUM(net_amount) AS total_charge
    FROM ipd_bills
    WHERE payment_status<>'Cancelled'
    GROUP BY ipd_admission_id
)b
ON b.ipd_admission_id=ia.id

LEFT JOIN(
    SELECT
        ipd_admission_id,
        SUM(charges) AS total_doctor_visit
    FROM ipd_doctor_visits
    GROUP BY ipd_admission_id
)dv
ON dv.ipd_admission_id=ia.id

LEFT JOIN(
    SELECT
        ia2.id AS ipd_admission_id,
        SUM(ps.grand_total) AS total_pharmacy
    FROM ipd_admissions ia2
    INNER JOIN pharmacy_sales ps
    ON ps.patient_id=ia2.patient_id
    AND ps.appointment_id=ia2.appointment_id
    AND ps.patient_type='IPD'
    AND ps.status='Active'
    GROUP BY ia2.id
)ph
ON ph.ipd_admission_id=ia.id

LEFT JOIN(
    SELECT
        ipd_admission_id,
        SUM(payment_amount) AS total_payments,
        SUM(
            CASE
                WHEN ipd_bill_id IS NULL
                THEN payment_amount
                ELSE 0
            END
        ) AS total_advance
    FROM ipd_payments
    GROUP BY ipd_admission_id
)pay
ON pay.ipd_admission_id=ia.id

WHERE ia.ipd_no IS NOT NULL
AND ia.ipd_no!=''
";
$params=[];
$types="";
if($search!=''){
$sql.=" AND(
ia.ipd_no LIKE ?
OR p.uhid LIKE ?
OR p.mobile LIKE ?
OR CONCAT_WS(' ',p.title,p.first_name,p.last_name) LIKE ?
OR u.full_name LIKE ?
)";
$search_like="%".$search."%";
$params[]=$search_like;
$params[]=$search_like;
$params[]=$search_like;
$params[]=$search_like;
$params[]=$search_like;
$types.="sssss";
}

$sql.=" ORDER BY ia.admission_date DESC,ia.id DESC";
$stmt=$conn->prepare($sql);
if(!$stmt){
die("Query Error: ".$conn->error);
}
if(!empty($params)){
$stmt->bind_param($types,...$params);
}
$stmt->execute();
$result=$stmt->get_result();
$rows=[];
$total_patients=0;
$total_charge=0;
$total_received=0;
$total_pending=0;
$total_advance=0;

while($row=$result->fetch_assoc()){

    $roomCharge=calculateIPDRoomCharges(
        $conn,
        (int)$row['ipd_admission_id']
    );

    $ipdBillAmount=(float)$row['total_charge'];
    $doctorVisitAmount=(float)$row['doctor_visit_amount'];
    $pharmacyAmount=(float)$row['pharmacy_amount'];

    $currentTotalCharge=
        $ipdBillAmount+
        $doctorVisitAmount+
        $roomCharge+
        $pharmacyAmount;

    $totalReceived=(float)$row['total_received'];

    $totalPending=
        max(
            $currentTotalCharge-$totalReceived,
            0
        );

    $excessAdvance=
        max(
            $totalReceived-$currentTotalCharge,
            0
        );

    $row['room_charge_amount']=$roomCharge;
    $row['total_charge']=$currentTotalCharge;
    $row['total_pending']=$totalPending;
    $row['excess_advance']=$excessAdvance;

    $statusMatches=true;

    if($status_filter==='Paid'){

        $statusMatches=
            $totalPending<=0 &&
            $currentTotalCharge>0;

    }elseif($status_filter==='Pending'){

        $statusMatches=
            $totalPending>0;

    }elseif($status_filter==='Advance'){

        $statusMatches=
            $currentTotalCharge<=0 &&
            (float)$row['total_advance']>0;
    }

    if(!$statusMatches){
        continue;
    }

    $rows[]=$row;

    $total_patients++;
    $total_charge+=$currentTotalCharge;
    $total_received+=$totalReceived;
    $total_pending+=$totalPending;
    $total_advance+=(float)$row['total_advance'];
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>IPD Invoice</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" href="/assets/img/hms_logo.png">
<style>


.summary{
display:grid;
grid-template-columns:repeat(5,minmax(160px,1fr));
gap:15px;
margin-bottom:20px;
}
.summary-box{
background:#fff;
border-radius:10px;
padding:5px;
box-shadow:0 4px 15px rgba(0,0,0,.07);
border-left:4px solid #0b5ed7;
}
.summary-label{
font-size:12px;
color:#64748b;
}
.summary-value{
font-size:21px;
font-weight:bold;
margin-top:7px;
}
.btn-warning{
background:#f59e0b;
color:#fff;
}
.btn-success{
background:#198754;
color:#fff;
}
.btn-secondary{
background:#6b7280;
color:#fff;
}
.table-wrap{
position:relative;
width:100%;
max-width:100%;
overflow-x:auto;
overflow-y:auto;
max-height:620px;
}
.table-wrap table{
width:100%;
min-width:1400px;
border-collapse:collapse;
font-size:13px;
white-space:nowrap;
}
/* PREVENT PAGE HORIZONTAL OVERFLOW */
th,
td{
padding:9px 10px;
border:1px solid #e5e7eb;
text-align:left;
}
th{
background:#0b5ed7;
color:#fff;
position:sticky;
top:0;
z-index:2;
}
tbody tr:hover{
background:#f8fbff;
}
.text-right{
text-align:right;
}
.paid{
color:#198754;
font-weight:bold;
}
.pending{
color:#dc3545;
font-weight:bold;
}
.advance{
color:#b45309;
font-weight:bold;
}
.badge{
display:inline-block;
padding:5px 10px;
border-radius:20px;
font-size:11px;
font-weight:bold;
}
.badge-paid{
background:#d1e7dd;
color:#0f5132;
}
.badge-pending{
background:#f8d7da;
color:#842029;
}
.badge-advance{
background:#fff3cd;
color:#856404;
}
.badge-nobill{
background:#e2e8f0;
color:#334155;
}
.action-buttons{
display:flex;
gap:6px;
align-items:center;
}
.modal{
display:none;
position:fixed;
z-index:999999;
left:0;
top:0;
width:100%;
height:100%;
background:rgba(0,0,0,.55);
}
.modal-content{
position:absolute;
width:100%;
height:100%;
background:#fff;
}
.modal-header{
height:52px;
background:#0b5ed7;
color:#fff;
display:flex;
justify-content:space-between;
align-items:center;
padding:0 18px;
font-weight:bold;
}
.modal-close{
border:none;
background:#dc3545;
color:#fff;
padding:7px 14px;
border-radius:5px;
cursor:pointer;
}
.modal iframe{
width:100%;
height:calc(100% - 52px);
border:none;
}
@media(max-width:1200px){
.summary{
grid-template-columns:repeat(3,1fr);
}
}
@media(max-width:700px){
.summary{
grid-template-columns:1fr;
}
.filters input{
min-width:100%;
}
}
/* FORCE IPD INVOICE PAGE TO STAY INSIDE MAIN CONTENT */
html,
body{
max-width:100% !important;
overflow-x:hidden !important;
}

.main-content#mainContent{
width:calc(100vw - 260px) !important;
max-width:calc(100vw - 260px) !important;
min-width:0 !important;
overflow-x:hidden !important;
box-sizing:border-box !important;
}

.main-content#mainContent .page-container{
width:100% !important;
max-width:100% !important;
min-width:0 !important;
overflow-x:hidden !important;
box-sizing:border-box !important;
}

.main-content#mainContent .card{
width:100% !important;
max-width:100% !important;
min-width:0 !important;
box-sizing:border-box !important;
overflow:hidden !important;
}

.main-content#mainContent .table-wrap{
width:100% !important;
max-width:100% !important;
min-width:0 !important;
overflow-x:auto !important;
overflow-y:auto !important;
max-height:620px !important;
box-sizing:border-box !important;
}

.main-content#mainContent .table-wrap table{
width:max-content !important;
min-width:100% !important;
max-width:none !important;
white-space:nowrap !important;
border-collapse:collapse !important;
}
</style>
</head>
<body>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>
<div class="main-content" id="mainContent">
<div class="page-container">
<div class="page-top">
<div>
<h2>IPD Invoice</h2>
</div>
</div>
<div class="summary">
<div class="summary-box">
<div class="summary-label">Total IPD Patients</div>
<div class="summary-value"><?= number_format($total_patients) ?></div>
</div>
<div class="summary-box">
<div class="summary-label">Total Bill Amount</div>
<div class="summary-value">₹<?= number_format($total_charge,2) ?></div>
</div>
<div class="summary-box">
<div class="summary-label">Advance Received</div>
<div class="summary-value advance">₹<?= number_format($total_advance,2) ?></div>
</div>
<div class="summary-box">
<div class="summary-label">Total Received</div>
<div class="summary-value paid">₹<?= number_format($total_received,2) ?></div>
</div>
<div class="summary-box">
<div class="summary-label">Total Pending</div>
<div class="summary-value pending">₹<?= number_format($total_pending,2) ?></div>
</div>
</div>
<div class="card">
<form method="get" class="filters">
<input
type="text"
name="search"
placeholder="Search IPD No., UHID, Mobile, Patient or Doctor"
value="<?= e($search) ?>">
<select name="status">
<option value="">All Status</option>
<option value="Paid" <?= $status_filter==='Paid'?'selected':'' ?>>Paid</option>
<option value="Pending" <?= $status_filter==='Pending'?'selected':'' ?>>Pending</option>
<option value="Advance" <?= $status_filter==='Advance'?'selected':'' ?>>Advance Only</option>
</select>
<button type="submit" class="btn btn-primary">Search</button>
<a href="index.php" class="btn btn-secondary">Reset</a>
</form>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>#</th>
<th>IPD No.</th>
<th>UHID</th>
<th>Patient</th>
<th>Doctor</th>
<th>Department</th>
<th class="text-right">Bills</th>
<th class="text-right">Bill Amount</th>
<th class="text-right">Advance</th>
<th class="text-right">Received</th>
<th>Excess Advance</th>
<th class="text-right">Pending</th>
<th>Status</th>
<th>Generate Ledger</th>
<th>Generate Invoice</th>
<th>Pay Balance</th>
<th>Action</th>
</tr>
</thead>
<tbody>
<?php if(empty($rows)){ ?>
<tr>
<td colspan="16" style="text-align:center;padding:30px;color:#64748b;">
No IPD records found.
</td>
</tr>
<?php } ?>
<?php foreach($rows as $index=>$row){ ?>
<?php
$totalBills=(int)$row['total_bills'];
$charge=(float)$row['total_charge'];
$advanceAmount=(float)$row['total_advance'];
$pendingAmount=(float)$row['total_pending'];
if($charge<=0 && $advanceAmount>0){
$status='Advance';
$statusClass='badge-advance';
}elseif($charge>0 && $pendingAmount<=0){
$status='Paid';
$statusClass='badge-paid';
}elseif($charge>0 && $pendingAmount>0){
$status='Pending';
$statusClass='badge-pending';
}else{
$status='No Bill';
$statusClass='badge-nobill';
}
?>
<tr>
<td><?= $index+1 ?></td>
<td><b><?= e($row['ipd_no']) ?></b></td>
<td><?= e($row['uhid'] ?: '-') ?></td>
<td>
<b><?= e($row['patient_name'] ?: '-') ?></b>
<br>
<small><?= e($row['mobile'] ?: '') ?></small>
</td>
<td><?= e($row['doctor_name'] ?: '-') ?></td>
<td><?= e($row['department_name'] ?: '-') ?></td>
<td class="text-right"><?= $totalBills ?></td>
<td class="text-right">₹<?= number_format($charge,2) ?></td>
<td class="text-right advance">₹<?= number_format($advanceAmount,2) ?></td>
<td class="text-right paid">₹<?= number_format((float)$row['total_received'],2) ?></td>
<td class="text-right advance">
₹<?= number_format((float)$row['excess_advance'],2) ?>
</td>
<td class="text-right <?= $pendingAmount>0?'pending':'paid' ?>">₹<?= number_format($pendingAmount,2) ?></td>

<td>
<span class="badge <?= $statusClass ?>">
<?= $status ?>
</span>
</td>
<td>
<?php if(checkPermission('ipd_billing','add')){ ?>
<button
type="button"
class="btn btn-success"
onclick="openLedger(<?= (int)$row['ipd_admission_id'] ?>)">
Generate Ledger
</button>
<?php } ?>
</td>
<td>
<?php if(checkPermission('ipd_billing','add')){ ?>
<button
type="button"
class="btn btn-primary"
onclick="openInvoice(<?= (int)$row['ipd_admission_id'] ?>)">
Generate Invoice
</button>
<?php } ?>
</td>
<td>
<?php if($pendingAmount>0 && checkPermission('ipd_billing','add')){ ?>
<button
type="button"
class="btn btn-warning"
onclick="openPayBalance(<?= (int)$row['ipd_admission_id'] ?>)">
Pay Balance
</button>
<?php }else{ ?>
-
<?php } ?>
</td>
<td>
<button
type="button"
class="btn btn-success"
onclick="openPrintInvoice(<?= (int)$row['ipd_admission_id'] ?>)">
Print Invoice
</button>
</td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
</div>
</div>
</div>
<div class="modal" id="invoiceModal">
<div class="modal-content">
<div class="modal-header">
<span id="modalTitle">IPD Invoice</span>
<button
type="button"
class="modal-close"
onclick="closeInvoiceModal()">
✖ Close
</button>
</div>
<iframe id="invoiceFrame" src=""></iframe>
</div>
</div>
<script>
function openModal(title,url){
document.getElementById('modalTitle').innerText=title;
document.getElementById('invoiceFrame').src=url;
document.getElementById('invoiceModal').style.display='block';
document.body.style.overflow='hidden';
}
function closeInvoiceModal(){
document.getElementById('invoiceModal').style.display='none';
document.getElementById('invoiceFrame').src='';
document.body.style.overflow='auto';
window.location.reload();
}
function openInvoice(ipdAdmissionId){
openModal(
'Generate IPD Invoice',
'generate_invoice.php?ipd_admission_id='+encodeURIComponent(ipdAdmissionId)
);
}
function openLedger(ipdAdmissionId){
openModal(
'IPD Patient Ledger',
'../ipd_ledger/generate_ledger.php?ipd_admission_id='+encodeURIComponent(ipdAdmissionId)
);
}
function openPayBalance(ipdAdmissionId){
openModal(
'Pay IPD Balance',
'pay_balance.php?ipd_admission_id='+encodeURIComponent(ipdAdmissionId)
);
}
function openPrintInvoice(ipdAdmissionId){
openModal(
'Print IPD Invoice',
'print_invoice.php?ipd_admission_id='+encodeURIComponent(ipdAdmissionId)
);
}
document.getElementById('invoiceModal').addEventListener('click',function(e){
if(e.target===this){
closeInvoiceModal();
}
});
</script>
</body>
</html>