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
$where[]="nc.status='$status'";
}
if(!empty($_GET['doctor_id'])){
$doctor_id=(int)$_GET['doctor_id'];
$where[]="nc.doctor_id=$doctor_id";
}
if(!empty($_GET['certificate_no'])){
$certificate_no=mysqli_real_escape_string($conn,$_GET['certificate_no']);
$where[]="nc.certificate_no LIKE '%$certificate_no%'";
}
if(!empty($_GET['ipd_no'])){
$ipd_no=mysqli_real_escape_string($conn,$_GET['ipd_no']);
$where[]="nc.ipd_no LIKE '%$ipd_no%'";
}
if(!empty($_GET['uhid'])){
$uhid=(int)$_GET['uhid'];
$where[]="nc.uhid=$uhid";
}
if(!empty($_GET['mother'])){
$mother=mysqli_real_escape_string($conn,$_GET['mother']);
$where[]="nc.mother_name LIKE '%$mother%'";
}
if(!empty($_GET['mobile'])){
$mobile=mysqli_real_escape_string($conn,$_GET['mobile']);
$where[]="nc.mobile LIKE '%$mobile%'";
}
if(!empty($_GET['delivery_type'])){
$delivery_type=mysqli_real_escape_string($conn,$_GET['delivery_type']);
$where[]="nc.delivery_type='$delivery_type'";
}
if(!empty($_GET['baby_gender'])){
$baby_gender=mysqli_real_escape_string($conn,$_GET['baby_gender']);
$where[]="nc.baby_gender='$baby_gender'";
}
if(!empty($_GET['date_from'])){
$date_from=mysqli_real_escape_string($conn,$_GET['date_from']);
$where[]="nc.delivery_date>='$date_from'";
}
if(!empty($_GET['date_to'])){
$date_to=mysqli_real_escape_string($conn,$_GET['date_to']);
$where[]="nc.delivery_date<='$date_to'";
}
/* Doctor Login Restriction */
if($isDoctor){
$where[]="nc.doctor_id=".$logged_doctor_id;
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
   NEW BORN CERTIFICATE LIST
============================================================ */
$sql="
SELECT
nc.id,
nc.certificate_no,
nc.certificate_date,
nc.patient_id,
nc.ipd_admission_id,
nc.ipd_no,
nc.uhid,
nc.mother_name,
nc.guardian_name,
nc.mobile,
nc.dob,
nc.age_y,
nc.age_m,
nc.age_d,
nc.age,
nc.sex,
nc.marital_status,
nc.address,
nc.admission_date,
nc.admission_time,
nc.department_id,
nc.department_name,
nc.doctor_id,
nc.doctor_name,
nc.doctor_registration_no,
nc.room_ward_type,
nc.bed_detail,
nc.first_pregnancy,
nc.anaesthesia,
nc.lead_surgeon_id,
nc.lead_surgeon_name,
nc.second_surgeon_id,
nc.second_surgeon_name,
nc.assistant_id,
nc.assistant_name,
nc.delivery_type,
nc.patient_status,
nc.last_child_age,
nc.born_child,
nc.alive_child,
nc.still_born_count,
nc.baby_gender,
nc.baby_weight,
nc.baby_length,
nc.delivery_date,
nc.delivery_time,
nc.remarks,
nc.status,
nc.created_by,
nc.created_at,
nc.updated_by,
nc.updated_at,
u_create.full_name AS created_by_name,
u_update.full_name AS updated_by_name
FROM newborn_certificates nc
LEFT JOIN users u_create
ON u_create.id=nc.created_by
LEFT JOIN users u_update
ON u_update.id=nc.updated_by
$whereSql
ORDER BY
nc.delivery_date DESC,
nc.delivery_time DESC,
nc.id DESC
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
<title>New Born Certificate</title>
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
width:140px;
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
min-width:3900px;
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
.cancel-menu-btn{
display:block;
width:100%;
padding:8px 10px;
border:0;
background:#fff;
color:#222;
text-align:left;
font-size:13px;
cursor:pointer;
}
.cancel-menu-btn:hover{
background:#f5f5f5;
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
.badge-yes{
background:#16a34a;
}
.badge-no{
background:#64748b;
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
/* ADD POPUP */
.nb-popup{
display:none;
position:fixed;
inset:0;
background:rgba(0,0,0,.55);
z-index:99999;
padding:0;
}
.nb-popup-box{
width:100%;
height:100vh;
margin:auto;
background:#fff;
border-radius:10px;
overflow:hidden;
box-shadow:0 10px 40px rgba(0,0,0,.3);
}
.nb-popup-header{
height:50px;
display:flex;
align-items:center;
padding:0 15px;
background:#1976d2;
color:#fff;
}
.nb-popup-title{
flex:1;
font-size:17px;
font-weight:600;
}
.nb-popup-close{
width:34px;
height:34px;
border:0;
background:transparent;
color:#fff;
font-size:28px;
line-height:28px;
cursor:pointer;
}
.nb-popup-body{
height:calc(94vh - 50px);
}
.nb-popup-body iframe{
width:100%;
height:100%;
border:0;
display:block;
}
body.nb-popup-open{
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
<h2 style="margin:0;">New Born Certificate</h2>
</div>
<div style="flex:1;text-align:right;">
<?php if(checkPermission('newborn_certificate','add')){ ?>
<button type="button" onclick="openNewBornCertificatePopup()">➕ Add New Born Certificate</button>
<?php } ?>
</div>
</div>
<div class="filter-box">
<form method="get">
<select name="doctor_id" onchange="this.form.submit()">
<option value="">Doctor</option>
<?php while($doc=mysqli_fetch_assoc($doctorRes)){ ?>
<option value="<?= (int)$doc['id'] ?>" <?= ((string)($_GET['doctor_id']??'')===(string)$doc['id'])?'selected':''; ?>>
<?= e($doc['full_name']) ?>
</option>
<?php } ?>
</select>
<select name="delivery_type" onchange="this.form.submit()">
<option value="">Delivery Type</option>
<option value="Normal" <?= (($_GET['delivery_type']??'')==='Normal')?'selected':''; ?>>Normal</option>
<option value="Caesarean" <?= (($_GET['delivery_type']??'')==='Caesarean')?'selected':''; ?>>Caesarean</option>
<option value="Forceps" <?= (($_GET['delivery_type']??'')==='Forceps')?'selected':''; ?>>Forceps</option>
<option value="Vacuum" <?= (($_GET['delivery_type']??'')==='Vacuum')?'selected':''; ?>>Vacuum</option>
<option value="Other" <?= (($_GET['delivery_type']??'')==='Other')?'selected':''; ?>>Other</option>
</select>
<select name="baby_gender" onchange="this.form.submit()">
<option value="">Baby Gender</option>
<option value="Male" <?= (($_GET['baby_gender']??'')==='Male')?'selected':''; ?>>Male</option>
<option value="Female" <?= (($_GET['baby_gender']??'')==='Female')?'selected':''; ?>>Female</option>
<option value="Other" <?= (($_GET['baby_gender']??'')==='Other')?'selected':''; ?>>Other</option>
</select>
<select name="status" onchange="this.form.submit()">
<option value="">Status</option>
<option value="Done" <?= (($_GET['status']??'')==='Done')?'selected':''; ?>>Done</option>
<option value="Cancelled" <?= (($_GET['status']??'')==='Cancelled')?'selected':''; ?>>Cancelled</option>
</select>
<input type="text" name="certificate_no" placeholder="Certificate No" value="<?= e($_GET['certificate_no']??'') ?>">
<input type="text" name="ipd_no" placeholder="IP No." value="<?= e($_GET['ipd_no']??'') ?>">
<input type="text" name="uhid" placeholder="UHID" value="<?= e($_GET['uhid']??'') ?>">
<input type="text" name="mother" placeholder="Mother Name" value="<?= e($_GET['mother']??'') ?>">
<input type="text" name="mobile" placeholder="Mobile" value="<?= e($_GET['mobile']??'') ?>">
<input type="date" name="date_from" value="<?= e($_GET['date_from']??'') ?>">
<input type="date" name="date_to" value="<?= e($_GET['date_to']??'') ?>">
<button type="submit">Search</button>
<button type="button" onclick="window.location='newborn_certificate.php'">Reset</button>
</form>
</div>
<div id="newBornCertificatePopup" class="nb-popup">
<div class="nb-popup-box">
<div class="nb-popup-header">
<div class="nb-popup-title">Add New Born Certificate</div>
<button type="button" class="nb-popup-close" onclick="closeNewBornCertificatePopup()">×</button>
</div>
<div class="nb-popup-body">
<iframe id="newBornCertificateFrame" src="" frameborder="0"></iframe>
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
<th>IP No.</th>
<th>UHID</th>
<th>Mother Name</th>
<th>Guardian Name</th>
<th>Mobile</th>
<th>DOB</th>
<th>Age</th>
<th>Gender</th>
<th>Marital Status</th>
<th>Address</th>
<th>Admission Date</th>
<th>Admission Time</th>
<th>Department</th>
<th>Doctor</th>
<th>Doctor Reg. No.</th>
<th>Room/Ward Type</th>
<th>Bed Detail</th>
<th>First Pregnancy</th>
<th>Anaesthesia</th>
<th>Lead Surgeon</th>
<th>Second Surgeon</th>
<th>Assistant</th>
<th>Delivery Type</th>
<th>Patient Status</th>
<th>Last Child Age</th>
<th>Born Child</th>
<th>Alive Child</th>
<th>Still Born / Intrapartum Death</th>
<th>Baby Gender</th>
<th>Baby Weight</th>
<th>Baby Length</th>
<th>Delivery Date</th>
<th>Delivery Time</th>
<th>Remarks</th>
<th>Status</th>
<th>Created By</th>
<th>Created At</th>
<th>Updated By</th>
<th>Updated At</th>
</tr>
</thead>
<tbody>
<?php if(mysqli_num_rows($res)==0){ ?>
<tr>
<td colspan="43" style="text-align:center;padding:25px;">
No New Born Certificates Found
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
<?php if($r['status']==='Done'){ ?>
<form method="POST"
action="cancel.php"
style="margin:0;"
onsubmit="return confirm('Are you sure you want to cancel this New Born Certificate?');">
<input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
<?php if(checkPermission('newborn_certificate','edit')){ ?>
<button type="submit" class="cancel-menu-btn">❌ Cancel</button>
<?php } ?>
</form>
<?php } ?>
</div>
</div>
</td>
<td><?= (int)$r['id'] ?></td>
<td><?= e($r['certificate_no']) ?></td>
<td><?= !empty($r['certificate_date']) ? date('d-m-Y',strtotime($r['certificate_date'])) : '-' ?></td>
<td><?= !empty($r['ipd_no']) ? e($r['ipd_no']) : '-' ?></td>
<td><?= !empty($r['uhid']) ? e($r['uhid']) : '-' ?></td>
<td><?= !empty($r['mother_name']) ? e($r['mother_name']) : '-' ?></td>
<td><?= !empty($r['guardian_name']) ? e($r['guardian_name']) : '-' ?></td>
<td><?= !empty($r['mobile']) ? e($r['mobile']) : '-' ?></td>
<td><?= !empty($r['dob']) ? date('d-m-Y',strtotime($r['dob'])) : '-' ?></td>
<td><?= !empty($r['age']) ? e($r['age']) : '-' ?></td>
<td><?= !empty($r['sex']) ? e($r['sex']) : '-' ?></td>
<td><?= !empty($r['marital_status']) ? e($r['marital_status']) : '-' ?></td>
<td><?= !empty($r['address']) ? e($r['address']) : '-' ?></td>
<td><?= !empty($r['admission_date']) ? date('d-m-Y',strtotime($r['admission_date'])) : '-' ?></td>
<td><?= !empty($r['admission_time']) ? date('h:i A',strtotime($r['admission_time'])) : '-' ?></td>
<td><?= !empty($r['department_name']) ? e($r['department_name']) : '-' ?></td>
<td><?= !empty($r['doctor_name']) ? e($r['doctor_name']) : '-' ?></td>
<td><?= !empty($r['doctor_registration_no']) ? e($r['doctor_registration_no']) : '-' ?></td>
<td><?= !empty($r['room_ward_type']) ? e($r['room_ward_type']) : '-' ?></td>
<td><?= !empty($r['bed_detail']) ? e($r['bed_detail']) : '-' ?></td>
<td>
<?php if((int)$r['first_pregnancy']===1){ ?>
<span class="badge badge-yes">Yes</span>
<?php }else{ ?>
<span class="badge badge-no">No</span>
<?php } ?>
</td>
<td>
<?php if((int)$r['anaesthesia']===1){ ?>
<span class="badge badge-yes">Yes</span>
<?php }else{ ?>
<span class="badge badge-no">No</span>
<?php } ?>
</td>
<td><?= !empty($r['lead_surgeon_name']) ? e($r['lead_surgeon_name']) : '-' ?></td>
<td><?= !empty($r['second_surgeon_name']) ? e($r['second_surgeon_name']) : '-' ?></td>
<td><?= !empty($r['assistant_name']) ? e($r['assistant_name']) : '-' ?></td>
<td><?= !empty($r['delivery_type']) ? e($r['delivery_type']) : '-' ?></td>
<td><?= !empty($r['patient_status']) ? e($r['patient_status']) : '-' ?></td>
<td><?= !empty($r['last_child_age']) ? e($r['last_child_age']) : '-' ?></td>
<td><?= (int)$r['born_child'] ?></td>
<td><?= (int)$r['alive_child'] ?></td>
<td><?= (int)$r['still_born_count'] ?></td>
<td><?= !empty($r['baby_gender']) ? e($r['baby_gender']) : '-' ?></td>
<td>
<?= $r['baby_weight']!==null ? e($r['baby_weight']).' kg' : '-' ?>
</td>
<td>
<?= $r['baby_length']!==null ? e($r['baby_length']).' cm' : '-' ?>
</td>
<td><?= !empty($r['delivery_date']) ? date('d-m-Y',strtotime($r['delivery_date'])) : '-' ?></td>
<td><?= !empty($r['delivery_time']) ? date('h:i A',strtotime($r['delivery_time'])) : '-' ?></td>
<td><?= !empty($r['remarks']) ? e($r['remarks']) : '-' ?></td>
<td>
<?php if($r['status']==='Done'){ ?>
<span class="badge badge-done">Done</span>
<?php }elseif($r['status']==='Cancelled'){ ?>
<span class="badge badge-cancel">Cancelled</span>
<?php } ?>
</td>
<td><?= !empty($r['created_by_name']) ? e($r['created_by_name']) : '-' ?></td>
<td><?= !empty($r['created_at']) ? date('d-m-Y h:i A',strtotime($r['created_at'])) : '-' ?></td>
<td><?= !empty($r['updated_by_name']) ? e($r['updated_by_name']) : '-' ?></td>
<td><?= !empty($r['updated_at']) ? date('d-m-Y h:i A',strtotime($r['updated_at'])) : '-' ?></td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
</div>
</div>
<script>
function openNewBornCertificatePopup(){
const popup=document.getElementById('newBornCertificatePopup');
const frame=document.getElementById('newBornCertificateFrame');
frame.src='add.php';
popup.style.display='block';
document.body.classList.add('nb-popup-open');
}
function closeNewBornCertificatePopup(){
const popup=document.getElementById('newBornCertificatePopup');
const frame=document.getElementById('newBornCertificateFrame');
popup.style.display='none';
frame.src='';
document.body.classList.remove('nb-popup-open');
}
function closeNewBornCertificatePopupAndRefresh(){
closeNewBornCertificatePopup();
window.location.reload();
}
window.addEventListener("message",function(event){
if(event.data==="newborn_certificate_saved"){
closeNewBornCertificatePopupAndRefresh();
}
});
document.addEventListener('keydown',function(event){
if(event.key==='Escape'){
const popup=document.getElementById('newBornCertificatePopup');
if(popup && popup.style.display==='block'){
closeNewBornCertificatePopup();
}
}
});
document.getElementById('newBornCertificatePopup').addEventListener('click',function(event){
if(event.target===this){
closeNewBornCertificatePopup();
}
});
function toggleActionMenu(button){
document.querySelectorAll('.action-dropdown').forEach(function(menu){
if(menu!==button.nextElementSibling){
menu.style.display='none';
}
});
const menu=button.nextElementSibling;
menu.style.display=(menu.style.display==='block')?'none':'block';
}
document.addEventListener('click',function(event){
if(!event.target.closest('.action-menu')){
document.querySelectorAll('.action-dropdown').forEach(function(menu){
menu.style.display='none';
});
}
});
</script>
</body>
</html>