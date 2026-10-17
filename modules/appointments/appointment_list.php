<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
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
ini_set('display_errors', 1);
error_reporting(E_ALL);
/* =========================
   FETCH ENTITY FILTER DATA
========================= */
$entityRes = null;
/* Doctor list */
if (($_GET['type'] ?? '') === 'doctor') {

    $entityRes = mysqli_query($conn, "
        SELECT u.id, u.full_name
        FROM doctor_master dm
        JOIN users u ON u.id = dm.user_id
        ORDER BY u.full_name
    ");
}
/* Service list */
if (
    ($_GET['type'] ?? '') === 'service' &&
    !empty($_GET['category_type'])
) {
    $cat = mysqli_real_escape_string($conn, $_GET['category_type']);

    $entityRes = mysqli_query($conn, "
        SELECT id, service_name 
        FROM service_master
        WHERE category_type = '$cat'
        ORDER BY service_name
    ");
}
$deptRes = mysqli_query($conn, "
    SELECT id, department_name 
    FROM department_master 
    ORDER BY department_name
");
// Service Categories (DISTINCT)
$catRes = mysqli_query($conn, "
    SELECT DISTINCT category_type 
    FROM service_master 
    ORDER BY category_type
");
$cityRes = mysqli_query($conn, " 
    SELECT id, name 
    FROM cities
    ORDER BY name
");
/* Created By Users */
$createdByRes = mysqli_query($conn, "
    SELECT id, full_name
    FROM users
    ORDER BY full_name
");
/* =========================
   FETCH APPOINTMENTS
========================= */
$where = [];
/* Type */
if (!empty($_GET['type'])) {
    $type = mysqli_real_escape_string($conn, $_GET['type']);
    $where[] = "a.type = '$type'";
}
/* Doctor → Department */
if (
    ($_GET['type'] ?? '') === 'doctor' &&
    !empty($_GET['department_id'])
) {
    $deptId = (int) $_GET['department_id'];
    $where[] = "a.department_id = $deptId";
}
/* Service → Category */
if (
    ($_GET['type'] ?? '') === 'service' &&
    !empty($_GET['category_type'])
) {
    $cat = mysqli_real_escape_string($conn, $_GET['category_type']);
    $where[] = "sm.category_type = '$cat'";
}
/* Doctor / Service filter */
if (!empty($_GET['entity_id'])) {
    $entityId = (int) $_GET['entity_id'];
    $where[] = "a.entity_id = $entityId";
}
/* Patient Status filter */
if (!empty($_GET['status'])) {
    $status = mysqli_real_escape_string($conn, $_GET['status']);
    $where[] = "a.status = '$status'";
}
/* Gender filter */
if (!empty($_GET['gender'])) {
    $gender = mysqli_real_escape_string($conn, $_GET['gender']);
    $where[] = "a.sex = '$gender'";
}
/* Marital Status filter */
if (!empty($_GET['marital_status'])) {
    $marital = mysqli_real_escape_string($conn, $_GET['marital_status']);
    $where[] = "a.marital_status = '$marital'";
}
/* City filter */
if (!empty($_GET['city_id'])) {
    $cityId = (int) $_GET['city_id'];
    $where[] = "a.city_id = $cityId";
}
/* Payment Mode filter */
if (!empty($_GET['payment_mode'])) {
    $mode = mysqli_real_escape_string($conn, $_GET['payment_mode']);
    $where[] = "a.payment_mode = '$mode'";
}
/* Created By filter */
if (!empty($_GET['created_by'])) {
    $createdBy = (int) $_GET['created_by'];
    $where[] = "a.created_by = $createdBy";
}
/* Appointment Date From */
if (!empty($_GET['date_from'])) {
    $dateFrom = mysqli_real_escape_string($conn, $_GET['date_from']);
    $where[] = "a.appointment_date >= '$dateFrom'";
}
/* Appointment Date To */
if (!empty($_GET['date_to'])) {
    $dateTo = mysqli_real_escape_string($conn, $_GET['date_to']);
    $where[] = "a.appointment_date <= '$dateTo'";
}
if ($isDoctor) {
    $where[] = "a.entity_id = ".$current_user_id;
}

$where[] = "a.type <> 'ipd'";

$whereSql = 'WHERE ' . implode(' AND ', $where);
$q = "
SELECT
    a.*,
    c.name AS city_name,
    dm.department_name,
    sm.category_type,
    u.full_name AS created_by_name,
    ru.full_name AS refund_by_name,
    CASE
    WHEN a.type = 'doctor' THEN doc.full_name
    WHEN a.type = 'ipd' THEN doc.full_name
    WHEN a.type = 'service' THEN sm.service_name
END AS entity_name
FROM appointments a
LEFT JOIN cities c 
    ON c.id = a.city_id
LEFT JOIN department_master dm 
    ON dm.id = a.department_id
LEFT JOIN users u 
    ON u.id = a.created_by
LEFT JOIN users doc 
    ON doc.id = a.entity_id 
   
LEFT JOIN service_master sm 
    ON sm.id = a.entity_id 
   AND a.type = 'service'
LEFT JOIN users ru 
    ON ru.id = a.refund_by
$whereSql
ORDER BY a.created_at DESC
";
$res = mysqli_query($conn, $q);
function e($value) {
    return htmlspecialchars((string)($value ?? ''));
}
function allowedStatusOptions($current)
{
    if ($current == 'Payment Pending') {
        return ['Confirmed','Cancel'];
    }
    if ($current == 'Partial Paid') {
        return ['Confirmed', 'Refund','Cancel'];
    }
    if ($current == 'Confirmed') {
        return ['Registered', 'Refund','Cancel'];
    }
    if ($current == 'Registered') {
        return ['Done','Cancel'];
    }
    if ($current == 'Refund') {
        return ['Registered'];
    }
     if (in_array($current, ['Cancel', 'Done'])) {
        return [];
    }
        return []; 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Appointment List</title>
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
    height: 550px;
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
tr:nth-child(even){
    background:#f8fafc;
}
.badge{
    padding:4px 8px;
    border-radius:6px;
    font-size:12px;
    font-weight:600;
}
.badge-doctor{ background:#dbeafe;color:#1e40af; }
.badge-service{ background:#90daaa;color:#166534; }
.amount{
    text-align:right;
    font-weight:600;
}
/* =========================================
   REFUND PAYMENT POPUP
========================================= */
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
border:none;
border-radius:6px;
font-size:13px;
font-weight:bold;
cursor:pointer;
}
.refund-confirm-btn{
background:#1976d2;
color:#fff;
}
.refund-confirm-btn:hover{
background:#1565c0;
}
.refund-cancel-btn{
background:#64748b;
color:#fff;
}
.refund-cancel-btn:hover{
background:#475569;
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
/* PAYMENT STATUS ROW COLORS */
tr.paid {
    background: #dcfce7 !important;   /* green */
}
tr.confirmed {
    background: #fef3c7 !important;   /* yellow */
}
tr.cancel {
    background:#fecaca !important;
}
tr.refund {
    background: #fee2e2 !important;   /* red */
}
tr.not-paid {
    background: #f3e8ff !important; /* purple */
}
tr.registered {
    background:#d1fae5 !important;
}
tr.done {
    background:#a2ddbf !important;
}
.action-menu {
    position: relative;
    display: inline-block;
}
.action-btn {
    background: #1976d299;
    border: none;
    font-size: 18px;
    cursor: pointer;
}
.action-dropdown {
    display: none;
    position: absolute;
    left: 0;
    top: 22px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    min-width: 180px;
    box-shadow: 0 10px 25px rgba(0,0,0,.15);
    z-index: 9999;
}
.action-dropdown a {
    display: block;
    padding: 8px 12px;
    font-size: 13px;
    text-decoration: none;
    color: #111827;
}
.action-dropdown a:hover {
    background: #f3f4f6;
}
/* ROW HOVER EFFECT */
tbody tr {
    transition: 
        box-shadow 0.15s ease,
        transform 0.15s ease;
}
tbody tr:hover {
    position: relative;
    z-index: 5;
    box-shadow: 
        inset 0 0 0 2px #1976d2,   /* blue border */
        0 6px 14px rgba(25,118,210,0.25);
    transform: translateY(-1px);
}
tbody tr:hover td {
    background-image: linear-gradient(
        rgba(25,118,210,0.04),
        rgba(25,118,210,0.04)
    );
}
/* =========================================
   EDIT APPOINTMENT POPUP
========================================= */
.edit-appointment-popup{
display:none;
position:fixed;
inset:0;
z-index:99999;
align-items:center;
justify-content:center;
background:rgba(15,23,42,.55);
}
.edit-appointment-popup.active{
display:flex;
}
.edit-appointment-modal{
position:relative;
width:100%;
height:100vh;
background:#fff;
overflow:hidden;
box-shadow:0 15px 40px rgba(0,0,0,.25);
border:1px solid #d1d5db;
}
.edit-appointment-modal iframe{
display:block;
width:100%;
height:100%;
border:0;
background:#eef2f7;
}
.edit-appointment-close{
position:absolute;
top:5.5px;
right:30px;
z-index:20;
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
}
.edit-appointment-close:hover{
background:rgba(220,38,38,.95);
border-color:rgba(255,255,255,.6);
transform:scale(1.06);
}
@media(max-width:700px){
.edit-appointment-popup{
padding:8px;
}
.edit-appointment-modal{
width:100%;
height:96vh;
border-radius:10px;
}
.edit-appointment-close{
top:7px;
right:7px;
width:32px;
height:32px;
font-size:17px;
}
}
</style>
</head>
<body>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>
<div class="main-content" id="mainContent"  style="width:auto;">
    <div class="page-container">
<h2 style="text-align:left;">📋 Appointment List</h2>
<?php if (isset($_GET['saved'])): ?>
<div style="background:#d1fae5;color:#065f46;padding:2px;border-radius:6px;">
    ✅ Appointment saved successfully
</div>
<?php endif; ?>
<form method="get" style="margin-bottom:3px;display:flex;gap:5px;align-items:center;">
    <!-- TYPE -->
    <select name="type" onchange="this.form.submit()">
        <option value="">Select Type</option>
        <option value="doctor" <?= ($_GET['type'] ?? '')==='doctor'?'selected':'' ?>>Doctor</option>
        <option value="service" <?= ($_GET['type'] ?? '')==='service'?'selected':'' ?>>Service</option>
    </select>
    <!-- DOCTOR → DEPARTMENT -->
    <?php if (($_GET['type'] ?? '') === 'doctor'): ?>
        <select name="department_id" onchange="this.form.submit()">
            <option value="">Departments</option>
            <?php while ($d = mysqli_fetch_assoc($deptRes)): ?>
                <option value="<?= $d['id'] ?>"
                    <?= (($_GET['department_id'] ?? '') == $d['id']) ? 'selected' : '' ?>>
                    <?= e($d['department_name']) ?>
                </option>
            <?php endwhile; ?>
        </select>
    <?php endif; ?>
    <!-- SERVICE → CATEGORY -->
    <?php if (($_GET['type'] ?? '') === 'service'): ?>
        <select name="category_type" onchange="this.form.submit()">
            <option value="">Service</option>
            <?php while ($c = mysqli_fetch_assoc($catRes)): ?>
                <option value="<?= e($c['category_type']) ?>"
                    <?= (($_GET['category_type'] ?? '') === $c['category_type']) ? 'selected' : '' ?>>
                    <?= e($c['category_type']) ?>
                </option>
            <?php endwhile; ?>
        </select>
    <?php endif; ?>
<?php if ($entityRes && mysqli_num_rows($entityRes) > 0): ?>
    <select name="entity_id" onchange="this.form.submit()">
        <option value="">All <?= ($_GET['type'] === 'doctor') ? 'Doctors' : 'Services' ?></option>
        <?php while ($e = mysqli_fetch_assoc($entityRes)): ?>
            <option value="<?= $e['id'] ?>"
                <?= (($_GET['entity_id'] ?? '') == $e['id']) ? 'selected' : '' ?>>
                <?= e($_GET['type'] === 'doctor' ? $e['full_name'] : $e['service_name']) ?>
            </option>
        <?php endwhile; ?>
    </select>
<?php endif; ?>
<!-- PATIENT STATUS -->
<select name="status" onchange="this.form.submit()">
    <option value="">Status</option>
<?php
    $statuses = [
        'Payment Pending',
        'Confirmed',
        'Registered',
        'Done',
        'Refund',
        'Cancel'
    ];
    foreach ($statuses as $s):
    ?>
        <option value="<?= $s ?>"
            <?= (($_GET['status'] ?? '') === $s) ? 'selected' : '' ?>>
            <?= $s ?>
        </option>
    <?php endforeach; ?>
</select>
<!-- GENDER -->
<select name="gender" onchange="this.form.submit()">
    <option value="">Gender</option>
    <option value="Male" <?= (($_GET['gender'] ?? '')=='Male')?'selected':'' ?>>Male</option>
    <option value="Female" <?= (($_GET['gender'] ?? '')=='Female')?'selected':'' ?>>Female</option>
    <option value="Other" <?= (($_GET['gender'] ?? '')=='Other')?'selected':'' ?>>Other</option>
</select>
<!-- MARITAL STATUS -->
<select name="marital_status" onchange="this.form.submit()">
    <option value="">Marital Status</option>

    <option value="UnMarried" <?= (($_GET['marital_status'] ?? '')=='UnMarried')?'selected':'' ?>>UnMarried</option>
    <option value="Married" <?= (($_GET['marital_status'] ?? '')=='Married')?'selected':'' ?>>Married</option>
    <option value="Divorced" <?= (($_GET['marital_status'] ?? '')=='Divorced')?'selected':'' ?>>Divorced</option>
</select>
<!-- CITY -->
<select name="city_id" onchange="this.form.submit()">
    <option value="">Cities</option>
    <?php while ($city = mysqli_fetch_assoc($cityRes)): ?>
        <option value="<?= $city['id'] ?>"
            <?= (($_GET['city_id'] ?? '') == $city['id']) ? 'selected' : '' ?>>
            <?= e($city['name']) ?>
        </option>
    <?php endwhile; ?>
</select>
<!-- PAYMENT MODE -->
<select name="payment_mode" onchange="this.form.submit()">
    <option value="">Payment Mode</option>
    <option value="Cash" <?= (($_GET['payment_mode'] ?? '')=='Cash')?'selected':'' ?>>Cash</option>
    <option value="UPI" <?= (($_GET['payment_mode'] ?? '')=='UPI')?'selected':'' ?>>UPI</option>
    <option value="Card" <?= (($_GET['payment_mode'] ?? '')=='Card')?'selected':'' ?>>Card</option>
    <option value="Bank Transfer" <?= (($_GET['payment_mode'] ?? '')=='Bank Transfer')?'selected':'' ?>>Bank Transfer</option>
</select>
<!-- CREATED BY -->
<select name="created_by" onchange="this.form.submit()">
    <option value="">Staff</option>
    <?php while ($u = mysqli_fetch_assoc($createdByRes)): ?>
        <option value="<?= $u['id'] ?>"
            <?= (($_GET['created_by'] ?? '') == $u['id']) ? 'selected' : '' ?>>
            <?= e($u['full_name']) ?>
        </option>
    <?php endwhile; ?>
</select>
<!-- APPOINTMENT DATE FROM -->
<input type="date" name="date_from"
value="<?= $_GET['date_from'] ?? '' ?>"
onchange="this.form.submit()">
<!-- APPOINTMENT DATE TO -->
<input type="date" name="date_to"
value="<?= $_GET['date_to'] ?? '' ?>"
onchange="this.form.submit()">
<a href="?" style="padding:6px 10px;background:#ef4444;color:#fff;border-radius:6px;text-decoration:none;">
X
</a>
</form>
<div class="table-wrap">
<table>
<thead>
<tr>
    <th>#</th>
    <th>ID</th>
    <th>Seq</th>
  <!--  <th>Appointment Code</th> -->
    <th>Token</th>
    <th>Type</th>
    <th>Consultation Mode</th>
    <th>Department</th>
    <th>Doctor / Service</th>
    <th>Date</th>
    <th>Time</th>
    <th>Patient Name</th>
    <th>Patient Status</th>
    <th>Gender</th>
    <th>Marital</th>
    <th>Guardian</th>
    <th>Aadhaar</th>
    <th>Mobile</th>
    <th>Phone</th>
    <th>Email</th>
    <th>DOB</th>
    <th>Age</th>
    <th>City</th>
    <th>Pincode</th>
    <th>Address</th>
    <th>Remarks</th>
    <th>Fee</th>
    <th>Payment Mode</th>
    <th>Paid</th>
    <th>Balance</th>
    <th>Created By</th>
    <th>Created At</th>
    <th>Refund Amount</th>
    <th>Refund Mode</th>
    <th>Refund By</th>
    <th>Refund Date</th>
</tr>
</thead>
<tbody>
<?php if (mysqli_num_rows($res) == 0): ?>
<tr>
    <td colspan="30" style="text-align:center;">No appointments found</td>
</tr>
<?php else: ?>
<?php while ($r = mysqli_fetch_assoc($res)): ?>
<?php
$alreadyRefunded = !empty($r['refund_datetime']);
$statusClass = '';
switch ($r['status']) {
    case 'Confirmed':
        $statusClass = 'confirmed';
        break;
    case 'Refund':
        $statusClass = 'refund';
        break;
    case 'Done':
        $statusClass = 'done';
        break;
    case 'Cancel':
        $statusClass = 'cancel';
        break;
    case 'Registered':
        $statusClass = 'registered';
        break;

    default:
        $statusClass = '';
}
?>
<tr class="<?= $statusClass ?>">
    <td style="position:relative;">
    <div class="action-menu">
        <button type="button" class="action-btn"
            onclick="toggleActionMenu(this)">⋮</button>
        <div class="action-dropdown">
           <?php if ($r['status'] == 'Confirmed' && checkPermission('appointments','update')): ?>
<a href="javascript:void(0);"
   onclick="openEditAppointment(<?= (int)$r['id'] ?>)">
   ✏ Edit Appointment
</a>
<?php endif; ?>
            <a href="appointment_pdf.php?id=<?= $r['id'] ?>&print=1"
               target="_blank">
               🖨 Print Appointment
            </a>
        </div>
    </div>
</td>
    <td><?= $r['id'] ?></td>
    <td><?= $r['appointment_seq'] ?></td>
<!--    <td><?= $r['appointment_code'] ?? '-' ?></td> -->
    <td><?= $r['token_no'] ?? '-' ?></td>
    <td>
        <span class="badge
<?=
$r['type']=='doctor' ? 'badge-doctor' :
($r['type']=='service' ? 'badge-service' : 'badge-doctor')
?>">
<?= strtoupper($r['type']) ?>
</span>
    </td>
    <td>
<?php if (($r['consultation_mode'] ?? 'PHYSICAL') === 'TELEMEDICINE'): ?>
    <span class="badge" style="background:#dbeafe;color:#1d4ed8;">📹 Telemedicine</span>
<?php else: ?>
    <span class="badge" style="background:#dcfce7;color:#166534;">🏥 Physical</span>
<?php endif; ?>
</td>
    <td>
<?php
if ($r['type'] == 'doctor' || $r['type'] == 'ipd') {
    echo e($r['department_name']);
} else {
    echo e($r['category_type']);
}
?>
</td>

    <td><?= e($r['entity_name']) ?></td>
    <td><?= $r['appointment_date'] ?></td>
    <td><?= $r['appointment_time'] ?></td>
    <td><?= $r['title'].' '.$r['first_name'].' '.$r['last_name'] ?></td>
    <td>
<?php
$allowed = allowedStatusOptions($r['status']);

if ($alreadyRefunded) {
    $allowed = array_values(array_diff($allowed, ['Refund', 'Cancel']));
}
?>
<?php if (!empty($allowed)): ?>
<form method="post" action="update_appointment_status.php">
    <input type="hidden" name="appointment_id" value="<?= $r['id'] ?>">
    <select onchange="handleStatusChange(this, <?= $r['id'] ?>, <?= $r['paid_amount'] ?>)">
    <option selected><?= $r['status'] ?></option>
    <?php foreach ($allowed as $st): ?>
        <option value="<?= $st ?>"><?= $st ?></option>
    <?php endforeach; ?>
</select>
</form>
<?php else: ?>
    <strong><?= $r['status'] ?></strong>
<?php endif; ?>
</td>
    <td><?= $r['sex'] ?></td>
    <td><?= $r['marital_status'] ?></td>
    <td><?= $r['guardian_name'] ?></td>
    <td><?= $r['aadhaar'] ?></td>
    <td><?= $r['mobile'] ?></td>
    <td><?= $r['phone'] ?></td>
    <td><?= $r['email'] ?></td>
    <td><?= $r['dob'] ?></td>
    <td><?= $r['age_y'].'Y '.$r['age_m'].'M '.$r['age_d'].'D' ?></td>
    <td><?= $r['city_name'] ?></td>
    <td><?= $r['pincode'] ?></td>
    <td><?= $r['address'] ?></td>
    <td><?= $r['remarks'] ?></td>
    <td class="amount"><?= number_format($r['consultation_fee'],2) ?></td>
    <td><?= $r['payment_mode'] ?></td>
    <td class="amount"><?= number_format($r['paid_amount'],2) ?></td>
    <td class="amount"><?= number_format($r['balance_amount'],2) ?></td>
    <td><?= e($r['created_by_name']) ?></td>
    <td><?= $r['created_at'] ?></td>
    <td class="amount">    <?= $r['refund_amount'] ? number_format($r['refund_amount'], 2) : '-' ?></td>
    <td>    <?= $r['refund_mode'] ?? '-' ?></td>
    <td><?= e($r['refund_by_name']) ?: '-' ?></td>
    <td>    <?= $r['refund_datetime'] ? date('d-m-Y H:i', strtotime($r['refund_datetime'])) : '-' ?></td>
    </tr>
<?php endwhile; ?>
<?php endif; ?>
</tbody>
</table>
</div>
<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    sidebar.classList.toggle('collapsed');
}
</script>

<script>
function handleStatusChange(select, appointmentId, paidAmount)
{
   if (select.value === 'Registered') {

    document.getElementById('registrationFrame').src =
    '/modules/registration/register.php?appointment_id=' + appointmentId;

document.getElementById('registrationPopup').style.display = 'block';
document.body.style.overflow = 'hidden';

    select.selectedIndex = 0;
    return;
}

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
    // normal status change
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'update_appointment_status.php';
    form.innerHTML = `
        <input type="hidden" name="appointment_id" value="${appointmentId}">
        <input type="hidden" name="status" value="${select.value}">
        <input type="hidden" name="return_to" value="appointment">
    `;
    document.body.appendChild(form);
    form.submit();
}
function closeRefund() {
    document.getElementById('refundModal').style.display = 'none';
}
</script>
<div id="refundModal" class="refund-modal">
<div class="refund-modal-box">
<div class="refund-modal-header">
<h3>Refund Payment</h3>
<button type="button" class="refund-modal-close" onclick="closeRefund()" aria-label="Close" title="Close">✕</button>
</div>
<div class="refund-modal-body">
<form method="post" action="process_refund.php">
<input type="hidden" name="appointment_id" id="refund_appointment_id">
<input type="hidden" name="status" id="refund_status">
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
<button type="button" class="refund-cancel-btn" onclick="closeRefund()">Cancel</button>
</div>
</form>
</div>
</div>
<script>
function toggleActionMenu(btn) {
    // close others
    document.querySelectorAll('.action-dropdown').forEach(d => {
        if (d !== btn.nextElementSibling) {
            d.style.display = 'none';
        }
    });
    const menu = btn.nextElementSibling;
    menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
}
// close when clicking outside
document.addEventListener('click', function (e) {
    if (!e.target.closest('.action-menu')) {
        document.querySelectorAll('.action-dropdown').forEach(d => {
            d.style.display = 'none';
        });
    }
});
</script>
<audio id="rowHoverSound" preload="auto">
    <source src="/assets/sounds/tick.wav" type="audio/mpeg">
</audio>
<script>
let lastHoverTime = 0;
document.querySelectorAll('tbody tr').forEach(row => {
    row.addEventListener('mouseenter', () => {
        const now = Date.now();
        // prevent machine-gun sound
        if (now - lastHoverTime < 120) return;
        lastHoverTime = now;
        const sound = document.getElementById('rowHoverSound');
        sound.currentTime = 0;
        sound.volume = 0.25; // subtle hospital-friendly
        sound.play().catch(() => {});
    });
});
function closeRegistrationPopup()
{
    document.getElementById('registrationFrame').src = '';
    document.getElementById('registrationPopup').style.display = 'none';
    document.body.style.overflow = '';
    location.reload();
}
function openEditAppointment(appointmentId)
{
    const popup = document.getElementById('editAppointmentPopup');
    const frame = document.getElementById('editAppointmentFrame');

    frame.src =
        'edit_appointment.php?id=' +
        encodeURIComponent(appointmentId);

    popup.classList.add('active');

    document.body.style.overflow = 'hidden';
}

function closeEditAppointmentPopup()
{
    const popup = document.getElementById('editAppointmentPopup');
    const frame = document.getElementById('editAppointmentFrame');

    frame.src = '';

    popup.classList.remove('active');

    document.body.style.overflow = '';
}
</script>
<script>
window.parent.closeEditAppointmentPopup();
window.parent.location.reload();
</script>
<div id="registrationPopup" style="
    display:none;
    position:fixed;
    top:0;
    left:0;
    width:100%;
    height:100%;
    background:rgba(0,0,0,0.6);
    z-index:99999;
">
    <div style="
        width:100%;
        height:100%;
        background:#fff;
        margin: auto;
        position:relative;
        
        overflow:hidden;
    ">
        <button type="button"
onclick="closeRegistrationPopup()"
aria-label="Close"
title="Close"
style="
position:absolute;
top:5.5px;
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
        <iframe id="registrationFrame" src="" style="width:100%;height:100%;border:none;"> </iframe>
    </div>
</div>
<div id="editAppointmentPopup" class="edit-appointment-popup">
    <div class="edit-appointment-modal">
        <button
            type="button"
            class="edit-appointment-close"
            onclick="closeEditAppointmentPopup()">
            ✕
        </button>
        <iframe
            id="editAppointmentFrame"
            src="">
        </iframe>
    </div>
</div>
</body>
</html>