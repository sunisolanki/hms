<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if(!isset($_SESSION['user_id'])){
    header("Location:/login.php");
    exit;
}
if(!isset($_GET['id'])){
    die("Appointment ID missing");
}
$appointment_id = intval($_GET['id']);
/* =========================
LOAD APPOINTMENT
========================= */
$stmt = $conn->prepare("
SELECT
a.*,

p.title AS patient_title,
p.first_name AS patient_first_name,
p.last_name AS patient_last_name,
p.sex AS patient_sex,
p.marital_status AS patient_marital_status,
p.aadhaar AS patient_aadhaar,
p.mobile AS patient_mobile,
p.phone AS patient_phone,
p.email AS patient_email,
p.guardian_name AS patient_guardian_name,
p.dob AS patient_dob,
p.city_id AS patient_city_id,
p.pincode AS patient_pincode,
p.address AS patient_address

FROM appointments a
LEFT JOIN patient_master p
ON p.id=a.patient_id
WHERE a.id=?
LIMIT 1
");
$stmt->bind_param("i",$appointment_id);
$stmt->execute();
$res = $stmt->get_result();
if(!$res->num_rows){
    die("Appointment not found");
}
$data = $res->fetch_assoc();
$stmt->close();
/* =========================
ASSIGN VARIABLES
========================= */
$type        = $data['type'];
$entity_id   = $data['entity_id'];
$date        = $data['appointment_date'];
$consultation_mode = $data['consultation_mode'] ?? 'PHYSICAL';
$slot        = $data['appointment_time'];
$patient_id  = $data['patient_id'];
$source='INTERNAL';
/* =========================
CITY LIST
========================= */
$cities=[];
$q=mysqli_query($conn,"SELECT id,name FROM cities ORDER BY name ASC");
while($r=mysqli_fetch_assoc($q)){
$cities[]=$r;
}
/* =========================
LOAD DOCTOR / SERVICE NAME
========================= */
$entityName='';
$departmentName='';
$feeAmount = $data['consultation_fee'] ?? 0;
if($type=='doctor'){
$stmt=$conn->prepare("
SELECT 
u.full_name,
dm.department_name
FROM users u
JOIN user_departments ud ON ud.user_id=u.id
JOIN department_master dm ON dm.id=ud.department_id
WHERE u.id=?
LIMIT 1
");
$stmt->bind_param("i",$entity_id);
$stmt->execute();
$res=$stmt->get_result();
if($row=$res->fetch_assoc()){
$entityName=$row['full_name'];
$departmentName=$row['department_name'];
}
$stmt->close();
}
if($type=='service'){
$stmt=$conn->prepare("
SELECT service_name,category_type,amount
FROM service_master
WHERE id=?
LIMIT 1
");
$stmt->bind_param("i",$entity_id);
$stmt->execute();
$res=$stmt->get_result();
if($row=$res->fetch_assoc()){
$entityName=$row['service_name'];
$departmentName=$row['category_type'];
}
$stmt->close();
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Edit Appointment</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<style>
/* =========================================
   EDIT APPOINTMENT
   SAME UI AS ADD APPOINTMENT
========================================= */
:root{
--primary:#1976d2;
--primary-dark:#1565c0;
--border:#d1d5db;
--bg:#eef2f7;
--surface:#fff;
--text:#1e293b;
--muted:#64748b;
}
*{
box-sizing:border-box;
}
html,body{
margin:0;
padding:0;
width:100%;
min-height:100%;
}
body{
margin:0;
background:var(--bg);
font-family:Arial,Helvetica,sans-serif;
font-size:13px;
color:var(--text);
}
.container{
max-width:1500px;
margin:0 auto;
padding:5px;
}
.header{
background:#1976d2;
color:#fff;
padding:10px 16px;
border-radius:10px;
box-shadow:0 8px 20px rgba(0,0,0,.08);
margin-bottom:14px;
}
.header h1,
.header h2{
margin:0;
color:#fff;
font-size:18px;
font-weight:bold;
}
.card{
background:#fff;
border-radius:10px;
box-shadow:0 8px 20px rgba(0,0,0,.08);
margin-bottom:10px;
overflow:hidden;
position:relative;
}
.card h3{
background:#1976d2;
color:#fff;
margin:0;
padding:10px 15px;
font-size:15px;
font-weight:bold;
display:flex;
align-items:center;
gap:7px;
}
.card h3 span{
display:inline-flex;
align-items:center;
justify-content:center;
}
.grid{
display:grid;
grid-template-columns:repeat(5,minmax(0,1fr));
gap:10px 14px;
padding:10px 15px;
}
.grid>div{
min-width:0;
}
label{
display:block;
font-size:12px;
font-weight:bold;
color:#475569;
margin-bottom:4px;
}
input,
select,
textarea{
width:100%;
padding:7px 8px;
border:1px solid #d1d5db;
border-radius:6px;
background:#fff;
color:#1e293b;
font-family:Arial,Helvetica,sans-serif;
font-size:14px;
min-height:34px;
}
input:focus,
select:focus,
textarea:focus{
outline:none;
border-color:#1976d2;
box-shadow:0 0 0 2px rgba(25,118,210,.12);
}
textarea{
min-height:70px;
resize:vertical;
}
.readonly,
input[readonly],
select:disabled{
background:#f1f5f9!important;
color:#64748b;
}
.actions{
display:flex;
justify-content:flex-end;
gap:10px;
padding:10px 15px 15px;
}
button{
min-height:34px;
padding:7px 16px;
border:none;
border-radius:6px;
background:#1976d2;
color:#fff;
font-family:Arial,Helvetica,sans-serif;
font-size:13px;
font-weight:bold;
cursor:pointer;
}
button:hover{
background:#1565c0;
}
.btn-primary{
background:#1976d2;
color:#fff;
}
.btn-secondary{
background:#64748b;
color:#fff;
}
.select2-container{
width:100%!important;
}
.select2-container .select2-selection--single{
height:34px!important;
border:1px solid #d1d5db!important;
border-radius:6px!important;
background:#fff!important;
}
.select2-container .select2-selection--single .select2-selection__rendered{
line-height:32px!important;
padding-left:8px!important;
font-size:14px;
}
.select2-container .select2-selection--single .select2-selection__arrow{
height:32px!important;
}
@media(max-width:1100px){
.grid{
grid-template-columns:repeat(3,minmax(0,1fr));
}
}
@media(max-width:800px){
.grid{
grid-template-columns:repeat(2,minmax(0,1fr));
}
.header{
padding:9px 14px;
}
.card{
margin-left:5px;
margin-right:5px;
}
}
@media(max-width:560px){
body{
font-size:13px;
}
.container{
padding:8px;
}
.header{
border-radius:7px;
}
.header h1,
.header h2{
font-size:16px;
}
.grid{
grid-template-columns:1fr;
gap:10px;
}
.card{
border-radius:8px;
margin-left:0;
margin-right:0;
}
.card h3{
padding:9px 12px;
font-size:14px;
}
.actions{
flex-direction:column;
}
.actions button{
width:100%;
}
}
</style>
</head>
<body>
<div class="container">
<form method="post" action="update_appointment.php">
<input type="hidden" name="appointment_id" value="<?= $appointment_id ?>">
<input type="hidden" name="patient_id" value="<?= $patient_id ?>">

<!-- ================= APPOINTMENT INFO ================= -->
<div class="card">
<h3><span>🗓</span>Appointment Details</h3>
<div class="grid">
<div>
<label>Type</label>
<select name="type" disabled>
<option value="doctor" <?= $type=='doctor'?'selected':'' ?>>Doctor</option>
<option value="service" <?= $type=='service'?'selected':'' ?>>Service</option>
</select>
<input type="hidden" name="type" value="<?= $type ?>">
</div>
<div>
<label>Consultation Mode</label>
<select name="consultation_mode" id="consultation_mode">
<option value="PHYSICAL" <?= $consultation_mode=='PHYSICAL'?'selected':'' ?>>Physical</option>
<option value="TELEMEDICINE" <?= $consultation_mode=='TELEMEDICINE'?'selected':'' ?>>Telemedicine</option>
</select>
</div>
<div>
<label>Department</label>
<input type="text"value="<?= htmlspecialchars($departmentName) ?>"readonly class="readonly">
</div>
<div>
<label>Doctor / Service</label>
<input type="text"value="<?= htmlspecialchars($entityName) ?>"readonly class="readonly">
<input type="hidden" name="entity_id" value="<?= $entity_id ?>">
</div>
<div>
<label>Appointment Date</label>
<input type="date"name="appointment_date"value="<?= $date ?>">
</div>
<div>
<label>Appointment Time *</label>
<select name="appointment_time" id="appointment_time_dd">
<option value="<?= htmlspecialchars($slot) ?>" selected><?= htmlspecialchars($slot) ?></option>
</select>
</div>
</div>
</div>
<!-- ================= PATIENT DETAILS ================= -->
<div class="card">
<h3><span>👤</span>Patient Details</h3>
<div class="grid">
<div>
<label>Title</label>
<select name="title">
<option <?= $data['title']=='Mr'?'selected':'' ?>>Mr</option>
<option <?= $data['title']=='Mrs'?'selected':'' ?>>Mrs</option>
<option <?= $data['title']=='Miss'?'selected':'' ?>>Miss</option>
<option <?= $data['title']=='Dr'?'selected':'' ?>>Dr</option>
</select>
</div>
<div>
<label>First Name</label>
<input type="text" name="first_name"
value="<?= htmlspecialchars($data['patient_first_name'] ?: $data['first_name']) ?>">
</div>
<div>
<label>Last Name</label>
<input type="text" name="last_name"
value="<?= htmlspecialchars($data['patient_last_name'] ?: $data['last_name']) ?>">
</div>
<div>
<label>Marital Status</label>
<select id="marital" name="marital_status">
<option value="UnMarried" <?= ($data['marital_status'] ?? '')=='UnMarried' ? 'selected' : '' ?>> UnMarried </option>
<option value="Married" <?= ($data['marital_status'] ?? '')=='Married' ? 'selected' : '' ?>> Married </option>
<option value="Divorced" <?= ($data['marital_status'] ?? '')=='Divorced' ? 'selected' : '' ?>>Divorced</option>
</select>
</div>
<div>
<label>Gender</label>
<select name="sex">
<option value="Male" <?= $data['sex']=='Male'?'selected':'' ?>>Male</option>
<option value="Female" <?= $data['sex']=='Female'?'selected':'' ?>>Female</option>
<option value="Other" <?= $data['sex']=='Other'?'selected':'' ?>>Other</option>
</select>
</div>
<div>
<label id="guardian_label">Father / Mother Name</label>
<input type="text"name="guardian_name"value="<?= htmlspecialchars($data['guardian_name']) ?>">
</div>
<div>
<label>Aadhaar No</label>
<input type="text"id="aadhaar"name="aadhaar_no"maxlength="12"pattern="\d{12}"inputmode="numeric"title="Aadhaar must be exactly 12 digits"value="<?= htmlspecialchars($data['aadhaar'] ?? '')?>">
</div>
<div>
<label>Mobile</label>
<input type="text" name="mobile"
value="<?= htmlspecialchars($data['patient_mobile'] ?: $data['mobile']) ?>">
</div>
<div>
<label>Phone</label>
<input type="text"name="phone"value="<?= htmlspecialchars($data['phone']) ?>">
</div>
<div>
<label>Email</label>
<input type="email" name="email"
value="<?= htmlspecialchars($data['patient_email'] ?: $data['email']) ?>">
</div>
<div>
<label>DOB</label>
<input type="date"
       name="dob"
       value="<?= $data['dob'] ?>"
       max="<?= date('Y-m-d') ?>"></div>
<div>
<label>City</label>
<select id="city" name="city">
<option value="">Select City</option>
<?php foreach($cities as $c): ?>
<option value="<?= $c['id'] ?>"
<?= $data['city_id']==$c['id']?'selected':'' ?>>
<?= htmlspecialchars($c['name']) ?>
</option>
<?php endforeach; ?>
</select>
</div>
<div>
<label>Pincode</label>
<input type="text"name="pincode"value="<?= $data['pincode'] ?>">
</div>
<div>
<label>Address</label>
<textarea name="address"><?= htmlspecialchars($data['address']) ?></textarea>
</div>
<div>
<label>Remarks</label>
<textarea id="remarks"name="remarks"rows="3"><?= htmlspecialchars($data['remarks'] ?? '') ?></textarea>
</div>
</div>
<div class="actions">
<button type="submit" class="btn-primary">Update Appointment</button>
</div>
</div>
</form>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function () {

$('#city').select2({
placeholder: 'Select City',
allowClear: true,
width: '100%'
});

});
</script>
<script>
document.getElementById('aadhaar').addEventListener('blur', function () {

    const val = this.value.trim();

    if (val === '') return;

    if (!/^\d{12}$/.test(val)) {
        alert('Aadhaar must be exactly 12 digits');
        this.value='';
        this.focus();
    }

});
</script>
<script>
document.addEventListener('DOMContentLoaded', function(){
const entityId = <?= (int)$entity_id ?>;
const type = "<?= $type ?>";
const dateInput = document.querySelector('[name="appointment_date"]');
const timeDD = document.getElementById('appointment_time_dd');
const currentSlot = "<?= $slot ?>";
function loadSlots(){
const date = dateInput.value;
if(!date || !entityId) return;
timeDD.innerHTML = `<option value="${currentSlot}" selected>${currentSlot}</option>`;
fetch(`/modules/appointments/ajax/get_booked_slots.php?type=${type}&entity_id=${entityId}&date=${date}`)
.then(res=>res.json())
.then(bookedSlots=>{
let slotUrl = '';
if(type === 'doctor'){
    slotUrl = `/modules/appointments/ajax/get_doctor_slots.php?doctor_id=${entityId}`;
}else{
    slotUrl = `/modules/appointments/ajax/get_service_time.php?service_id=${entityId}`;
}
fetch(slotUrl)
.then(res=>res.json())
.then(data=>{
if(!data[date]) return;
data[date].forEach(slot=>{
const slotValue = `${slot.start} - ${slot.end}`;
if(bookedSlots && bookedSlots[slotValue] && slotValue !== currentSlot){
return;
}
const opt = document.createElement('option');
opt.value = slotValue;
opt.textContent = slotValue;
if(slotValue === currentSlot){
opt.selected = true;
}
timeDD.appendChild(opt);
});
});
});
}
loadSlots();
dateInput.addEventListener('change',loadSlots);
});
</script>
<script>
document.querySelector('[name="dob"]').addEventListener('change', function () {
    const selectedDate = new Date(this.value);
    const today = new Date();
    today.setHours(0,0,0,0);
    if (selectedDate > today) {
        alert("DOB can't be a future date");
        this.value = '';
    }
});
</script>
</body>
</html>