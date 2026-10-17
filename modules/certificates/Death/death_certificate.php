<?php
session_start();
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if(!isset($_SESSION['user_id'])){
    header("Location:/index.php");
    exit;
}
if(!checkPermission('death_certificate_list','view')){
    header("Location:/access_denied.php");
    exit;
}
$current_user_id=(int)$_SESSION['user_id'];
function e($value){
    return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
}
/* ============================================================
   CHECK WHETHER LOGGED USER IS DOCTOR
============================================================ */
$isDoctor=false;
$logged_doctor_id=0;
$stmt=$conn->prepare("
SELECT id
FROM doctor_master
WHERE user_id=?
LIMIT 1
");
$stmt->bind_param("i",$current_user_id);
$stmt->execute();
$doctorLoginResult=$stmt->get_result();
if($doctorRow=$doctorLoginResult->fetch_assoc()){
    $isDoctor=true;
    $logged_doctor_id=(int)$doctorRow['id'];
}
$stmt->close();
/* ============================================================
   FILTERS
============================================================ */
$where=[];
if(!empty($_GET['status'])){
    $status=mysqli_real_escape_string($conn,$_GET['status']);
    $where[]="dc.status='$status'";
}
if(!empty($_GET['doctor_id'])){
    $doctor_id=(int)$_GET['doctor_id'];
    $where[]="dc.doctor_id=$doctor_id";
}
if(!empty($_GET['patient_type'])){
    $patient_type=mysqli_real_escape_string($conn,$_GET['patient_type']);
    $where[]="dc.patient_type='$patient_type'";
}
if(!empty($_GET['certificate_no'])){
    $certificate_no=mysqli_real_escape_string($conn,$_GET['certificate_no']);
    $where[]="dc.certificate_no LIKE '%$certificate_no%'";
}
if(!empty($_GET['uhid'])){
    $uhid=(int)$_GET['uhid'];
    $where[]="dc.uhid=$uhid";
}
if(!empty($_GET['patient'])){
    $patient=mysqli_real_escape_string($conn,$_GET['patient']);
    $where[]="dc.patient_name LIKE '%$patient%'";
}
if(!empty($_GET['mobile'])){
    $mobile=mysqli_real_escape_string($conn,$_GET['mobile']);
    $where[]="dc.mobile LIKE '%$mobile%'";
}
if(!empty($_GET['manner_of_death'])){
    $manner=mysqli_real_escape_string($conn,$_GET['manner_of_death']);
    $where[]="dc.manner_of_death='$manner'";
}
if(!empty($_GET['date_from'])){
    $date_from=mysqli_real_escape_string($conn,$_GET['date_from']);
    $where[]="dc.date_of_death>='$date_from'";
}
if(!empty($_GET['date_to'])){
    $date_to=mysqli_real_escape_string($conn,$_GET['date_to']);
    $where[]="dc.date_of_death<='$date_to'";
}
/* Doctor Login Restriction */
if($isDoctor){
    $where[]="dc.doctor_id=".$logged_doctor_id;
}
$whereSql="WHERE 1=1";
if(count($where)){
    $whereSql.=" AND ".implode(" AND ",$where);
}
/* ============================================================
   DOCTOR DROPDOWN
============================================================ */
$doctorRes=mysqli_query($conn,"
SELECT
dm.id,
u.full_name
FROM doctor_master dm
INNER JOIN users u
ON u.id=dm.user_id
ORDER BY u.full_name
");
/* ============================================================
   DEATH CERTIFICATE LIST
============================================================ */
$sql="
SELECT
dc.id,
dc.certificate_no,
dc.certificate_date,
dc.patient_type,
dc.uhid,
dc.patient_name,
dc.guardian_name,
dc.mobile,
dc.dob,
dc.age_y,
dc.age_m,
dc.age_d,
dc.age,
dc.sex,
dc.marital_status,
dc.address,
dc.date_of_death,
dc.time_of_death,
dc.place_of_death,
dc.place_of_death_other,
dc.immediate_cause,
dc.antecedent_cause,
dc.underlying_cause,
dc.other_significant_conditions,
dc.manner_of_death,
dc.manner_of_death_other,
dc.remarks,
dc.doctor_id,
dc.doctor_name,
dc.doctor_registration_no,
dc.status,
dc.created_at,
dc.updated_by,
dc.updated_at,
u_update.full_name AS updated_by_name
FROM death_certificates dc
LEFT JOIN users u_update
ON u_update.id=dc.updated_by
$whereSql
ORDER BY
dc.certificate_date DESC,
dc.id DESC
";
$res=mysqli_query($conn,$sql);
if(!$res){
    die("Database Error: ".mysqli_error($conn));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Death Certificate</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" href="/assets/img/hms_logo.png">
<style>
.main-content{
width:auto;
overflow:hidden;
}
.page-container{
padding:8px;
}
.filter-box{
background:#fff;
padding:10px;
border-radius:8px;
margin-bottom:10px;
box-shadow:0 2px 8px rgba(0,0,0,.08);
}
.filter-box form{
display:flex;
flex-wrap:wrap;
gap:8px;
align-items:center;
}
.filter-box select{
width:130px;
height:36px;
}
.filter-box input[type=text]{
width:140px;
height:36px;
}
.filter-box input[type=date]{
width:145px;
height:36px;
}
.filter-box button{
height:36px;
padding:0 15px;
}
.table-wrap{
background:#fff;
border-radius:10px;
padding:1px;
box-shadow:0 10px 25px rgba(0,0,0,.08);
overflow:auto;
width:100%;
max-width:100%;
height:calc(100vh - 220px);
}
table{
width:100%;
min-width:2500px;
border-collapse:collapse;
white-space:nowrap;
font-size:13px;
}
th,
td{
border:1px solid #e5e7eb;
padding:6px 10px;
text-align:left;
}
th{
background:#1976d2;
color:#fff;
position:sticky;
top:0;
z-index:2;
}
.badge{
padding:4px 8px;
border-radius:4px;
font-size:12px;
color:#fff;
display:inline-block;
}
.badge-done{
background:#16a34a;
}
.badge-cancel{
background:#dc2626;
}
.action-menu{
    position:relative;
    display:inline-block;
}
.action-btn{
    background:#1976d2;
    color:#fff;
    border:none;
    padding:4px 8px;
    border-radius:4px;
    cursor:pointer;
}
.action-dropdown{
    display:none;
    position:absolute;
    left:100%;
    top:0;
    margin-left:5px;

    min-width:150px;
    background:#fff;
    border:1px solid #ddd;
    border-radius:5px;
    box-shadow:0 5px 15px rgba(0,0,0,.18);

    z-index:999999;
}
.action-dropdown a{
    display:block;
    padding:9px 12px;
    text-decoration:none;
    color:#222;
    white-space:nowrap;
}
.action-dropdown a:hover{
    background:#f5f5f5;
}
/* ADD POPUP */
.dc-popup{
display:none;
position:fixed;
inset:0;
background:rgba(0,0,0,.55);
z-index:99999;
padding:0px;
}
.dc-popup-box{
width:100%;
height:100vh;
margin:auto;
background:#fff;
border-radius:10px;
overflow:hidden;
box-shadow:0 10px 40px rgba(0,0,0,.3);
}
.dc-popup-header{
height:50px;
display:flex;
align-items:center;
padding:0 15px;
background:#1976d2;
color:#fff;
}
.dc-popup-title{
flex:1;
font-size:17px;
font-weight:600;
}
.dc-popup-close{
width:34px;
height:34px;
border:0;
background:transparent;
color:#fff;
font-size:28px;
line-height:28px;
cursor:pointer;
}
.dc-popup-body{
height:calc(94vh - 50px);
}
.dc-popup-body iframe{
width:100%;
height:100%;
border:0;
display:block;
}
body.dc-popup-open{
overflow:hidden;
}
</style>
</head>
<body>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>
<div class="main-content" id="mainContent" style="width:auto;">
<div class="page-container">
<div style="display:flex;align-items:center;margin-bottom:10px;">
<div style="flex:1;">
<h2 style="margin:0;">Death Certificate</h2>
</div>
<div style="flex:1;text-align:right;">
<?php if(checkPermission('death_certificate','add')){ ?>
<button type="button" onclick="openDeathCertificatePopup()">➕ Add Death Certificate</button>
<?php } ?>
</div>
</div>
<div class="filter-box">
<form method="get">
<select name="patient_type" onchange="this.form.submit()">
<option value="">Patient Type</option>
<option value="Direct" <?= (($_GET['patient_type']??'')==='Direct')?'selected':''; ?>>Direct</option>
<option value="OPD" <?= (($_GET['patient_type']??'')==='OPD')?'selected':''; ?>>OPD</option>
<option value="IPD" <?= (($_GET['patient_type']??'')==='IPD')?'selected':''; ?>>IPD</option>
</select>
<select name="doctor_id" onchange="this.form.submit()">
<option value="">Doctor</option>
<?php while($doc=mysqli_fetch_assoc($doctorRes)){ ?>
<option value="<?= (int)$doc['id'] ?>" <?= ((string)($_GET['doctor_id']??'')===(string)$doc['id'])?'selected':''; ?>>
<?= e($doc['full_name']) ?>
</option>
<?php } ?>
</select>
<select name="manner_of_death" onchange="this.form.submit()">
<option value="">Manner of Death</option>
<option value="Natural" <?= (($_GET['manner_of_death']??'')==='Natural')?'selected':''; ?>>Natural</option>
<option value="Accident" <?= (($_GET['manner_of_death']??'')==='Accident')?'selected':''; ?>>Accident</option>
<option value="Suicide" <?= (($_GET['manner_of_death']??'')==='Suicide')?'selected':''; ?>>Suicide</option>
<option value="Homicide" <?= (($_GET['manner_of_death']??'')==='Homicide')?'selected':''; ?>>Homicide</option>
<option value="Pending Investigation" <?= (($_GET['manner_of_death']??'')==='Pending Investigation')?'selected':''; ?>>Pending Investigation</option>
<option value="Other" <?= (($_GET['manner_of_death']??'')==='Other')?'selected':''; ?>>Other</option>
</select>
<select name="status" onchange="this.form.submit()">
<option value="">Status</option>
<option value="Done" <?= (($_GET['status']??'')==='Done')?'selected':''; ?>>Done</option>
<option value="Cancelled" <?= (($_GET['status']??'')==='Cancelled')?'selected':''; ?>>Cancelled</option>
</select>
<input type="text" name="certificate_no" placeholder="Certificate No" value="<?= e($_GET['certificate_no']??'') ?>">
<input type="text" name="uhid" placeholder="UHID" value="<?= e($_GET['uhid']??'') ?>">
<input type="text" name="patient" placeholder="Patient Name" value="<?= e($_GET['patient']??'') ?>">
<input type="text" name="mobile" placeholder="Mobile" value="<?= e($_GET['mobile']??'') ?>">
<input type="date" name="date_from" value="<?= e($_GET['date_from']??'') ?>">
<input type="date" name="date_to" value="<?= e($_GET['date_to']??'') ?>">
<button type="submit">Search</button>
<button type="button" onclick="window.location='death_certificate.php'">Reset</button>
</form>
</div>
<div id="deathCertificatePopup" class="dc-popup">
<div class="dc-popup-box">
<div class="dc-popup-header">
<div class="dc-popup-title">Add Death Certificate</div>
<button type="button" class="dc-popup-close" onclick="closeDeathCertificatePopup()">×</button>
</div>
<div class="dc-popup-body">
<iframe id="deathCertificateFrame" src="" frameborder="0"></iframe>
</div>
</div>
</div>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Action</th>
<th>ID</th>
<th>Certificate No</th>
<th>Certificate Date</th>
<th>Patient Type</th>
<th>UHID</th>
<th>Patient Name</th>
<th>Guardian Name</th>
<th>Mobile</th>
<th>DOB</th>
<th>Age</th>
<th>Gender</th>
<th>Marital Status</th>
<th>Address</th>
<th>Date of Death</th>
<th>Time of Death</th>
<th>Place of Death</th>
<th>Immediate Cause</th>
<th>Antecedent Cause</th>
<th>Underlying Cause</th>
<th>Other Significant Conditions</th>
<th>Manner of Death</th>
<th>Remarks</th>
<th>Doctor</th>
<th>Doctor Reg. No.</th>
<th>Status</th>
<th>Created At</th>
<th>Updated By</th>
<th>Updated At</th>
</tr>
</thead>
<tbody>
<?php if(mysqli_num_rows($res)==0){ ?>
<tr>
<td colspan="29" style="text-align:center;padding:25px;">
No Death Certificates Found
</td>
</tr>
<?php } ?>
<?php while($r=mysqli_fetch_assoc($res)){ ?>
<tr>
<td>
<div class="action-menu">
<button type="button" class="action-btn" onclick="toggleActionMenu(this)">⋮</button>
<div class="action-dropdown">
<a href="print.php?id=<?= (int)$r['id'] ?>" target="_blank">🖨 Print</a>
<?php if($r['status']==='Done' && checkPermission('death_certificate','edit')){ ?>
<a href="cancel.php?id=<?= (int)$r['id'] ?>"
onclick="return confirm('Are you sure you want to cancel this Death Certificate?')">❌ Cancel</a>
<?php } ?>
</div>
</div>
</td>
<td><?= (int)$r['id'] ?></td>
<td><?= e($r['certificate_no']) ?></td>
<td><?= !empty($r['certificate_date']) ? date('d-m-Y',strtotime($r['certificate_date'])) : '-' ?></td>
<td><?= e($r['patient_type']) ?></td>
<td><?= !empty($r['uhid']) ? e($r['uhid']) : '-' ?></td>
<td><?= e($r['patient_name']) ?></td>
<td><?= !empty($r['guardian_name']) ? e($r['guardian_name']) : '-' ?></td>
<td><?= !empty($r['mobile']) ? e($r['mobile']) : '-' ?></td>
<td><?= !empty($r['dob']) ? date('d-m-Y',strtotime($r['dob'])) : '-' ?></td>
<td><?= !empty($r['age']) ? e($r['age']) : '-' ?></td>
<td><?= !empty($r['sex']) ? e($r['sex']) : '-' ?></td>
<td><?= !empty($r['marital_status']) ? e($r['marital_status']) : '-' ?></td>
<td><?= !empty($r['address']) ? e($r['address']) : '-' ?></td>
<td><?= !empty($r['date_of_death']) ? date('d-m-Y',strtotime($r['date_of_death'])) : '-' ?></td>
<td>
<?php
if(!empty($r['time_of_death'])){
    echo date('h:i A',strtotime($r['time_of_death']));
}else{
    echo '-';
}
?>
</td>
<td>
<?php
$place_of_death=trim($r['place_of_death'] ?? '');
if($place_of_death==='Other' && !empty($r['place_of_death_other'])){
    echo e($r['place_of_death_other']);
}else{
    echo $place_of_death!=='' ? e($place_of_death) : '-';
}
?>
</td>
<td><?= !empty($r['immediate_cause']) ? e($r['immediate_cause']) : '-' ?></td>
<td><?= !empty($r['antecedent_cause']) ? e($r['antecedent_cause']) : '-' ?></td>
<td><?= !empty($r['underlying_cause']) ? e($r['underlying_cause']) : '-' ?></td>
<td><?= !empty($r['other_significant_conditions']) ? e($r['other_significant_conditions']) : '-' ?></td>
<td>
<?php
$manner=trim($r['manner_of_death'] ?? '');
if($manner==='Other' && !empty($r['manner_of_death_other'])){
    echo e($r['manner_of_death_other']);
}else{
    echo $manner!=='' ? e($manner) : '-';
}
?>
</td>
<td><?= !empty($r['remarks']) ? e($r['remarks']) : '-' ?></td>
<td><?= !empty($r['doctor_name']) ? e($r['doctor_name']) : '-' ?></td>
<td><?= !empty($r['doctor_registration_no']) ? e($r['doctor_registration_no']) : '-' ?></td>
<td>
<?php if($r['status']==='Done'){ ?>
<span class="badge badge-done">Done</span>
<?php }elseif($r['status']==='Cancelled'){ ?>
<span class="badge badge-cancel">Cancelled</span>
<?php } ?>
</td>
<td>
<?= !empty($r['created_at']) ? date('d-m-Y h:i A',strtotime($r['created_at'])) : '-' ?>
</td>
<td>
<?= !empty($r['updated_by_name']) ? e($r['updated_by_name']) : '-' ?>
</td>
<td>
<?= !empty($r['updated_at']) ? date('d-m-Y h:i A',strtotime($r['updated_at'])) : '-' ?>
</td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
<script>

/* ============================================================
   OPEN DEATH CERTIFICATE POPUP
============================================================ */
function openDeathCertificatePopup(){

    const popup = document.getElementById('deathCertificatePopup');
    const frame = document.getElementById('deathCertificateFrame');

    // Load Add Death Certificate page inside iframe
    frame.src = 'add.php';

    // Show popup
    popup.style.display = 'block';

    // Stop background page scrolling
    document.body.classList.add('dc-popup-open');
}


/* ============================================================
   CLOSE DEATH CERTIFICATE POPUP
============================================================ */
function closeDeathCertificatePopup(){

    const popup = document.getElementById('deathCertificatePopup');
    const frame = document.getElementById('deathCertificateFrame');

    // Hide popup
    popup.style.display = 'none';

    // Clear iframe
    frame.src = '';

    // Restore background scrolling
    document.body.classList.remove('dc-popup-open');
}


/* ============================================================
   CLOSE POPUP AND REFRESH LIST
============================================================ */
function closeDeathCertificatePopupAndRefresh(){

    closeDeathCertificatePopup();

    // Refresh Death Certificate list
    window.location.reload();
}


/* ============================================================
   RECEIVE MESSAGE FROM save.php
============================================================ */
window.addEventListener("message", function(event){

    if(event.data === "death_certificate_saved"){

        closeDeathCertificatePopupAndRefresh();

    }

});


/* ============================================================
   CLOSE POPUP WITH ESC KEY
============================================================ */
document.addEventListener('keydown', function(event){

    if(event.key === 'Escape'){

        const popup = document.getElementById('deathCertificatePopup');

        if(popup && popup.style.display === 'block'){
            closeDeathCertificatePopup();
        }

    }

});


/* ============================================================
   CLOSE WHEN CLICKING DARK BACKGROUND
============================================================ */
document.getElementById('deathCertificatePopup')
.addEventListener('click', function(event){

    if(event.target === this){
        closeDeathCertificatePopup();
    }

});
function toggleActionMenu(button){

    const menu = button.nextElementSibling;

    document.querySelectorAll('.action-dropdown').forEach(function(item){
        if(item !== menu){
            item.style.display = 'none';
        }
    });

    if(menu.style.display === 'block'){
        menu.style.display = 'none';
    }else{
        menu.style.display = 'block';
    }
}
</script>