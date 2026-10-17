<?php
session_start();
date_default_timezone_set('Asia/Kolkata');
ini_set('display_errors',1);
error_reporting(E_ALL);
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if(!isset($_SESSION['user_id'])){
    header("Location:/login/login.php");
    exit;
}
if(!checkPermission('ipd_admission','edit')){
    header("Location:/access_denied.php");
    exit;
}
$user_id   = (int)$_SESSION['user_id'];
$branch_id = (int)$_SESSION['branch_id'];
$appointment_id = isset($_GET['appointment_id'])
    ? (int)$_GET['appointment_id']
    : 0;
if($appointment_id<=0){
    die("Invalid Appointment.");
}
/*---------------------------------------------------
GET HOSPITAL FROM BRANCH
---------------------------------------------------*/
$stmt = $conn->prepare("
SELECT
hospital_id,
branch_name
FROM branch_master
WHERE id=?
LIMIT 1
");

$stmt->bind_param("i",$branch_id);
$stmt->execute();
$branch = $stmt->get_result()->fetch_assoc();
if(!$branch){
    die("Branch not found.");
}
$hospital_id = $branch['hospital_id'];
$branch_name = $branch['branch_name'];
/*---------------------------------------------------
LOAD APPOINTMENT
---------------------------------------------------*/
$stmt = $conn->prepare("
SELECT
a.*,
p.uhid,
p.abha_id,
u.full_name AS doctor_name,
d.department_name,
c.name AS city_name
FROM appointments a
LEFT JOIN patient_master p
ON p.id=a.patient_id
LEFT JOIN users u
ON u.id=a.entity_id
LEFT JOIN department_master d
ON d.id=a.department_id
LEFT JOIN cities c
ON c.id=a.city_id
WHERE a.id=?
LIMIT 1
");

$stmt->bind_param("i",$appointment_id);
$stmt->execute();

$appointment = $stmt->get_result()->fetch_assoc();
if(!$appointment){
    die("Appointment not found.");
}
/*---------------------------------------------------
LOAD ADMISSION
---------------------------------------------------*/
$stmt = $conn->prepare("
SELECT *
FROM ipd_admissions
WHERE appointment_id=?
LIMIT 1
");
$stmt->bind_param("i",$appointment_id);
$stmt->execute();
$admission = $stmt->get_result()->fetch_assoc();
if(!$admission){
    die("IPD Admission not found.");
}
/*---------------------------------------------------
CURRENT ROOM ALLOCATION
---------------------------------------------------*/
$stmt = $conn->prepare("
SELECT
ira.*,
bm.building_name,
fm.floor_name,
rm.room_number,
rm.room_name,
bed.bed_number
FROM ipd_room_allocation ira
LEFT JOIN building_master bm
ON bm.id=ira.building_id
LEFT JOIN floor_master fm
ON fm.id=ira.floor_id
LEFT JOIN room_master rm
ON rm.id=ira.room_id
LEFT JOIN bed_master bed
ON bed.id=ira.bed_id
WHERE ira.admission_id=?
AND ira.status='Occupied'
LIMIT 1
");
$stmt->bind_param("i",$admission['id']);
$stmt->execute();
$currentRoom = $stmt->get_result()->fetch_assoc();
if(!$currentRoom){
    die("Current room allocation not found.");
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Shift IPD Patient</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" href="/assets/img/hms_logo.png">
<style>
.page-container{
padding:10px;
max-width:1400px;
margin:auto;
}
.card{
background:#fff;
border-radius:6px;
padding:12px 15px;
margin-bottom:12px;
box-shadow:0 1px 4px rgba(0,0,0,.08);
border:1px solid #e8e8e8;
}
.card h3{
margin:0 0 10px;
color:#1976d2;
font-size:15px;
padding-bottom:6px;
border-bottom:1px solid #e8e8e8;
}
.info-grid{
display:grid;
grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
gap:8px 15px;
}
.info-item{
display:flex;
align-items:baseline;
}
.info-item .label{
min-width:90px;
font-weight:600;
font-size:12px;
color:#555;
}
.info-item .value{
font-size:13px;
color:#222;
}
.form-grid{
display:grid;
grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
gap:10px 15px;
}
.form-group{
display:flex;
flex-direction:column;
}
.form-group label{
font-size:12px;
font-weight:600;
margin-bottom:3px;
}
.form-group input,
.form-group select,
.form-group textarea{
padding:6px 8px;
border:1px solid #ccc;
border-radius:4px;
font-size:13px;
}
.btn{
padding:8px 20px;
border:none;
border-radius:4px;
cursor:pointer;
font-weight:600;
}
.btn-primary{
background:#1976d2;
color:#fff;
}
.btn-secondary{
background:#666;
color:#fff;
}
</style>
</head>
<body>
<div class="page-container">
<h2>🏥 Shift IPD Patient</h2>
<form method="post"
action="save_shift_patient.php">
<input type="hidden" name="appointment_id" value="<?= $appointment_id ?>">
<input type="hidden" name="admission_id" value="<?= $admission['id'] ?>">
<input type="hidden" name="patient_id" value="<?= $appointment['patient_id'] ?>">
<input type="hidden" name="old_building_id" value="<?= $currentRoom['building_id'] ?>">
<input type="hidden" name="old_floor_id" value="<?= $currentRoom['floor_id'] ?>">
<input type="hidden" name="old_room_id" value="<?= $currentRoom['room_id'] ?>">
<input type="hidden" name="old_bed_id" value="<?= $currentRoom['bed_id'] ?>">
<div class="card">
<h3>Patient Information</h3>
<div class="info-grid">
<div class="info-item">
<span class="label">UHID :</span>
<span class="value"><?= htmlspecialchars($appointment['uhid']) ?></span>
</div>
<div class="info-item">
<span class="label">OPD No :</span>
<span class="value"><?= htmlspecialchars($appointment['opd_no']) ?></span>
</div>
<div class="info-item">
<span class="label">IPD No :</span>
<span class="value"><?= htmlspecialchars($admission['ipd_no']) ?></span>
</div>
<div class="info-item">
<span class="label">Patient :</span>
<span class="value"><?= htmlspecialchars($appointment['title'].' '.$appointment['first_name'].' '.$appointment['last_name']) ?></span>
</div>
<div class="info-item">
<span class="label">Gender :</span>
<span class="value"><?= htmlspecialchars($appointment['sex']) ?></span>
</div>
<div class="info-item">
<span class="label">Age :</span>
<span class="value"><?= $appointment['age_y'] ?>Y <?= $appointment['age_m'] ?>M <?= $appointment['age_d'] ?>D</span>
</div>
<div class="info-item">
<span class="label">Mobile :</span>
<span class="value"><?= htmlspecialchars($appointment['mobile']) ?></span>
</div>
<div class="info-item">
<span class="label">Doctor :</span>
<span class="value"><?= htmlspecialchars($appointment['doctor_name']) ?></span>
</div>
<div class="info-item">
<span class="label">Department :</span>
<span class="value"><?= htmlspecialchars($appointment['department_name']) ?></span>
</div>
<div class="info-item">
<span class="label">Admission :</span>
<span class="value"><?= date('d-m-Y',strtotime($admission['admission_date'])) ?></span>
</div>
<div class="info-item">
<span class="label">Branch :</span>
<span class="value"><?= htmlspecialchars($branch_name) ?></span>
</div>
<div class="info-item">
<span class="label">City :</span>
<span class="value"><?= htmlspecialchars($appointment['city_name']) ?></span>
</div>
<div class="info-item" style="grid-column:1/-1;">
<span class="label">Address :</span>
<span class="value"><?= nl2br(htmlspecialchars($appointment['address'])) ?></span>
</div>
</div>
</div>

<div class="card">
<h3>Current Room Allocation</h3>
<div class="info-grid">
<div class="info-item">
<span class="label">Building :</span>
<span class="value"><?= htmlspecialchars($currentRoom['building_name']) ?></span>
</div>
<div class="info-item">
<span class="label">Floor :</span>
<span class="value"><?= htmlspecialchars($currentRoom['floor_name']) ?></span>
</div>
<div class="info-item">
<span class="label">Room :</span>
<span class="value"><?= htmlspecialchars($currentRoom['room_number'].' - '.$currentRoom['room_name']) ?></span>
</div>
<div class="info-item">
<span class="label">Bed :</span>
<span class="value"><?= htmlspecialchars($currentRoom['bed_number']) ?></span>
</div>
<div class="info-item">
<span class="label">Allocated On :</span>
<span class="value"><?= date('d-m-Y H:i',strtotime($currentRoom['allocated_date'])) ?></span>
</div>
<div class="info-item">
<span class="label">Status :</span>
<span class="value" style="color:green;font-weight:bold;">
<?= htmlspecialchars($currentRoom['status']) ?>
</span>
</div>
</div>
</div>

<div class="card">
<h3>New Room Allocation</h3>
<div class="form-grid">

<div class="form-group">
<label>Building *</label>
<select name="building_id" id="building_id" required>
<option value="">Select Building</option>
<?php
$bq=mysqli_query($conn,"
SELECT id,building_name
FROM building_master
WHERE hospital_id='$hospital_id'
AND branch_id='$branch_id'
AND status='Active'
ORDER BY building_name
");
while($b=mysqli_fetch_assoc($bq)){
?>
<option value="<?= $b['id'] ?>">
<?= htmlspecialchars($b['building_name']) ?>
</option>
<?php } ?>
</select>
</div>
<div class="form-group">
<label>Floor *</label>
<select name="floor_id" id="floor_id" required>
<option value="">Select Floor</option>
</select>
</div>
<div class="form-group">
<label>Room *</label>
<select name="room_id" id="room_id" required>
<option value="">Select Room</option>
</select>
</div>
<div class="form-group">
<label>Bed *</label>
<select name="bed_id" id="bed_id" required>
<option value="">Select Bed</option>
</select>
</div>
<div class="form-group">
<label>Shift Date *</label>
<input type="date" name="shift_date" value="<?= date('Y-m-d') ?>" required>
</div>
<div class="form-group">
<label>Shift Time *</label>
<input type="time" name="shift_time" value="<?= date('H:i') ?>" required>
</div>
<div class="form-group" style="grid-column:1/-1;">
<label>Shift Reason *</label>
<textarea name="shift_reason" rows="3" required placeholder="Enter reason for shifting patient"></textarea>
</div>
</div>
</div>
<div style="text-align:center;margin-top:20px;">
<button type="submit" class="btn btn-primary">🏥 Shift Patient</button>
<a href="ipd_list.php" class="btn btn-secondary">Cancel</a>
</div>
</form>
</div>
<script>
document.getElementById('building_id').addEventListener('change',function(){
let id=this.value;
document.getElementById('floor_id').innerHTML='<option value="">Loading...</option>';
document.getElementById('room_id').innerHTML='<option value="">Select Room</option>';
document.getElementById('bed_id').innerHTML='<option value="">Select Bed</option>';
if(id==""){
document.getElementById('floor_id').innerHTML='<option value="">Select Floor</option>';
return;
}
fetch('/masters/rooms/get_room_details.php?action=floors&building_id='+id)
.then(r=>r.text())
.then(data=>{
document.getElementById('floor_id').innerHTML=data;
});
});
document.getElementById('floor_id').addEventListener('change',function(){
let id=this.value;
document.getElementById('room_id').innerHTML='<option value="">Loading...</option>';
document.getElementById('bed_id').innerHTML='<option value="">Select Bed</option>';
if(id==""){
document.getElementById('room_id').innerHTML='<option value="">Select Room</option>';
return;
}
fetch('/masters/rooms/get_room_details.php?action=rooms&floor_id='+id)
.then(r=>r.text())
.then(data=>{
document.getElementById('room_id').innerHTML=data;
});
});
document.getElementById('room_id').addEventListener('change',function(){
let id=this.value;
document.getElementById('bed_id').innerHTML='<option value="">Loading...</option>';
if(id==""){
document.getElementById('bed_id').innerHTML='<option value="">Select Bed</option>';
return;
}
fetch('/masters/rooms/get_room_details.php?action=beds&room_id='+id)
.then(r=>r.text())
.then(data=>{
document.getElementById('bed_id').innerHTML=data;
});
});
</script>
</body>
</html>