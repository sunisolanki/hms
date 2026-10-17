<?php
session_start();
ini_set('display_errors',1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if(!isset($_SESSION['user_id'])){
    header("Location:/index.php");
    exit;
}

if(!checkPermission('medical_certificate_list','view')){
    header("Location:/access_denied.php");
    exit;
}

$current_user_id=(int)$_SESSION['user_id'];

/* ============================================================
   CHECK WHETHER LOGGED USER IS DOCTOR
============================================================ */

$isDoctor=false;

$stmt=$conn->prepare("
SELECT
id
FROM doctor_master
WHERE user_id=?
LIMIT 1
");

$stmt->bind_param("i",$current_user_id);
$stmt->execute();
$isDoctor=$stmt->get_result()->num_rows>0;
$stmt->close();

/* ============================================================
   FILTERS
============================================================ */

$where=[];

/* Status */

if(!empty($_GET['status'])){

    $status=mysqli_real_escape_string($conn,$_GET['status']);
    $where[]="mc.status='$status'";

}

/* Doctor */

if(!empty($_GET['doctor_id'])){

    $doctor=(int)$_GET['doctor_id'];
    $where[]="mc.doctor_id=$doctor";

}

/* Patient Type */

if(!empty($_GET['patient_type'])){

    $type=mysqli_real_escape_string($conn,$_GET['patient_type']);
    $where[]="mc.patient_type='$type'";

}

/* Certificate Purpose */

if(!empty($_GET['purpose'])){

    $purpose=mysqli_real_escape_string($conn,$_GET['purpose']);
    $where[]="mc.purpose='$purpose'";

}

/* Certificate No */

if(!empty($_GET['certificate_no'])){

    $certificate=mysqli_real_escape_string($conn,$_GET['certificate_no']);
    $where[]="mc.certificate_no LIKE '%$certificate%'";

}

/* UHID */

if(!empty($_GET['uhid'])){

    $uhid=(int)$_GET['uhid'];
    $where[]="mc.uhid=$uhid";

}

/* Patient */

if(!empty($_GET['patient'])){

    $patient=mysqli_real_escape_string($conn,$_GET['patient']);
    $where[]="mc.patient_name LIKE '%$patient%'";

}

/* Mobile */

if(!empty($_GET['mobile'])){

    $mobile=mysqli_real_escape_string($conn,$_GET['mobile']);
    $where[]="mc.mobile LIKE '%$mobile%'";

}

/* Date From */

if(!empty($_GET['date_from'])){

    $date=mysqli_real_escape_string($conn,$_GET['date_from']);
    $where[]="mc.certificate_date>='$date'";

}

/* Date To */

if(!empty($_GET['date_to'])){

    $date=mysqli_real_escape_string($conn,$_GET['date_to']);
    $where[]="mc.certificate_date<='$date'";

}

/* Doctor Login Restriction */

if($isDoctor){

    $where[]="mc.doctor_id=".$current_user_id;

}

$whereSql="WHERE 1=1";

if(count($where)){

    $whereSql.=" AND ".implode(" AND ",$where);

}

/* ============================================================
   DROPDOWNS
============================================================ */

$doctorRes=mysqli_query($conn,"
SELECT
u.id,
u.full_name
FROM doctor_master dm
INNER JOIN users u
ON u.id=dm.user_id
ORDER BY u.full_name
");

$purposeRes=mysqli_query($conn,"
SELECT DISTINCT purpose
FROM medical_certificates
ORDER BY purpose
");

/* ============================================================
   MEDICAL CERTIFICATE LIST
============================================================ */
$sql="
SELECT
mc.id,
mc.certificate_no,
mc.certificate_date,
mc.patient_type,
mc.uhid,
mc.patient_name,
mc.guardian_name,
mc.mobile,
mc.dob,
mc.age_y,
mc.age_m,
mc.age_d,
mc.age,
mc.sex,
mc.marital_status,
mc.address,
mc.reference_no,
mc.purpose,
mc.purpose_other,
mc.issued_to,
mc.issued_to_other,
mc.suffering_from,
mc.rest_from,
mc.rest_to,
mc.joining_date,
mc.fitness_status,
mc.remarks,
mc.doctor_id,
mc.doctor_name,
mc.doctor_registration_no,
mc.status,
mc.created_at,
mc.updated_by,
mc.updated_at,
u_update.full_name AS updated_by_name
FROM medical_certificates mc

LEFT JOIN users u_update
ON u_update.id=mc.updated_by
$whereSql
ORDER BY
mc.certificate_date DESC,
mc.id DESC
";
$res=mysqli_query($conn,$sql);

/* ============================================================
   ESCAPE
============================================================ */

function e($value){

    return htmlspecialchars((string)$value);

}

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<title>Medical Certificate</title>

<meta
name="viewport"
content="width=device-width, initial-scale=1">

<link
rel="stylesheet"
href="/assets/css/style.css">

<link
rel="icon"
href="/assets/img/hms_logo.png">

<style>

.main-content{
width:auto;
overflow:hidden;
}

.page-container{
padding:8px;
}
.mc-popup{
display:none;
position:fixed;
inset:0;
background:rgba(0,0,0,.55);
z-index:99999;
}
.mc-popup-box{
width:100%;
height:99vh;
margin:auto;
background:#fff;
border-radius:10px;
overflow:hidden;
box-shadow:0 10px 40px rgba(0,0,0,.3);
}
.mc-popup-header{
height:50px;
display:flex;
align-items:center;
padding:0 15px;
background:#1976d2;
color:#fff;
}
.mc-popup-title{
flex:1;
font-size:17px;
font-weight:600;
}
.mc-popup-close{
width:34px;
height:34px;
border:0;
background:transparent;
color:#fff;
font-size:28px;
line-height:28px;
cursor:pointer;
}
.mc-popup-body{
height:calc(94vh - 50px);
}
.mc-popup-body iframe{
width:100%;
height:100%;
border:0;
display:block;
}
body.mc-popup-open{
overflow:hidden;
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
width:120px;
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
height:calc(100vh - 222px);
}

table{
width:100%;
min-width:1700px;
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

.badge-draft{
background:#f59e0b;
}

.badge-final{
background:#16a34a;
}
.badge-cancel{
background:#dc2626;
}
.action-menu{
position:relative;
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
left:0;
top:28px;
min-width:190px;
background:#fff;
border:1px solid #ddd;
border-radius:5px;
box-shadow:0 5px 15px rgba(0,0,0,.15);
z-index:999;
}
.action-dropdown a{
display:block;
padding:8px 10px;
text-decoration:none;
color:#222;
}
.action-dropdown a:hover{
background:#f5f5f5;
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
<h2 style="margin:0;">
🏥 Medical Certificate
</h2>
</div>
<div style="flex:1;text-align:right;">
<?php if(checkPermission('medical_certificate','add')){ ?>
<button
type="button"
onclick="openMedicalCertificatePopup()">
+ Add Medical Certificate
</button>
<?php } ?>
</div>
</div>
<div class="filter-box">
<form method="get">
<select
name="patient_type"
onchange="this.form.submit()">

<option value="">
Patient Type
</option>

<option
value="Direct"
<?= (($_GET['patient_type']??'')=='Direct')?'selected':''; ?>>
Direct
</option>

<option
value="OPD"
<?= (($_GET['patient_type']??'')=='OPD')?'selected':''; ?>>
OPD
</option>

<option
value="IPD"
<?= (($_GET['patient_type']??'')=='IPD')?'selected':''; ?>>
IPD
</option>

</select>

<select
name="doctor_id"
onchange="this.form.submit()">

<option value="">
Doctor
</option>

<?php while($doc=mysqli_fetch_assoc($doctorRes)){ ?>

<option
value="<?= $doc['id'] ?>"
<?= (($_GET['doctor_id']??'')==$doc['id'])?'selected':''; ?>>

<?= e($doc['full_name']) ?>

</option>

<?php } ?>

</select>

<select
name="purpose"
onchange="this.form.submit()">

<option value="">
Purpose
</option>

<?php while($p=mysqli_fetch_assoc($purposeRes)){ ?>

<option
value="<?= e($p['purpose']) ?>"
<?= (($_GET['purpose']??'')==$p['purpose'])?'selected':''; ?>>

<?= e($p['purpose']) ?>

</option>

<?php } ?>

</select>

<select
name="status"
onchange="this.form.submit()">

<option value="">
Status
</option>

<option
value="Done"
<?= (($_GET['status']??'')=='Done')?'selected':''; ?>>
Done
</option>

<option
value="Cancelled"
<?= (($_GET['status']??'')=='Cancelled')?'selected':''; ?>>
Cancelled
</option>
</select>

<input
type="text"
name="certificate_no"
placeholder="Certificate No"
value="<?= e($_GET['certificate_no']??'') ?>">

<input
type="text"
name="uhid"
placeholder="UHID"
value="<?= e($_GET['uhid']??'') ?>">

<input
type="text"
name="patient"
placeholder="Patient Name"
value="<?= e($_GET['patient']??'') ?>">

<input
type="text"
name="mobile"
placeholder="Mobile"
value="<?= e($_GET['mobile']??'') ?>">

<input
type="date"
name="date_from"
value="<?= e($_GET['date_from']??'') ?>">

<input
type="date"
name="date_to"
value="<?= e($_GET['date_to']??'') ?>">

<button type="submit">

Search

</button>

<button
type="button"
onclick="window.location='medical_certificate.php'">

Reset

</button>

</form>

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
<th>Reference No</th>
<th>Purpose</th>
<th>Purpose Other</th>
<th>Issued To</th>
<th>Issued To Other</th>
<th>Suffering From</th>
<th>Rest From</th>
<th>Rest To</th>
<th>Joining Date</th>
<th>Fitness Status</th>
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
<td colspan="31" style="text-align:center;padding:25px;">
No Medical Certificates Found
</td>
</tr>
<?php } ?>

<?php while($r=mysqli_fetch_assoc($res)){ ?>

<tr>

<td>
<div class="action-menu">
<button type="button" class="action-btn" onclick="toggleActionMenu(this)">⋮</button>
<div class="action-dropdown">
<a href="print.php?id=<?= $r['id'] ?>" target="_blank">🖨 Print</a>
<?php if($r['status']=="Done" && checkPermission('medical_certificate','edit')){ ?>
<a href="cancel.php?id=<?= (int)$r['id'] ?>" onclick="return confirm('Are you sure you want to cancel this Medical Certificate?')">❌ Cancel</a>
<?php } ?>
</div>
</div>
</td>
<td><?= (int)$r['id'] ?></td>
<td><?= e($r['certificate_no']) ?></td>
<td><?= !empty($r['certificate_date']) ? date("d-m-Y",strtotime($r['certificate_date'])) : '' ?></td>
<td><?= e($r['patient_type']) ?></td>
<td><?= e($r['uhid']) ?></td>
<td><?= e($r['patient_name']) ?></td>
<td><?= e($r['guardian_name']) ?></td>
<td><?= e($r['mobile']) ?></td>
<td><?= !empty($r['dob']) ? date("d-m-Y",strtotime($r['dob'])) : '' ?></td>
<td><?= e($r['age']) ?></td>
<td><?= e($r['sex']) ?></td>
<td><?= e($r['marital_status']) ?></td>
<td><?= e($r['address']) ?></td>
<td><?= e($r['reference_no']) ?></td>
<td><?= e($r['purpose']) ?></td>
<td><?= e($r['purpose_other']) ?></td>
<td><?= e($r['issued_to']) ?></td>
<td><?= e($r['issued_to_other']) ?></td>
<td><?= e($r['suffering_from']) ?></td>
<td><?= !empty($r['rest_from']) ? date("d-m-Y",strtotime($r['rest_from'])) : '' ?></td>
<td><?= !empty($r['rest_to']) ? date("d-m-Y",strtotime($r['rest_to'])) : '' ?></td>
<td><?= !empty($r['joining_date']) ? date("d-m-Y",strtotime($r['joining_date'])) : '' ?></td>
<td><?= e($r['fitness_status']) ?></td>
<td><?= e($r['remarks']) ?></td>
<td><?= e($r['doctor_name']) ?></td>
<td><?= e($r['doctor_registration_no']) ?></td>
<td>
<?php if($r['status']=="Done"){ ?>
<span class="badge badge-final">Done</span>
<?php } ?>
<?php if($r['status']=="Cancelled"){ ?>
<span class="badge badge-cancel">Cancelled</span>
<?php } ?>
</td>
<td><?= !empty($r['created_at']) ? date("d-m-Y h:i A",strtotime($r['created_at'])) : '' ?></td>
<td>
<?= !empty($r['updated_by_name']) ? e($r['updated_by_name']) : '-' ?>
</td>
<td>
<?= !empty($r['updated_at']) ? date("d-m-Y h:i A",strtotime($r['updated_at'])) : '-' ?>
</td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
</div>
<script>

function toggleActionMenu(btn){
document.querySelectorAll('.action-dropdown').forEach(function(menu){
if(menu!==btn.nextElementSibling){
menu.style.display='none';
}
});
let current=btn.nextElementSibling;
if(current.style.display==="block"){
current.style.display="none";
}else{
current.style.display="block";
}
}

document.addEventListener("click",function(e){
if(!e.target.closest(".action-menu")){
document.querySelectorAll(".action-dropdown").forEach(function(menu){
menu.style.display="none";
});
}
});

function confirmDelete(id){
if(confirm("Are you sure you want to delete this Medical Certificate?")){
window.location="delete.php?id="+id;
}
}

function refreshPage(){
window.location="medical_certificate.php";
}

function printCertificate(id){
window.open("print.php?id="+id,"_blank");
}

function viewCertificate(id){
window.location="view.php?id="+id;
}

function editCertificate(id){
window.location="edit.php?id="+id;
}


document.addEventListener("keydown",function(e){
if(e.key==="Escape"){
document.querySelectorAll(".action-dropdown").forEach(function(menu){
menu.style.display="none";
});
}
});
function openMedicalCertificatePopup(){
document.getElementById("medicalCertificateFrame").src="add.php?popup=1";
document.getElementById("medicalCertificatePopup").style.display="flex";
document.body.classList.add("mc-popup-open");
}
function closeMedicalCertificatePopup(){
document.getElementById("medicalCertificatePopup").style.display="none";
document.getElementById("medicalCertificateFrame").src="";
document.body.classList.remove("mc-popup-open");
}
document.addEventListener("keydown",function(e){
if(e.key==="Escape"){
closeMedicalCertificatePopup();
}
});
</script>

</div>
<div id="medicalCertificatePopup" class="mc-popup">
<div class="mc-popup-box">
<div class="mc-popup-header">
<div class="mc-popup-title">Add Medical Certificate</div>
<button type="button" class="mc-popup-close" onclick="closeMedicalCertificatePopup()">×</button>
</div>
<div class="mc-popup-body">
<iframe id="medicalCertificateFrame" src="" frameborder="0"></iframe>
</div>
</div>
</div>

</body>
</html>
