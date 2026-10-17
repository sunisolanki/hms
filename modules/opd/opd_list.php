<?php
session_start();
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
/* =========================
   DATE PAGINATION LOGIC
========================= */
$selected_date = $_GET['date'] ?? date('Y-m-d');
$prev_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($selected_date . ' +1 day'));
ini_set('display_errors', 1);
error_reporting(E_ALL);
if (!isset($_SESSION['user_id'])) {
    header('Location: /login/login.php');
    exit;
}
$current_user_id = (int)$_SESSION['user_id'];

$isDoctor = false;

$docChk = $conn->prepare("
SELECT id
FROM doctor_master
WHERE user_id = ?
LIMIT 1
");
$docChk->bind_param("i", $current_user_id);
$docChk->execute();
$isDoctor = $docChk->get_result()->num_rows > 0;
$docChk->close();
function allowedStatusOptions($current)
{
    if ($current == 'Payment Pending') {
        return ['Confirmed','Cancel'];
    }
    if ($current == 'Partial Paid') {
        return ['Confirmed','Refund','Cancel'];
    }
    if ($current == 'Confirmed') {
        return ['Registered','Refund','Cancel'];
    }
    if ($current == 'Registered') {
    return [
        'Prescription',
        'Admit Advice',
        'Admit',
        'Done',
        'Refund',
        'Cancel'
    ];
}
if ($current == 'Admit Advice') {
    return [
        'Admit'
    ];
}
if ($current == 'Admitted') {
    return [];
}
    if ($current == 'Refund') {
        return ['Prescription','Done'];
    }
    if ($current == 'Cancel') {
        return [];
    }
    return [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
    <title>OPD List</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
    <style>
.table-wrap{
    background:#fff;
    border-radius:10px;
    padding:1px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
    overflow:auto;
    height: 620px;
}
table{
    width:100%;
    border-collapse:collapse;
    font-size:13px;
    white-space:nowrap;
}
th, td{
    border:1px solid #e5e7eb;
    padding:1px 10px;
    text-align:left;
}
th{
    background:#1976d2;
    padding:8px 10px;
    color:#fff;
    position:sticky;
    top:0;
}
.action-dropdown {
    position: relative;
    display: inline-block;
}
.action-menu {
    display: none;
    position: absolute;
    left: 0;
    background: #fff;
    min-width: 220px;
    box-shadow: 0 8px 16px rgba(0,0,0,0.15);
    border-radius: 6px;
    z-index: 999;
}
.action-menu a {
    display: block;
    padding: 8px 12px;
    text-decoration: none;
    color: #333;
    font-size: 13px;
}
.action-menu a:hover {
    background: #f2f2f2;
}
/* ===== REFUND POPUP ===== */
.refund-modal{
display:none;
position:fixed;
inset:0;
z-index:999999;
align-items:center;
justify-content:center;
background:rgba(15,23,42,.55);
backdrop-filter:blur(7px);
-webkit-backdrop-filter:blur(7px);
}
.refund-modal-box{
position:relative;
width:100%;
max-width:440px;
margin:20px;
background:#fff;
border-radius:12px;
overflow:hidden;
box-shadow:0 20px 50px rgba(0,0,0,.25);
border:1px solid #d1d5db;
}
.refund-modal-header{
display:flex;
align-items:center;
justify-content:space-between;
padding:12px 16px;
background:#1976d2;
color:#fff;
}
.refund-modal-header h3{
margin:0;
color:#fff;
font-size:16px;
font-weight:bold;
}
.refund-modal-close{
width:36px;
height:36px;
display:flex;
align-items:center;
justify-content:center;
padding:0;
border:1px solid rgba(255,255,255,.35);
border-radius:50%;
background:rgba(255,255,255,.14);
color:#fff;
font-size:20px;
font-weight:400;
line-height:1;
cursor:pointer;
transition:all .18s ease;
}
.refund-modal-close:hover{
background:rgba(220,38,38,.95);
border-color:rgba(255,255,255,.6);
transform:scale(1.06);
}
.refund-modal-body{
padding:18px;
}
.refund-modal-body label{
display:block;
margin-bottom:5px;
font-size:12px;
font-weight:bold;
color:#475569;
}
.refund-modal-body input,
.refund-modal-body select{
width:100%;
min-height:36px;
padding:7px 9px;
border:1px solid #d1d5db;
border-radius:6px;
background:#fff;
color:#1e293b;
font-family:Arial,Helvetica,sans-serif;
font-size:14px;
margin-bottom:14px;
box-sizing:border-box;
}
.refund-modal-body input:focus,
.refund-modal-body select:focus{
outline:none;
border-color:#1976d2;
box-shadow:0 0 0 2px rgba(25,118,210,.12);
}
.refund-modal-body input[readonly]{
background:#f1f5f9;
color:#64748b;
}
.refund-modal-footer{
display:flex;
justify-content:flex-end;
gap:8px;
padding:0 18px 18px;
}
.refund-modal-footer button{
min-height:36px;
padding:7px 16px;
border:1px solid transparent;
border-radius:6px;
font-size:13px;
font-weight:bold;
cursor:pointer;
}
.refund-confirm-btn{
background:#1976d2;
color:#fff;
border-color:#1565c0 !important;
}
.refund-confirm-btn:hover{
background:#1565c0;
border-color:#0d47a1 !important;
}
.refund-cancel-btn{
background:#64748b;
color:#fff;
border-color:#475569 !important;
}
.refund-cancel-btn:hover{
background:#475569;
}
.admission-modal{
display:none;
position:fixed;
inset:0;
z-index:999999;
align-items:center;
justify-content:center;
background:rgba(15,23,42,.55);
backdrop-filter:blur(7px);
-webkit-backdrop-filter:blur(7px);
box-sizing:border-box;
}
.admission-modal-box{
position:relative;
width:100%;
height:100%;
background:#fff;
overflow:hidden;
box-shadow:0 20px 50px rgba(0,0,0,.25);
border:1px solid #d1d5db;
}
.admission-modal-box iframe{
width:100%;
height:100%;
border:none;
display:block;
}
.admission-modal-close{
position:absolute;
top:7px;
right:30px;
z-index:10;
width:36px;
height:36px;
display:flex;
align-items:center;
justify-content:center;
padding:0;
border:1px solid rgba(255,255,255,.35);
border-radius:50%;
background:rgba(255,255,255,.14);
color:#fff;
font-size:20px;
font-weight:400;
line-height:1;
}
.admission-modal-close:hover{
background:#dc2626;
border-color:#dc2626;
transform:scale(1.06);
}
.consultation-popup{
    display:none;
    position:fixed;
    inset:0;
    z-index:99999;
}

.consultation-popup.active{
    display:block;
}

.consultation-popup-overlay{
    position:absolute;
    inset:0;
    background:rgba(0,0,0,.65);
}

.consultation-popup-box{
    position:absolute;
    top:3%;
    left:3%;
    width:94%;
    height:94%;
    background:#fff;
    border-radius:12px;
    overflow:hidden;
    box-shadow:0 20px 60px rgba(0,0,0,.35);
    display:flex;
    flex-direction:column;
}

.consultation-popup-header{
    height:52px;
    min-height:52px;
    padding:0 16px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    background:#fff;
    border-bottom:1px solid #e5e7eb;
    color:#1f2937;
}

.consultation-popup-close{
    width:34px;
    height:34px;
    border:0;
    border-radius:6px;
    background:#f3f4f6;
    color:#374151;
    font-size:24px;
    line-height:1;
    cursor:pointer;
}

.consultation-popup-close:hover{
    background:#fee2e2;
    color:#dc2626;
}

.consultation-popup-body{
    flex:1;
    min-height:0;
    background:#000;
}

.consultation-popup-body iframe{
    width:100%;
    height:100%;
    border:0;
    display:block;
    background: white;
}
@media(max-width:700px){
.admission-modal{
padding:8px;
}
.admission-modal-box{
border-radius:8px;
}
}
.start-consultation-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    padding:6px 11px;
    border-radius:6px;
    background:#0f9d58;
    color:#fff !important;
    text-decoration:none;
    font-size:12px;
    font-weight:600;
    line-height:1;
    border:1px solid #0b8043;
    box-shadow:0 1px 3px rgba(0,0,0,.12);
    transition:all .18s ease;
}
.start-consultation-btn:hover{
    background:#0b8043;
    border-color:#076b36;
    transform:translateY(-1px);
    box-shadow:0 3px 7px rgba(0,0,0,.18);
}
.start-consultation-btn:active{
    transform:translateY(0);
    box-shadow:0 1px 2px rgba(0,0,0,.12);
}
.consultation-icon{
    font-size:13px;
    line-height:1;
}
@media(max-width:560px){
.refund-modal-box{
max-width:none;
margin:12px;
border-radius:10px;
}
.refund-modal-header{
padding:10px 12px;
}
.refund-modal-body{
padding:14px;
}
.refund-modal-footer{
padding:0 14px 14px;
flex-direction:column;
}
.refund-modal-footer button{
width:100%;
}
}
/* STATUS ROW COLORS */
tr.confirmed {
    background: #fef3c7 !important;
}
tr.refund {
    background: #fee2e2 !important;
}
tr.registered {
    background:#d1fae5 !important;
}
tr.done {
    background:#a2ddbf !important;
}
tbody tr{
    transition: box-shadow .15s ease, transform .15s ease;
}
tr.cancel {
    background:#fecaca !important;
}
tbody tr:hover{
    position:relative;
    z-index:5;
    box-shadow:
        inset 0 0 0 2px #1976d2,
        0 6px 14px rgba(25,118,210,0.25);
    transform: translateY(-1px);
}
tr.emergency-row{
    background:#fecaca !important;
}

tr.emergency-row td{
    background:#fecaca !important;
    color:#991b1b;
    font-weight:600;
}
</style>
</head>
<body>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>
<div class="main-content" id="mainContent"  style="width:auto;">
    <div class="page-container">
<div style="display:flex; align-items:center;">
    <!-- LEFT -->
    <div style="flex:1; text-align:left;">
        <h2 style="margin:0;text-align: left;">🩺 OPD List</h2>
    </div>
    <!-- CENTER -->
    <div style="flex:1; text-align:center;">
    <a href="?date=<?= $prev_date ?>"
       style="text-decoration:none;font-size:22px;font-weight:bold;padding:0 10px;">
        &#10094;
    </a>
    <span id="dateDisplay"
      onclick="document.getElementById('jumpDate').showPicker();"
      style="
        font-size:18px;
        font-weight:600;
        cursor:pointer;
        padding:6px 12px;
        border-radius:6px;
        background:#f3f4f6;
      ">
    <?= date('d M Y', strtotime($selected_date)) ?> 📅
</span>
    <a href="?date=<?= $next_date ?>"
       style="text-decoration:none;font-size:22px;font-weight:bold;padding:0 10px;">
        &#10095;
    </a>
    <input
    type="date"
    id="jumpDate"
    value="<?= $selected_date ?>"
    style="
        position:absolute;
        opacity:0;
        width:1px;
        height:1px;
        pointer-events:none;
    ">
</div>
    <!-- RIGHT (empty space for balance) -->
    <div style="flex:1; text-align:right;">
<?php if(isset($_GET['all'])){ ?>
<button onclick="window.location='?'">
    📅 Today
</button>
<?php } else { ?>
<button onclick="window.location='?all=1'">
    📋 All
</button>
<?php } ?>
<button onclick="window.open('/modules/opd/queue_display.php', '_blank')">
    Queue Display
</button>
</div>
</div>
  <!-- TABLE AREA -->
        <div class="table-wrap">
<?php
$barcodeEnabled = false;
$barcodeCheck = mysqli_query($conn,"
    SELECT id
    FROM barcode_settings
    WHERE module_name='OPD'
    AND barcode_enabled=1
    AND status='Active'
    LIMIT 1
");
if($barcodeCheck && mysqli_num_rows($barcodeCheck)){
    $barcodeEnabled = true;
}
$where = "
WHERE a.type='doctor'
AND a.status NOT IN (
    'Payment Pending',
    'Partial Paid',
    'Confirmed'
)";
if ($isDoctor) {
    $where .= " AND a.entity_id = ".$current_user_id;
}
if(!isset($_GET['all'])){
    $where .= " AND a.appointment_date='$selected_date'";
}
$query = "
SELECT
    a.*,
    (
        SELECT op.id
        FROM opd_prescriptions op
        WHERE op.appointment_id = a.id
        AND op.patient_type = 'opd'
        ORDER BY op.id DESC
        LIMIT 1
    ) AS prescription_id,
    p.uhid,
    p.abha_id,
    u.full_name AS doctor_name,
    cu.full_name AS created_by_name,
    ru.full_name AS refund_by_name,
    d.department_name,
    c.name AS city_name,
    iu.full_name AS internal_referral_name,
    rm.name AS external_referral_name,
    rm.category AS external_referral_category,
    em.empanelment_name,
    dm.discount_name
FROM appointments a
LEFT JOIN patient_master p
ON a.patient_id = p.id
LEFT JOIN users u
ON a.entity_id = u.id
LEFT JOIN users cu
ON a.created_by = cu.id
LEFT JOIN users ru
ON a.refund_by = ru.id
LEFT JOIN department_master d
ON a.department_id = d.id
LEFT JOIN cities c
ON a.city_id = c.id
LEFT JOIN users iu
ON a.internal_referral_id = iu.id
LEFT JOIN referral_master rm
ON a.external_referral_id = rm.id
LEFT JOIN empanelment_master em
ON a.empanelment_id = em.id
LEFT JOIN discount_master dm
ON a.discount_type = dm.id
$where
ORDER BY
a.appointment_date DESC,
a.created_at DESC
";
$result = mysqli_query($conn, $query);
?>
<table>
    <tr>
        <th>Action</th>
        <th>Appointment Code</th>
        <th>Appointment Seq</th>
        <th>OPD No.</th>
        <th>Token</th>
        <th>UHID</th>
        <th>Date</th>
        <th>Time</th>
        <th>Consultation Mode</th>
        <th>Patient</th>
        <th>Age</th>
        <th>Start Consultation</th>
        <th>Status</th>
        <th>Marital</th>
        <th>City</th>
        <th>Gender</th>
        <th>Department</th>
        <th>Doctor</th>
        <th>Remarks</th>
        <th>ABHA ID</th>
        <th>Guardian</th>
        <th>Referral Type</th>
        <th>Referred By</th>
        <th>Empanelment</th>
        <th>Discount Type</th>
        <th>Discount Amount</th>
        <th>Camp</th>
        <th>Visit</th>
        <th>Emergency</th>
        <th>Fee</th>
        <th>Paid</th>
        <th>Balance</th>
        <th>Payment Mode</th>
<th>Refund Amount</th>
<th>Refund Mode</th>
<th>Refund By</th>
<th>Refund Date</th>
<th>Created By</th>
    </tr>
<?php while($row = mysqli_fetch_assoc($result)) { 
$statusClass = '';
switch ($row['status']) {
    case 'Confirmed':
        $statusClass = 'confirmed';
        break;
    case 'Refund':
        $statusClass = 'refund';
        break;
    case 'Registered':
        $statusClass = 'registered';
        break;
    case 'Cancel':
        $statusClass = 'cancel';
        break;
    case 'Done':
        $statusClass = 'done';
        break;
}
?>
<tr class="<?= $statusClass ?> <?= ((int)$row['is_emergency'] === 1) ? 'emergency-row' : '' ?>">
    <td>
    <div class="action-dropdown">
        <button class="action-btn">⋮</button>
        <div class="action-menu">
            <a href="print_op_slip.php?id=<?= $row['id'] ?>" target="_blank">
    🖨️ Print OP Receipt
</a>
<a href="/modules/opd/print_op_receipt.php?appointment_id=<?= $row['id'] ?>" target="_blank">
    🧾 Print OP Slip (Rx)
</a>
<?php if($row['status']=='Prescription'){ ?>

<a href="/modules/opd/prescription_pdf.php?appointment_id=<?= $row['id'] ?>" target="_blank">
    💊 Print Prescription
</a>
<a href="javascript:void(0);"
   onclick="openPrescriptionPopup(
       <?= (int)$row['id'] ?>,
       <?= (int)($row['prescription_id'] ?? 0) ?>
   )">
    ✏️ Edit Prescription
</a>
<?php } ?>

 <?php if($barcodeEnabled){ ?>
<a href="barcode.php?id=<?= $row['id'] ?>" target="_blank">
    🏷️ Barcode
</a>
<?php } ?>
<?php if (checkPermission('patient_registration','add')): ?>
<a href="javascript:void(0);"
   onclick="openRevisitPopup(<?= $row['patient_id'] ?>)">
    🔄 Revisit
</a>
<?php endif; ?>
    <!---
    <a href="refer_patient.php?id=<?= $row['id'] ?>">
        👨‍⚕️ Refer
    </a>
    -->
        </div>
    </div>
</td>
    <td><?= $row['appointment_code'] ?></td>
    <td><?= $row['appointment_seq'] ?></td>
    <td><?= $row['opd_no'] ?></td>
    <td><?= $row['token_no'] ?></td>
    <td><?= $row['uhid'] ?></td>
    <td><?= date('d-m-Y', strtotime($row['appointment_date'])) ?></td>
    <td><?= $row['appointment_time'] ?></td>
    <td>
<?php if (($row['consultation_mode'] ?? 'PHYSICAL') === 'TELEMEDICINE'): ?>
    <span style="display:inline-block;padding:4px 8px;border-radius:6px;font-size:12px;font-weight:600;background:#dbeafe;color:#1d4ed8;">
        📹 Telemedicine
    </span>
<?php else: ?>
    <span style="display:inline-block;padding:4px 8px;border-radius:6px;font-size:12px;font-weight:600;background:#dcfce7;color:#166534;">
        🏥 Physical
    </span>
<?php endif; ?>
</td>
    <td><?= $row['title'].' '.$row['first_name'].' '.$row['last_name'] ?></td>
    <td><?= $row['age_y'].'Y '.$row['age_m'].'M '.$row['age_d'].'D' ?></td>
    <td>
<?php if (($row['consultation_mode'] ?? 'PHYSICAL') === 'TELEMEDICINE'): ?>
    <a
    href="Telemedicine/start_consultation.php?id=<?= (int)$row['id'] ?>"
    class="start-consultation-btn"
    title="Start Telemedicine Consultation"
    onclick="openConsultationPopup(event, this.href)"
>
    <span class="consultation-icon">📹</span>
    Start
</a>
<?php else: ?>
    -
<?php endif; ?>
</td>
    <td>
<?php
$allowed = allowedStatusOptions($row['status']);
$alreadyRefunded = !empty($row['refund_datetime']);

if ($alreadyRefunded) {
    $allowed = array_values(array_diff($allowed, ['Refund', 'Cancel']));
}
?>
<?php if (!empty($allowed)): ?>
<form method="post" action="/modules/appointments/update_appointment_status.php">
<input type="hidden" name="return_to" value="opd">

<input type="hidden" name="appointment_id" value="<?= $row['id'] ?>">
<select onchange="handleStatusChange(this, <?= $row['id'] ?>, <?= $row['paid_amount'] ?>)">
<option selected><?= $row['status'] ?></option>
<?php foreach ($allowed as $st): ?>
<option value="<?= $st ?>"><?= $st ?></option>
<?php endforeach; ?>
</select>
</form>
<?php else: ?>
<strong><?= $row['status'] ?></strong>
<?php endif; ?>
</td>
<td><?= $row['marital_status'] ?></td>
<td><?= $row['city_name'] ?></td>
<td><?= $row['sex'] ?></td>
<td><?= $row['department_name'] ?></td>
<td> <?= $row['doctor_name'] ?></td>
<td><?= $row['remarks'] ?></td>
<td><?= $row['abha_id'] ?></td>
<td><?= $row['guardian_name'] ?></td>
<td><?= $row['referral_type'] ?></td>
<td>
<?php
if ($row['referral_type'] === 'Internal') {
    echo $row['internal_referral_name'] ?? '';
} elseif ($row['referral_type'] === 'External') {
    echo $row['external_referral_name'] ?? '';
}
?>
</td>
<td><?= $row['empanelment_name'] ?? '' ?></td>
<td><?= $row['discount_name'] ?></td>
<td><?= $row['discount_amount'] ?></td>
<td><?= $row['camp'] ? 'Yes' : 'No' ?></td>
    <td><?= $row['visit_type'] ?></td>
    <td>
    <?php if ((int)$row['is_emergency'] === 1): ?>
        <span class="emergency-badge">🚨 EMERGENCY</span>
    <?php else: ?>
        -
    <?php endif; ?>
</td>
    <td><?= number_format($row['consultation_fee'],2) ?></td>
    <td><?= number_format($row['paid_amount'],2) ?></td>
    <td><?= number_format($row['balance_amount'],2) ?></td>
    <td><?= $row['payment_mode'] ?></td>
<td>
<?= $row['refund_amount'] ? number_format($row['refund_amount'],2) : '-' ?>
</td>
<td>
<?= $row['refund_mode'] ?: '-' ?>
</td>
<td>
<?= $row['refund_by_name'] ?: '-' ?>
</td>
<td>
<?= !empty($row['refund_datetime']) ? date('d-m-Y H:i', strtotime($row['refund_datetime'])) : '-' ?>
</td>
<td><?= $row['created_by_name'] ?></td>
</tr>
<?php } ?>
</table>
</div>
</div>
<div id="refundModal" class="refund-modal">
<div class="refund-modal-box">
<div class="refund-modal-header">
<h3>Refund Payment</h3>
<button type="button"
onclick="closeRefund()"
aria-label="Close"
title="Close"
class="refund-modal-close"
onmouseover="this.style.background='rgba(220,38,38,.95)';this.style.borderColor='rgba(255,255,255,.6)';this.style.transform='scale(1.06)'"
onmouseout="this.style.background='rgba(255,255,255,.14)';this.style.borderColor='rgba(255,255,255,.35)';this.style.transform='scale(1)'">
✕
</button>
</div>
<form method="post" action="/modules/appointments/process_refund.php">
<input type="hidden" name="appointment_id" id="refund_appointment_id">
<input type="hidden" name="status" id="refund_status">
<input type="hidden" name="return_to" value="opd">
<div class="refund-modal-body">
<label>Refund Amount</label>
<input type="text" id="refund_amount" name="refund_amount" readonly>
<label>Refund Mode</label>
<select name="refund_mode" required>
<option value="">Select</option>
<option>Cash</option>
<option>UPI</option>
<option>Card</option>
<option>Bank Transfer</option>
</select>
</div>
<div class="refund-modal-footer">
<button type="submit" class="refund-confirm-btn">Confirm Refund</button>
<button type="button" onclick="closeRefund()" class="refund-cancel-btn">Cancel</button>
</div>
</form>
</div>
</div>
<script>
document.querySelectorAll('.action-btn').forEach(btn => {
    btn.addEventListener('click', function(e){
        e.stopPropagation();
        document.querySelectorAll('.action-menu').forEach(menu => {
            menu.style.display = 'none';
        });
        this.nextElementSibling.style.display = 'block';
    });
});
document.addEventListener('click', function(){
    document.querySelectorAll('.action-menu').forEach(menu => {
        menu.style.display = 'none';
    });
});
function handleStatusChange(select, appointmentId, paidAmount)
{
if (
    select.value === 'Refund' ||
    select.value === 'Cancel'
) {
    document.getElementById('refund_status').value = select.value;
    document.getElementById('refundModal').style.display = 'flex';
    document.getElementById('refund_appointment_id').value = appointmentId;
    document.getElementById('refund_amount').value = paidAmount.toFixed(2);
    select.selectedIndex = 0;
    return;
}
else if (select.value === 'Prescription') {
    document.getElementById('prescriptionFrame').src =
        '/modules/opd/prescription_form.php?appointment_id=' + appointmentId;
    document.getElementById('prescriptionModal').style.display = 'flex';
    select.selectedIndex = 0;
    return;
}
else if (select.value === 'Admit') {
    document.getElementById('admissionFrame').src =
        '/modules/ipd/admission.php?appointment_id=' + appointmentId;
    document.getElementById('admissionModal').style.display = 'flex';
    select.selectedIndex = 0;
    return;
}
    else {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/modules/appointments/update_appointment_status.php';
        form.innerHTML = `
        <input type="hidden" name="appointment_id" value="${appointmentId}">
        <input type="hidden" name="status" value="${select.value}">
        <input type="hidden" name="return_to" value="opd">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
function closeRefund() {
    document.getElementById('refundModal').style.display = 'none';
}
function closePrescriptionModal(){
    document.getElementById('prescriptionModal').style.display = 'none';
    document.getElementById('prescriptionFrame').src = '';
}
document.addEventListener('keydown', function(e){
    if(e.key === "Escape"){
        closePrescriptionModal();
    }
});
function openPrescriptionPopup(appointmentId, prescriptionId)
{
    const frame = document.getElementById('prescriptionFrame');
    const modal = document.getElementById('prescriptionModal');

    if(!prescriptionId || prescriptionId <= 0){
        alert('Prescription record not found for this appointment.');
        return;
    }

    frame.src =
        '/modules/opd/prescription_form.php' +
        '?prescription_id=' + encodeURIComponent(prescriptionId) +
        '&appointment_id=' + encodeURIComponent(appointmentId) +
        '&mode=edit';

    console.log(
        'OPEN EDIT:',
        'appointment_id =', appointmentId,
        'prescription_id =', prescriptionId
    );

    modal.style.display = 'block';
}
</script>
<div id="prescriptionModal" style="
display:none;
position:fixed;
top:0;
left:0;
width:100%;
height:100%;
background:rgba(0,0,0,0.7);
z-index:9999;
">
    <div style="
    width:100%;
    height:100%;
    margin:auto;
    margin:auto;
    background:#fff;
    border-radius:10px;
    position:relative;
    ">
        <!-- CLOSE BUTTON -->
       <button type="button"
onclick="closePrescriptionModal()"
aria-label="Close"
title="Close"
style="
position:absolute;
top:13px;
right:30px;
z-index:10;
width:36px;
height:36px;
display:flex;
align-items:center;
justify-content:center;
padding:0;
border:1px solid rgba(255,255,255,.35);
border-radius:50%;
background:rgba(255,255,255,.14);
color:#fff;
font-size:20px;
font-weight:400;
line-height:1;
cursor:pointer;
box-shadow:0 2px 8px rgba(0,0,0,.12);
transition:all .18s ease;
"
onmouseover="this.style.background='rgba(220,38,38,.95)';this.style.borderColor='rgba(255,255,255,.6)';this.style.transform='scale(1.06)'"
onmouseout="this.style.background='rgba(255,255,255,.14)';this.style.borderColor='rgba(255,255,255,.35)';this.style.transform='scale(1)'">
✕
</button>
        <!-- PAGE LOAD HERE -->
        <iframe id="prescriptionFrame"
            style="width:100%; height:100%; border:none;">
        </iframe>
    </div>
</div>
<div id="revisitModal" style="
display:none;
position:fixed;
top:0;
left:0;
width:100%;
height:100%;
background:rgba(0,0,0,.7);
z-index:99999;
">
    <div style="
    width:100%;
    height:100%;
    background:#fff;
    border-radius:10px;
    position:relative;
    overflow:hidden;
    ">
        <button onclick="closeRevisitPopup()" style="
        position:absolute;
        top:20px;
        right:45px;
        background:red;
        color:#fff;
        border:none;
        padding:5px 10px;
        cursor:pointer;
        z-index:1000;
        ">
        X
        </button>
        <iframe id="revisitFrame"
            style="width:100%;height:100%;border:none;">
        </iframe>
    </div>
</div>
<script>
function openRevisitPopup(patientId)
{
    document.getElementById('revisitFrame').src =
        '/modules/registration/register.php?revisit_patient_id=' + patientId;
    document.getElementById('revisitModal').style.display = 'block';
}
function closeRevisitPopup()
{
    document.getElementById('revisitModal').style.display = 'none';
    document.getElementById('revisitFrame').src = '';
    location.reload();
}
</script>
<script>
document.getElementById('jumpDate').addEventListener('change', function(){
    window.location.href='?date=' + this.value;
});
function closeAdmissionPopup(){
document.getElementById('admissionModal').style.display='none';
document.getElementById('admissionFrame').src='';
location.reload();
}
</script>
<div id="admissionModal" class="admission-modal">
<div class="admission-modal-box">
<button type="button" class="admission-modal-close" onclick="closeAdmissionPopup()" aria-label="Close" title="Close">✕</button>
<iframe id="admissionFrame" src=""></iframe>
</div>
</div>
<div id="consultationPopup" class="consultation-popup">
    <div class="consultation-popup-overlay" onclick="closeConsultationPopup()"></div>

    <div class="consultation-popup-box">
        <div class="consultation-popup-header">
            <div>
                <strong>Telemedicine Consultation</strong>
            </div>
            <button type="button" class="consultation-popup-close" onclick="closeConsultationPopup()">×</button>
        </div>

        <div class="consultation-popup-body">
            <iframe
                id="consultationFrame"
                src=""
                allow="camera; microphone; fullscreen; display-capture"
                allowfullscreen
            ></iframe>
        </div>
    </div>
</div>
<script>
function openConsultationPopup(event, url){
    event.preventDefault();

    const popup = document.getElementById('consultationPopup');
    const frame = document.getElementById('consultationFrame');

    frame.src = url;
    popup.classList.add('active');

    document.body.style.overflow = 'hidden';
}

function closeConsultationPopup(){
    const popup = document.getElementById('consultationPopup');
    const frame = document.getElementById('consultationFrame');

    popup.classList.remove('active');

    setTimeout(function(){
        frame.src = '';
    }, 200);

    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(event){
    if(event.key === 'Escape'){
        closeConsultationPopup();
    }
});
</script>
</body>
</html>