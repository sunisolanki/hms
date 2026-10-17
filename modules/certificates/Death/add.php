<?php
session_start();
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
checkPermission('death_certificate_add','add');
function e($v){
return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
}
$user_id=$_SESSION['user_id']??0;
$hospital_id=$_SESSION['hospital_id']??0;
$branch_id=$_SESSION['branch_id']??0;
$mode='Direct';
if(isset($_GET['appointment_id']) && (int)$_GET['appointment_id']>0){
$mode='OPD';
}
if(isset($_GET['ipd_admission_id']) && (int)$_GET['ipd_admission_id']>0){
$mode='IPD';
}
$appointment_id=(int)($_GET['appointment_id']??0);
$ipd_admission_id=(int)($_GET['ipd_admission_id']??0);
$certificate_date=date('Y-m-d');
$patient=[
'id'=>0,
'uhid'=>'',
'patient_name'=>'',
'guardian_name'=>'',
'age_y'=>'',
'age_m'=>'',
'age_d'=>'',
'dob'=>'',
'sex'=>'',
'mobile'=>'',
'address'=>'',
'marital_status'=>''
];
$doctor=[
'id'=>'',
'name'=>'',
'registration_no'=>''
];
/* ===========================================================
   CERTIFICATE NUMBER
=========================================================== */
$certificate_no='';
$q=mysqli_query($conn,"
SELECT certificate_no
FROM death_certificates
ORDER BY id DESC
LIMIT 1
");
if($q && mysqli_num_rows($q)){
$r=mysqli_fetch_assoc($q);
if(preg_match('/(\d+)$/',$r['certificate_no'],$m)){
$next=str_pad(((int)$m[1])+1,6,'0',STR_PAD_LEFT);
}else{
$next='000001';
}
}else{
$next='000001';
}
$certificate_no='DC-'.date('Ymd').'-'.$next;
/* ===========================================================
   LOAD OPD PATIENT WHEN OPENED DIRECTLY FROM OPD
=========================================================== */
if($mode=='OPD' && $appointment_id>0){
$sql="SELECT
a.id AS appointment_id,
a.patient_id,
a.entity_id AS doctor_id,
p.uhid,
CONCAT(IFNULL(p.title,''),' ',IFNULL(p.first_name,''),' ',IFNULL(p.last_name,'')) AS patient_name,
p.guardian_name,
p.age_y,
p.age_m,
p.age_d,
p.dob,
p.sex AS gender,
p.mobile,
p.address,
p.marital_status,
u.full_name AS doctor_name,
dm.license_no AS registration_no
FROM appointments a
LEFT JOIN patient_master p
ON p.id=a.patient_id
LEFT JOIN doctor_master dm
ON dm.id=a.entity_id
LEFT JOIN users u
ON u.id=dm.user_id
WHERE a.id=?";
$stmt=mysqli_prepare($conn,$sql);
mysqli_stmt_bind_param($stmt,"i",$appointment_id);
mysqli_stmt_execute($stmt);
$res=mysqli_stmt_get_result($stmt);
if($row=mysqli_fetch_assoc($res)){
$patient['id']=$row['patient_id'];
$patient['uhid']=$row['uhid'];
$patient['patient_name']=$row['patient_name'];
$patient['guardian_name']=$row['guardian_name'];
$patient['age_y']=$row['age_y'];
$patient['age_m']=$row['age_m'];
$patient['age_d']=$row['age_d'];
$patient['dob']=$row['dob'];
$patient['sex']=$row['gender'];
$patient['mobile']=$row['mobile'];
$patient['address']=$row['address'];
$patient['marital_status']=$row['marital_status'];
$doctor['id']=$row['doctor_id'];
$doctor['name']=$row['doctor_name'];
$doctor['registration_no']=$row['registration_no'];
}
mysqli_stmt_close($stmt);
}
/* ===========================================================
   LOAD IPD PATIENT WHEN OPENED DIRECTLY FROM IPD
=========================================================== */
if($mode=='IPD' && $ipd_admission_id>0){
$sql="SELECT
i.id AS ipd_admission_id,
i.patient_id,
i.doctor_id,
p.uhid,
CONCAT(IFNULL(p.title,''),' ',IFNULL(p.first_name,''),' ',IFNULL(p.last_name,'')) AS patient_name,
p.guardian_name,
p.age_y,
p.age_m,
p.age_d,
p.dob,
p.sex AS gender,
p.mobile,
p.address,
p.marital_status,
u.full_name AS doctor_name,
dm.license_no AS registration_no
FROM ipd_admissions i
LEFT JOIN patient_master p
ON p.id=i.patient_id
LEFT JOIN doctor_master dm
ON dm.id=i.doctor_id
LEFT JOIN users u
ON u.id=dm.user_id
WHERE i.id=?";
$stmt=mysqli_prepare($conn,$sql);
mysqli_stmt_bind_param($stmt,"i",$ipd_admission_id);
mysqli_stmt_execute($stmt);
$res=mysqli_stmt_get_result($stmt);
if($row=mysqli_fetch_assoc($res)){
$patient['id']=$row['patient_id'];
$patient['uhid']=$row['uhid'];
$patient['patient_name']=$row['patient_name'];
$patient['guardian_name']=$row['guardian_name'];
$patient['age_y']=$row['age_y'];
$patient['age_m']=$row['age_m'];
$patient['age_d']=$row['age_d'];
$patient['dob']=$row['dob'];
$patient['sex']=$row['gender'];
$patient['mobile']=$row['mobile'];
$patient['address']=$row['address'];
$patient['marital_status']=$row['marital_status'];
$doctor['id']=$row['doctor_id'];
$doctor['name']=$row['doctor_name'];
$doctor['registration_no']=$row['registration_no'];
}
mysqli_stmt_close($stmt);
}
/* ===========================================================
   DOCTOR LIST
=========================================================== */
$doctorList=mysqli_query($conn,"
SELECT
dm.id,
u.full_name
FROM doctor_master dm
INNER JOIN users u
ON u.id=dm.user_id
WHERE u.status='Active'
ORDER BY u.full_name
");
if(!$doctorList){
die('Doctor Load Error: '.mysqli_error($conn));
}
/* ===========================================================
   DEFAULT DEATH DETAILS
=========================================================== */
$date_of_death=date('Y-m-d');
$time_of_death=date('H:i');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Create Death Certificate</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/style.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link rel="icon" href="/assets/img/hms_logo.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
.main-content1{
padding:5px 20px 20px 20px;
background:#f0f2f5;
min-height:100vh;
}
@media(max-width:768px){
.main-content{
margin-left:0;
padding:10px;
}
}
.page-header{
display:flex;
justify-content:space-between;
align-items:center;
flex-wrap:wrap;
gap:10px;
}
.card{
background:#fff;
border-radius:10px;
box-shadow:0 4px 12px rgba(0,0,0,.08);
margin-bottom:10px;
overflow:hidden;
transition:box-shadow .2s;
}
.card-header{
background:#0d6efd;
color:#fff;
padding:12px 18px;
font-size:15px;
font-weight:bold;
display:flex;
justify-content:space-between;
align-items:center;
}
.card-header h3{
margin:0;
font-size:15px;
font-weight:bold;
display:flex;
align-items:center;
gap:8px;
}
.card-header h3 i{
font-size:16px;
}
.card-body{
padding:5px 20px;
}
.row{
display:flex;
flex-wrap:wrap;
margin-left:-8px;
margin-right:-8px;
}
.col-md-2{flex:0 0 16.666667%;max-width:16.666667%;padding:0 8px;}
.col-md-3{flex:0 0 20%;max-width:25%;padding:0 8px;}
.col-md-4{flex:0 0 20%;max-width:33.333333%;padding:0 8px;}
.col-md-5{flex:0 0 20%;max-width:41.666667%;padding:0 8px;}
.col-md-6{flex:0 0 50%;max-width:50%;padding:0 8px;}
.col-md-12{flex:0 0 100%;max-width:100%;padding:0 8px;}

.select2-container{
width:100% !important;
}
.select2-container .select2-selection--single{
height:34px !important;
border:1px solid #ced4da !important;
border-radius:4px !important;
}
.select2-container .select2-selection--single .select2-selection__rendered{
line-height:32px !important;
font-size:13px;
}
.select2-container .select2-selection--single .select2-selection__arrow{
height:32px !important;
}
@media(max-width:768px){
.col-md-2,
.col-md-3,
.col-md-4,
.col-md-5,
.col-md-6{
flex:0 0 100%;
max-width:100%;
margin-bottom:10px;
}
}
label{
font-weight:600;
font-size:13px;
margin-bottom:4px;
display:block;
color:#1e293b;
}
.form-control{
width:100%;
padding:6px 10px;
border:1px solid #ced4da;
border-radius:4px;
font-size:13px;
transition:.15s;
background:#fff;
}
.form-control:focus{
border-color:#0d6efd;
outline:none;
box-shadow:0 0 0 2px rgba(13,110,253,.15);
}
.form-control[readonly]{
background:#f5f5f5;
}
.form-control:disabled{
background:#e9ecef;
cursor:not-allowed;
}
textarea.form-control{
resize:vertical;
min-height:60px;
}
.text-danger{
color:#dc3545;
}
.mt-3{
margin-top:15px;
}
.mb-3{
margin-bottom:15px;
}
.mt-4{
margin-top:25px;
}
.mb-4{
margin-bottom:25px;
}
.text-end{
text-align:right;
}
.required-star{
color:#dc3545;
}
.col-five{
flex:0 0 20%;
max-width:20%;
padding:0 8px;
}
@media(max-width:768px){
.col-five{
flex:0 0 100%;
max-width:100%;
margin-bottom:10px;
}
}
@media(max-width:768px){
.page-header{
flex-direction:column;
align-items:flex-start;
}
.page-actions{
width:100%;
}
.page-actions .btn{
flex:1;
text-align:center;
}
.text-end{
text-align:center;
}
.text-end .btn{
margin-bottom:8px;
width:100%;
}
}
</style>
</head>
<body>
<div class="main-content1">
<div class="page-header">
<div class="page-actions"></div>
</div>
<form method="post" action="save.php" id="deathCertificateForm">
<input type="hidden" name="patient_id" id="patient_id" value="<?= e($patient['id']) ?>">
<input type="hidden" name="appointment_id" id="appointment_id" value="<?= e($appointment_id) ?>">
<input type="hidden" name="ipd_admission_id" id="ipd_admission_id" value="<?= e($ipd_admission_id) ?>">
<input type="hidden" id="selected_opd_id" name="selected_opd_id">
<input type="hidden" id="selected_ipd_id" name="selected_ipd_id">
<div class="card">
<div class="card-header">
<h3><i class="fas fa-file-medical"></i> Certificate Information</h3>
</div>
<div class="card-body">
<div class="row">
<div class="col-md-3">
<label>Certificate No</label>
<input type="text" class="form-control" name="certificate_no" value="<?= e($certificate_no) ?>" readonly>
</div>
<div class="col-md-3">
<label>Certificate Date <span class="required-star">*</span></label>
<input type="date" class="form-control" name="certificate_date" value="<?= e($certificate_date) ?>" required>
</div>
<div class="col-md-3">
<label>Patient Type <span class="required-star">*</span></label>
<select name="patient_type" id="patient_type" class="form-control" onchange="changePatientType()">
<option value="Direct" <?= $mode=='Direct'?'selected':'' ?>>Direct</option>
<option value="OPD" <?= $mode=='OPD'?'selected':'' ?>>OPD</option>
<option value="IPD" <?= $mode=='IPD'?'selected':'' ?>>IPD</option>
</select>
</div>
<div class="col-md-3" id="opd_patient_div" style="<?= $mode=='OPD'?'':'display:none;' ?>">
<label>Select OPD Patient</label>
<select id="opd_patient_search" class="form-control" style="width:100%;"></select>
</div>
<div class="col-md-3" id="ipd_patient_div" style="<?= $mode=='IPD'?'':'display:none;' ?>">
<label>Select IPD Patient</label>
<select id="ipd_patient_search" class="form-control" style="width:100%;"></select>
</div>
</div>
</div>

<!-- Patient Information Card -->
<div class="card">
<div class="card-header">
<h3><i class="fas fa-user"></i> Patient Information</h3>
</div>
<div class="card-body">
<div class="row">
<div class="col-md-3">
<label>UHID</label>
<input type="text" class="form-control" name="uhid" id="uhid" value="<?= e($patient['uhid']) ?>" readonly>
</div>
<div class="col-md-5">
<label>Patient Name <span class="required-star">*</span></label>
<input type="text" class="form-control" name="patient_name" id="patient_name" value="<?= e($patient['patient_name']) ?>" required>
</div>
<div class="col-md-4">
<label>Guardian / Relative Name</label>
<input type="text" class="form-control" name="guardian_name" id="guardian_name" value="<?= e($patient['guardian_name']) ?>">
</div>
<div class="col-md-3">
<label>Age (Y | M | D)</label>
<div style="display:flex;gap:6px;">
<input type="number"
class="form-control"
name="age_y"
id="age_y"
placeholder="Y"
min="0"
value="<?= e($patient['age_y']) ?>"
oninput="calculateDOBFromAge()">
<input type="number"
class="form-control"
name="age_m"
id="age_m"
placeholder="M"
min="0"
max="11"
value="<?= e($patient['age_m']) ?>"
oninput="calculateDOBFromAge()">
<input type="number"
class="form-control"
name="age_d"
id="age_d"
placeholder="D"
min="0"
max="31"
value="<?= e($patient['age_d']) ?>"
oninput="calculateDOBFromAge()">
</div>
</div>

</div>

<div class="row mt-3">

<div class="col-md-3">
<label>Date of Birth</label>
<input type="date"
class="form-control"
name="dob"
id="dob"
value="<?= e($patient['dob']) ?>"
onchange="calculateAgeFromDOB()">
</div>

<div class="col-md-2">
<label>Gender</label>
<select class="form-control" name="sex" id="sex">
<option value="">Select Gender</option>
<option value="Male" <?= $patient['sex']=='Male'?'selected':'' ?>>Male</option>
<option value="Female" <?= $patient['sex']=='Female'?'selected':'' ?>>Female</option>
<option value="Other" <?= $patient['sex']=='Other'?'selected':'' ?>>Other</option>
</select>
</div>

<div class="col-md-2">
<label>Mobile</label>
<input type="text"
class="form-control"
name="mobile"
id="mobile"
value="<?= e($patient['mobile']) ?>"
maxlength="10"
inputmode="numeric"
pattern="[0-9]{10}"
title="Enter exactly 10 digit mobile number"
oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,10);">
</div>

<div class="col-md-2">
<label>Marital Status</label>
<select class="form-control" name="marital_status" id="marital_status">
<option value="">Select Marital Status</option>
<option value="Unmarried" <?= in_array($patient['marital_status'],['Unmarried','Single'],true)?'selected':'' ?>>Unmarried</option>
<option value="Married" <?= $patient['marital_status']=='Married'?'selected':'' ?>>Married</option>
<option value="Widowed" <?= $patient['marital_status']=='Widowed'?'selected':'' ?>>Widowed</option>
<option value="Divorced" <?= $patient['marital_status']=='Divorced'?'selected':'' ?>>Divorced</option>
<option value="Separated" <?= $patient['marital_status']=='Separated'?'selected':'' ?>>Separated</option>
</select>
</div>
<div class="col-md-3">
<label>Address</label>
<input type="text"
name="address"
id="address"
class="form-control"
value="<?= e($patient['address']) ?>"
<?=$mode=='Direct'?'':'readonly';?>>
</div>


</div>
</div>
</div>

<div class="card">
<div class="card-header">
<h3><i class="fas fa-notes-medical"></i> Death Details</h3>
</div>
<div class="card-body">
<div class="row">
<div class="col-md-3">
<label>Date of Death <span class="required-star">*</span></label>
<input type="date"
class="form-control"
name="date_of_death"
id="date_of_death"
value="<?= e($date_of_death) ?>"
required>
</div>
<div class="col-md-3">
<label>Time of Death</label>
<input type="time"
class="form-control"
name="time_of_death"
id="time_of_death"
value="<?= e($time_of_death) ?>">
</div>
<div class="col-md-3">
<label>Place of Death</label>
<select class="form-control"
name="place_of_death"
id="place_of_death"
onchange="togglePlaceOther()">
<option value="Hospital" selected>Hospital</option>
<option value="Home">Home</option>
<option value="Other">Other</option>
</select>
</div>

<div class="col-md-3" id="place_other_wrap" style="display:none;">
<label>Other Place</label>
<input type="text"
class="form-control"
name="place_of_death_other"
id="place_of_death_other"
maxlength="255"
placeholder="Specify place">
</div>


<div class="col-md-3">
<label>Manner of Death</label>
<select class="form-control"
name="manner_of_death"
id="manner_of_death"
onchange="toggleMannerOther()">
<option value="Natural" selected>Natural</option>
<option value="Accident">Accident</option>
<option value="Suicide">Suicide</option>
<option value="Homicide">Homicide</option>
<option value="Pending Investigation">Pending Investigation</option>
<option value="Other">Other</option>
</select>
</div>

<div class="col-md-3" id="manner_other_wrap" style="display:none;">
<label>Other Manner</label>
<input type="text"
class="form-control"
name="manner_of_death_other"
id="manner_of_death_other"
maxlength="255"
placeholder="Specify manner">
</div>

<div class="col-md-3">
<label>Doctor <span class="required-star">*</span></label>
<select class="form-control"
name="doctor_id"
id="doctor_id"
required
onchange="loadDoctorDetails()">
<option value="">Select Doctor</option>
<?php
if($doctorList){
mysqli_data_seek($doctorList,0);
while($d=mysqli_fetch_assoc($doctorList)){
?>
<option value="<?= (int)$d['id'] ?>" <?= ((string)$doctor['id']===(string)$d['id'])?'selected':'' ?>>
<?= e($d['full_name']) ?>
</option>
<?php
}
}
?>
</select>
</div>
<div class="col-md-3">
<label>Doctor Name</label>
<input type="text"
class="form-control"
name="doctor_name"
id="doctor_name"
value="<?= e($doctor['name']) ?>"
readonly>
</div>
<div class="col-md-3">
<label>Registration No.</label>
<input type="text"
class="form-control"
name="doctor_registration_no"
id="doctor_registration_no"
value="<?= e($doctor['registration_no']) ?>"
readonly>
</div>
</div>

<div class="row mt-3">
<div class="col-five">
<label>Immediate Cause of Death</label>
<textarea class="form-control"
name="immediate_cause"
id="immediate_cause"
rows="2"
placeholder="Enter immediate cause of death"></textarea>
</div>
<div class="col-five">
<label>Antecedent Cause</label>
<textarea class="form-control"
name="antecedent_cause"
id="antecedent_cause"
rows="2"
placeholder="Enter antecedent cause, if applicable"></textarea>
</div>
<div class="col-five">
<label>Underlying Cause</label>
<textarea class="form-control"
name="underlying_cause"
id="underlying_cause"
rows="2"
placeholder="Enter underlying cause of death"></textarea>
</div>
<div class="col-five">
<label>Other Significant Conditions</label>
<textarea class="form-control"
name="other_significant_conditions"
id="other_significant_conditions"
rows="2"
placeholder="Other significant conditions contributing to death"></textarea>
</div>
<div class="col-five">
<label>Remarks</label>
<textarea class="form-control"
name="remarks"
id="remarks"
rows="2"
placeholder="Enter remarks if any"></textarea>
</div>
</div>
</div>
</div>

<div class="card">
<div class="card-body">
<div class="row">
<div class="col-md-12 text-end">
<button type="button"
class="btn btn-secondary"
onclick="closeDeathForm()">
<i class="fas fa-times"></i> Cancel
</button>

<button type="submit"
name="save"
value="1"
class="btn btn-primary">
<i class="fas fa-save"></i> Save
</button>

<button type="submit"
name="save_print"
value="1"
class="btn btn-success">
<i class="fas fa-print"></i> Save & Print
</button>
</div>
</div>
</div>
</div>

</form>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
let ageCalculationLock=false;

/* ===========================================================
   OPD SELECT2 SEARCH
=========================================================== */
$("#opd_patient_search").select2({
placeholder:"Search OPD No / UHID / Name / Mobile",
allowClear:true,
ajax:{
url:"../ajax/search_opd_patients.php",
dataType:"json",
delay:250,
data:function(params){
return{
q:params.term
};
},
processResults:function(data){
return data;
},
cache:true
}
});
/* ===========================================================
   IPD SELECT2 SEARCH
=========================================================== */
$("#ipd_patient_search").select2({
placeholder:"Search IPD No / UHID / Name / Mobile",
allowClear:true,
ajax:{
url:"../ajax/search_ipd_patients.php",
dataType:"json",
delay:250,
data:function(params){
return{
q:params.term
};
},
processResults:function(data){
return data;
},
cache:true
}
});
/* ===========================================================
   LOAD SELECTED OPD PATIENT
=========================================================== */
$("#opd_patient_search").on("select2:select",function(e){
let id=e.params.data.id;
$("#selected_opd_id").val(id);
$.getJSON("../ajax/get_opd_patient.php",{id:id},function(res){
if(!res.success){
alert(res.message);
return;
}
$("#patient_id").val(res.patient_id);
$("#appointment_id").val(res.appointment_id);
$("#ipd_admission_id").val("");
$("#uhid").val(res.uhid);
$("#patient_name").val(res.patient_name);
$("#guardian_name").val(res.guardian_name);
$("#dob").val(res.dob);
dobToAge();
$("#mobile").val(res.mobile);
$("#address").val(res.address);
$("#sex").val(res.sex);
let maritalStatus=res.marital_status;
if(maritalStatus==="Single"){
maritalStatus="Unmarried";
}
$("#marital_status").val(maritalStatus);
$("#doctor_id").val(res.doctor_id);
$("#doctor_name").val(res.doctor_name || "");
$("#doctor_registration_no").val(res.doctor_registration_no || "");
if(res.doctor_id){
loadDoctorDetails();
}
});
});
/* ===========================================================
   LOAD SELECTED IPD PATIENT
=========================================================== */
$("#ipd_patient_search").on("select2:select",function(e){
let id=e.params.data.id;
$("#selected_ipd_id").val(id);
$.getJSON("../ajax/get_ipd_patient.php",{id:id},function(res){
if(!res.success){
alert(res.message);
return;
}
$("#patient_id").val(res.patient_id);
$("#ipd_admission_id").val(res.ipd_admission_id);
$("#appointment_id").val(res.appointment_id || "");
$("#uhid").val(res.uhid);
$("#patient_name").val(res.patient_name);
$("#guardian_name").val(res.guardian_name);
$("#dob").val(res.dob);
dobToAge();
$("#mobile").val(res.mobile);
$("#address").val(res.address);
$("#sex").val(res.sex);
let maritalStatus=res.marital_status;
if(maritalStatus==="Single"){
maritalStatus="Unmarried";
}
$("#marital_status").val(maritalStatus);
$("#doctor_id").val(res.doctor_id);
$("#doctor_name").val(res.doctor_name || "");
$("#doctor_registration_no").val(res.doctor_registration_no || "");
if(res.doctor_id){
loadDoctorDetails();
}
});
});
/* ===========================================================
   CLEAR SEARCH
=========================================================== */
$("#opd_patient_search").on("select2:clear",function(){
$("#selected_opd_id").val("");
$("#patient_id").val("");
$("#appointment_id").val("");
$("#uhid").val("");
$("#patient_name").val("");
$("#guardian_name").val("");
$("#age_y,#age_m,#age_d").val("");
$("#dob").val("");
$("#mobile").val("");
$("#address").val("");
$("#sex").val("");
$("#marital_status").val("");
});
$("#ipd_patient_search").on("select2:clear",function(){
$("#selected_ipd_id").val("");
$("#patient_id").val("");
$("#ipd_admission_id").val("");
$("#appointment_id").val("");
$("#uhid").val("");
$("#patient_name").val("");
$("#guardian_name").val("");
$("#age_y,#age_m,#age_d").val("");
$("#dob").val("");
$("#mobile").val("");
$("#address").val("");
$("#sex").val("");
$("#marital_status").val("");
});
/* ===========================================================
   DOB TO AGE
=========================================================== */
function dobToAge(){
if(ageCalculationLock){
return;
}
let dob=$("#dob").val();
if(dob==""){
return;
}
let birth=new Date(dob+"T00:00:00");
let today=new Date();
today.setHours(0,0,0,0);
if(birth>today){
alert("Date of Birth cannot be in the future.");
$("#dob").val("");
$("#age_y,#age_m,#age_d").val("");
return;
}
let years=today.getFullYear()-birth.getFullYear();
let months=today.getMonth()-birth.getMonth();
let days=today.getDate()-birth.getDate();
if(days<0){
months--;
let lastMonth=new Date(today.getFullYear(),today.getMonth(),0);
days+=lastMonth.getDate();
}
if(months<0){
years--;
months+=12;
}
ageCalculationLock=true;
$("#age_y").val(Math.max(0,years));
$("#age_m").val(Math.max(0,months));
$("#age_d").val(Math.max(0,days));
ageCalculationLock=false;
}
/* ===========================================================
   AGE TO DOB
=========================================================== */
function ageToDob(){
if(ageCalculationLock){
return;
}
let y=parseInt($("#age_y").val())||0;
let m=parseInt($("#age_m").val())||0;
let d=parseInt($("#age_d").val())||0;
if(y===0 && m===0 && d===0){
return;
}
let today=new Date();
let dob=new Date(
today.getFullYear()-y,
today.getMonth()-m,
today.getDate()-d
);
let yyyy=dob.getFullYear();
let mm=String(dob.getMonth()+1).padStart(2,"0");
let dd=String(dob.getDate()).padStart(2,"0");
ageCalculationLock=true;
$("#dob").val(yyyy+"-"+mm+"-"+dd);
ageCalculationLock=false;
}
/* ===========================================================
   COMPATIBILITY WITH CURRENT HTML
=========================================================== */
function calculateAgeFromDOB(){
dobToAge();
}
function calculateDOBFromAge(){
ageToDob();
}
/* ===========================================================
   DOCTOR DETAILS
=========================================================== */
function loadDoctorDetails(){
const doctorId=document.getElementById("doctor_id").value;
if(!doctorId){
document.getElementById("doctor_name").value="";
document.getElementById("doctor_registration_no").value="";
return;
}
fetch("../ajax/get_doctor.php?id="+encodeURIComponent(doctorId),{
credentials:"same-origin"
})
.then(function(response){
if(!response.ok){
throw new Error("Unable to load doctor.");
}
return response.json();
})
.then(function(data){
if(data.success===false){
document.getElementById("doctor_name").value="";
document.getElementById("doctor_registration_no").value="";
return;
}
const doctor=data.doctor || data;
document.getElementById("doctor_name").value=
doctor.doctor_name ||
doctor.full_name ||
doctor.name ||
"";
document.getElementById("doctor_registration_no").value=
doctor.registration_no ||
doctor.license_no ||
"";
})
.catch(function(error){
console.error(error);
});
}
/* ===========================================================
   PLACE OF DEATH OTHER
=========================================================== */
function togglePlaceOther(){
const value=document.getElementById("place_of_death").value;
const wrap=document.getElementById("place_other_wrap");
const input=document.getElementById("place_of_death_other");
if(value==="Other"){
wrap.style.display="";
input.required=true;
}else{
wrap.style.display="none";
input.required=false;
input.value="";
}
}
/* ===========================================================
   MANNER OF DEATH OTHER
=========================================================== */
function toggleMannerOther(){
const value=document.getElementById("manner_of_death").value;
const wrap=document.getElementById("manner_other_wrap");
const input=document.getElementById("manner_of_death_other");
if(value==="Other"){
wrap.style.display="";
input.required=true;
}else{
wrap.style.display="none";
input.required=false;
input.value="";
}
}
/* ===========================================================
   CLOSE POPUP
=========================================================== */
function closeDeathForm(){
if(
window.parent &&
window.parent!==window &&
typeof window.parent.closeDeathCertificatePopup==="function"
){
window.parent.closeDeathCertificatePopup();
}else{
window.location.href="death_certificate.php";
}
}
/* ===========================================================
   FORM SUBMIT
=========================================================== */
document.getElementById("deathCertificateForm").addEventListener("submit",function(e){
const type=$("#patient_type").val();
const patientName=$("#patient_name").val().trim();
const doctor=$("#doctor_id").val();
const mobile=$("#mobile").val().trim();
if(type==="Direct"){
if(mobile===""){
alert("Please enter Mobile Number.");
$("#mobile").focus();
e.preventDefault();
return;
}
if(!/^[6-9][0-9]{9}$/.test(mobile)){
alert("Please enter a valid 10-digit Mobile Number.");
$("#mobile").focus();
e.preventDefault();
return;
}
}
if(patientName===""){
alert("Please enter Patient Name.");
$("#patient_name").focus();
e.preventDefault();
return;
}
if(doctor===""){
alert("Please select Doctor.");
$("#doctor_id").focus();
e.preventDefault();
return;
}
/*
Disabled SELECT fields are not submitted by browser.
Enable them immediately before submit.
*/
if(type==="OPD" || type==="IPD"){
$("#sex").prop("disabled",false);
$("#marital_status").prop("disabled",false);
}
});
/* ===========================================================
   MOBILE ONLY NUMBERS
=========================================================== */
$("#mobile").on("input",function(){
this.value=this.value.replace(/\D/g,"");
if(this.value.length>10){
this.value=this.value.substring(0,10);
}
});
/* ===========================================================
   PAGE LOAD
=========================================================== */
$(document).ready(function(){
togglePlaceOther();
toggleMannerOther();
let initialType=$("#patient_type").val();
if(initialType==="OPD"){
$("#opd_patient_div").show();
$("#ipd_patient_div").hide();
$("#patient_name,#guardian_name,#mobile,#address").prop("readonly",true);
$("#dob,#age_y,#age_m,#age_d").prop("readonly",true);
$("#sex,#marital_status").prop("disabled",true);
}else if(initialType==="IPD"){
$("#ipd_patient_div").show();
$("#opd_patient_div").hide();
$("#patient_name,#guardian_name,#mobile,#address").prop("readonly",true);
$("#dob,#age_y,#age_m,#age_d").prop("readonly",true);
$("#sex,#marital_status").prop("disabled",true);
}else{
$("#opd_patient_div,#ipd_patient_div").hide();
}
<?php if(!empty($doctor['id'])){ ?>
loadDoctorDetails();
<?php } ?>
});
function changePatientType(){
const type=document.getElementById("patient_type").value;
const opdDiv=document.getElementById("opd_patient_div");
const ipdDiv=document.getElementById("ipd_patient_div");
opdDiv.style.display="none";
ipdDiv.style.display="none";
if(type==="OPD"){
opdDiv.style.display="block";
}
if(type==="IPD"){
ipdDiv.style.display="block";
}
$("#opd_patient_search").val(null).trigger("change");
$("#ipd_patient_search").val(null).trigger("change");
$("#selected_opd_id").val("");
$("#selected_ipd_id").val("");
$("#patient_id").val("");
$("#appointment_id").val("");
$("#ipd_admission_id").val("");
$("#uhid").val("");
$("#patient_name").val("");
$("#guardian_name").val("");
$("#age_y").val("");
$("#age_m").val("");
$("#age_d").val("");
$("#dob").val("");
$("#mobile").val("");
$("#address").val("");
$("#sex").val("");
$("#marital_status").val("");
if(type==="Direct"){
$("#patient_name,#guardian_name,#mobile,#address").prop("readonly",false);
$("#dob,#age_y,#age_m,#age_d").prop("readonly",false);
$("#sex,#marital_status").prop("disabled",false);
}else{
$("#patient_name,#guardian_name,#mobile,#address").prop("readonly",true);
$("#dob,#age_y,#age_m,#age_d").prop("readonly",true);
$("#sex,#marital_status").prop("disabled",true);
}
}
</script>
</body>
</html>