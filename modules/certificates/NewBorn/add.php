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
checkPermission('newborn_certificate_add','add');
$branch_id=(int)($_SESSION['branch_id']??0);
if($branch_id<=0){
die('Branch not found in session.');
}
$stmt=$conn->prepare("
SELECT hospital_id
FROM branch_master
WHERE id=?
LIMIT 1
");
$stmt->bind_param("i",$branch_id);
$stmt->execute();
$branch=$stmt->get_result()->fetch_assoc();
$stmt->close();
if(!$branch){
die('Hospital not found for this branch.');
}
$hospital_id=(int)$branch['hospital_id'];
function e($value){
return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
}
/* ===========================================================
   GENERATE CERTIFICATE NUMBER
=========================================================== */
$today=date('Y-m-d');
$dateCode=date('Ymd');
$prefix='NBC-'.$dateCode.'-';
$stmt=$conn->prepare("
SELECT certificate_no
FROM newborn_certificates
WHERE certificate_no LIKE CONCAT(?,'%')
ORDER BY id DESC
LIMIT 1
");
$stmt->bind_param("s",$prefix);
$stmt->execute();
$last=$stmt->get_result()->fetch_assoc();
$stmt->close();
$next=1;
if($last && !empty($last['certificate_no'])){
$parts=explode('-',$last['certificate_no']);
$lastSequence=(int)end($parts);
$next=$lastSequence+1;
}
$certificate_no=$prefix.str_pad((string)$next,6,'0',STR_PAD_LEFT);
/* ===========================================================
   DOCTOR LIST FOR SURGEON / ASSISTANT
=========================================================== */
$doctorList=mysqli_query($conn,"
SELECT
dm.id,
u.full_name,
dm.license_no
FROM doctor_master dm
INNER JOIN users u
ON u.id=dm.user_id
ORDER BY u.full_name ASC
");
$doctors=[];
if($doctorList){
while($d=mysqli_fetch_assoc($doctorList)){
$doctors[]=$d;
}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add New Born Certificate</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/assets/css/style.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<style>
*{
box-sizing:border-box;
}
body{
margin:0;
background:#f5f7fb;
font-family:Arial,sans-serif;
font-size:13px;
}
.page-wrap{
padding:10px;
}
.form-card{
background:#fff;
border-radius:8px;
box-shadow:0 2px 10px rgba(0,0,0,.08);
margin-bottom:10px;
overflow:hidden;
}
.form-card-header{
background:#1976d2;
color:#fff;
font-weight:600;
padding:9px 12px;
font-size:14px;
}
.form-card-body{
padding:10px;
}
.row{
display:flex;
flex-wrap:wrap;
margin-left:-5px;
margin-right:-5px;
}
.col-md-1,
.col-md-2,
.col-md-3,
.col-md-4,
.col-md-5,
.col-md-6,
.col-md-8,
.col-md-12{
padding-left:5px;
padding-right:5px;
margin-bottom:8px;
}
.col-md-1{width:8.333333%;}
.col-md-2{width:16.666667%;}
.col-md-3{width:25%;}
.col-md-4{width:33.333333%;}
.col-md-5{width:41.666667%;}
.col-md-6{width:50%;}
.col-md-8{width:66.666667%;}
.col-md-12{width:100%;}
label{
display:block;
font-weight:600;
margin-bottom:4px;
color:#333;
}
.form-control{
width:100%;
height:34px;
border:1px solid #ced4da;
border-radius:4px;
padding:6px 8px;
background:#fff;
font-size:13px;
}
textarea.form-control{
height:auto;
resize:vertical;
}
.form-control[readonly]{
background:#f3f4f6;
}
.required-star{
color:#dc2626;
}
.form-actions{
display:flex;
justify-content:flex-end;
gap:8px;
padding:10px;
background:#fff;
position:sticky;
bottom:0;
z-index:20;
border-top:1px solid #ddd;
}
.btn{
border:0;
border-radius:4px;
padding:9px 16px;
cursor:pointer;
font-size:13px;
}
.btn-primary{
background:#1976d2;
color:#fff;
}
.btn-success{
background:#16a34a;
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
border:1px solid #ced4da!important;
border-radius:4px!important;
}
.select2-container .select2-selection--single .select2-selection__rendered{
line-height:32px!important;
}
.select2-container .select2-selection--single .select2-selection__arrow{
height:32px!important;
}
.info-note{
padding:7px 10px;
background:#eef6ff;
border:1px solid #bfdbfe;
border-radius:4px;
margin-bottom:10px;
color:#1e3a5f;
}
.select2-container{
width:100%!important;
}
.select2-dropdown{
z-index:999999!important;
}
.select2-container--open{
z-index:999999!important;
}
.select2-results__option{
font-size:13px;
}
.observation-table{
width:100%;
border-collapse:collapse;
}
.observation-table th,
.observation-table td{
border:1px solid #d1d5db;
padding:6px;
vertical-align:middle;
}
.observation-table th{
background:#f3f4f6;
color:#333;
font-weight:600;
text-align:left;
}
.observation-sr{
text-align:center;
font-weight:600;
}
.observation-add-btn,
.observation-remove-btn{
width:30px;
height:30px;
border:0;
border-radius:4px;
color:#fff;
font-size:18px;
font-weight:bold;
cursor:pointer;
line-height:28px;
padding:0;
}
.observation-add-btn{
background:#16a34a;
}
.observation-remove-btn{
background:#dc2626;
}
@media(max-width:900px){
.col-md-1,
.col-md-2,
.col-md-3,
.col-md-4,
.col-md-5,
.col-md-6,
.col-md-8{
width:100%;
}
}
</style>
</head>
<body>
<div class="page-wrap">
<form method="post" action="save.php" id="newBornForm">
<input type="hidden" name="patient_id" id="patient_id">
<input type="hidden" name="ipd_admission_id" id="ipd_admission_id">
<input type="hidden" name="department_id" id="department_id">
<input type="hidden" name="doctor_id" id="doctor_id">
<div class="form-card">
<div class="form-card-header">Certificate Information</div>
<div class="form-card-body">
<div class="row">
<div class="col-md-3">
<label>Certificate No. <span class="required-star">*</span></label>
<input type="text" class="form-control" name="certificate_no" value="<?= e($certificate_no) ?>" readonly required>
</div>
<div class="col-md-3">
<label>Certificate Date <span class="required-star">*</span></label>
<input type="date" class="form-control" name="certificate_date" value="<?= e($today) ?>" required>
</div>
<div class="col-md-6">
<label>Search Female Admitted IPD Patient <span class="required-star">*</span></label>
<select name="mother_ipd_search" id="mother_ipd_search" class="form-control" style="width:100%;" required>
<option value=""></option>
</select>
</div>
</div>
<div class="info-note">
Only Female patients whose current IPD status is <strong>Admitted</strong> will appear in this search.
</div>
</div>
</div>
<div class="form-card">
<div class="form-card-header">Mother / IPD Information</div>
<div class="form-card-body">
<div class="row">
<div class="col-md-2">
<label>IP No.</label>
<input type="text" class="form-control" name="ipd_no" id="ipd_no" readonly required>
</div>
<div class="col-md-2">
<label>UHID</label>
<input type="text" class="form-control" name="uhid" id="uhid" readonly>
</div>
<div class="col-md-4">
<label>Mother Name <span class="required-star">*</span></label>
<input type="text" class="form-control" name="mother_name" id="mother_name" readonly required>
</div>
<div class="col-md-4">
<label>Guardian / Relative</label>
<input type="text" class="form-control" name="guardian_name" id="guardian_name" readonly>
</div>
</div>
<div class="row">
<div class="col-md-2">
<label>DOB</label>
<input type="date" class="form-control" name="dob" id="dob" readonly>
</div>
<div class="col-md-2">
<label>Age</label>
<input type="text" class="form-control" name="age" id="age" readonly>
<input type="hidden" name="age_y" id="age_y">
<input type="hidden" name="age_m" id="age_m">
<input type="hidden" name="age_d" id="age_d">
</div>
<div class="col-md-2">
<label>Gender</label>
<input type="text" class="form-control" name="sex" id="sex" readonly>
</div>
<div class="col-md-2">
<label>Marital Status</label>
<input type="text" class="form-control" name="marital_status" id="marital_status" readonly>
</div>
<div class="col-md-2">
<label>Mobile</label>
<input type="text" class="form-control" name="mobile" id="mobile" readonly>
</div>

</div>
<div class="row">
<div class="col-md-2">
<label>Admission Date</label>
<input type="date" class="form-control" name="admission_date" id="admission_date" readonly>
</div>
<div class="col-md-2">
<label>Admission Time</label>
<input type="time" class="form-control" name="admission_time" id="admission_time" readonly>
</div>
<div class="col-md-2">
<label>Department</label>
<input type="text" class="form-control" name="department_name" id="department_name" readonly>
</div>
<div class="col-md-2">
<label>Doctor</label>
<input type="text" class="form-control" name="doctor_name" id="doctor_name" readonly>
</div>
<div class="col-md-2">
<label>Doctor Reg. No.</label>
<input type="text" class="form-control" name="doctor_registration_no" id="doctor_registration_no" readonly>
</div>
<div class="col-md-2">
<label>Room / Ward Type</label>
<input type="text" class="form-control" name="room_ward_type" id="room_ward_type" readonly>
</div>
</div>
<div class="row">
<div class="col-md-3">
<label>Bed Detail</label>
<input type="text" class="form-control" name="bed_detail" id="bed_detail" readonly>
</div>
<div class="col-md-9">
<label>Patient Address</label>
<textarea class="form-control" name="address" id="address" rows="2" readonly></textarea>
</div>
</div>
</div>
</div>
<div class="form-card">
<div class="form-card-header">Delivery Information</div>
<div class="form-card-body">
<div class="row">
<div class="col-md-2">
<label>First Pregnancy <span class="required-star">*</span></label>
<select class="form-control" name="first_pregnancy" required>
<option value="0">No</option>
<option value="1">Yes</option>
</select>
</div>
<div class="col-md-2">
<label>Anaesthesia <span class="required-star">*</span></label>
<select class="form-control" name="anaesthesia" required>
<option value="0">No</option>
<option value="1">Yes</option>
</select>
</div>
<div class="col-md-2">
<label>Lead Surgeon</label>
<select class="form-control" name="lead_surgeon_id" id="lead_surgeon_id">
<option value="">Select</option>
<?php foreach($doctors as $d){ ?>
<option value="<?= (int)$d['id'] ?>"><?= e($d['full_name']) ?></option>
<?php } ?>
</select>
</div>
<div class="col-md-2">
<label>Second Surgeon</label>
<select class="form-control" name="second_surgeon_id" id="second_surgeon_id">
<option value="">Select</option>
<?php foreach($doctors as $d){ ?>
<option value="<?= (int)$d['id'] ?>"><?= e($d['full_name']) ?></option>
<?php } ?>
</select>
</div>
<div class="col-md-2">
<label>Assistant</label>
<select class="form-control" name="assistant_id" id="assistant_id">
<option value="">Select</option>
<?php foreach($doctors as $d){ ?>
<option value="<?= (int)$d['id'] ?>"><?= e($d['full_name']) ?></option>
<?php } ?>
</select>
</div>
<div class="col-md-2">
<label>Delivery Type <span class="required-star">*</span></label>
<select class="form-control" name="delivery_type" required>
<option value="">Select</option>
<option value="Normal">Normal</option>
<option value="Caesarean">Caesarean</option>
<option value="Forceps">Forceps</option>
<option value="Vacuum">Vacuum</option>
<option value="Other">Other</option>
</select>
</div>
<div class="col-md-2">
<label>Patient Status <span class="required-star">*</span></label>
<select class="form-control" name="patient_status" id="patient_status" required>
<option value="">Select</option>
<option value="Stable">Stable</option>
<option value="Critical">Critical</option>
<option value="Under Observation">Under Observation</option>
<option value="Transferred">Transferred</option>
<option value="Discharged">Discharged</option>
<option value="Other">Other</option>
</select>
</div>
</div>
<div class="row">
<div class="col-md-3">
<label>Last Child Age</label>
<input type="text" class="form-control" name="last_child_age" placeholder="Example: 3 Years">
</div>
<div class="col-md-3">
<label>Delivery Date <span class="required-star">*</span></label>
<input type="date" class="form-control" name="delivery_date" value="<?= e($today) ?>" required>
</div>
<div class="col-md-3">
<label>Delivery Time <span class="required-star">*</span></label>
<input type="time" class="form-control" name="delivery_time" value="<?= date('H:i') ?>" required>
</div>
</div>
</div>
</div>
<div class="form-card">
<div class="form-card-header">New Born Baby Information</div>
<div class="form-card-body">
<div class="row">
<div class="col-md-2">
<label>Born Child <span class="required-star">*</span></label>
<input type="number" min="1" class="form-control" name="born_child" id="born_child" value="1" required>
</div>
<div class="col-md-2">
<label>Alive Child <span class="required-star">*</span></label>
<input type="number" min="0" class="form-control" name="alive_child" id="alive_child" value="1" required>
</div>
<div class="col-md-2">
<label>Still Born / Intrapartum Death <span class="required-star">*</span></label>
<input type="number" min="0" class="form-control" name="still_born_count" id="still_born_count" value="0" required>
</div>
<div class="col-md-2">
<label>Baby Gender <span class="required-star">*</span></label>
<select class="form-control" name="baby_gender" required>
<option value="">Select</option>
<option value="Male">Male</option>
<option value="Female">Female</option>
<option value="Other">Other</option>
</select>
</div>
<div class="col-md-2">
<label>Baby Weight (Kg) <span class="required-star">*</span></label>
<input type="number" step="0.001" min="0" class="form-control" name="baby_weight" required>
</div>
<div class="col-md-2">
<label>Baby Length (cm) <span class="required-star">*</span></label>
<input type="number" step="0.01" min="0" class="form-control" name="baby_length" required>
</div>
</div>
<div class="row">
<div class="col-md-12">
<label>Remarks</label>
<textarea class="form-control" name="remarks" rows="2" placeholder="Enter remarks if any"></textarea>
</div>
</div>
</div>
</div>


<div class="form-card">
<div class="form-card-header">Condition at Birth</div>
<div class="form-card-body">
<div class="row">
<div class="col-md-2">
<label style="display:flex;align-items:center;gap:7px;margin-top:7px;">
<input type="checkbox" name="oxygen_required" id="oxygen_required" value="1" onchange="toggleOxygenDuration()">
Oxygen Required
</label>
</div>
<div class="col-md-2">
<label style="display:flex;align-items:center;gap:7px;margin-top:7px;">
<input type="checkbox" name="jaundice" id="jaundice" value="1">
Jaundice
</label>
</div>
<div class="col-md-2">
<label>How Long Oxygen Required</label>
<input type="text" class="form-control" name="oxygen_duration" id="oxygen_duration" placeholder="Example: 2 Hours" disabled>
</div>
<div class="col-md-2">
<label>1st Baby 1 min APGAR</label>
<input type="text" class="form-control" name="apgar_1_min" id="apgar_1_min" placeholder="APGAR Score">
</div>
<div class="col-md-2">
<label>1st Baby 5 min APGAR</label>
<input type="text" class="form-control" name="apgar_5_min" id="apgar_5_min" placeholder="APGAR Score">
</div>
<div class="col-md-2">
<label>1st Baby Head Shape</label>
<input type="text" class="form-control" name="head_shape" id="head_shape" placeholder="Head Shape">
</div>
</div>
<div style="margin-top:8px;overflow-x:auto;">
<table class="observation-table" id="observationTable">
<thead>
<tr>
<th style="width:70px;">Sr. No.</th>
<th>Vital Observation <span class="required-star">*</span></th>
<th>Vaccination Given <span class="required-star">*</span></th>
<th style="width:90px;">Action</th>
</tr>
</thead>
<tbody id="observationBody">
<tr>
<td class="observation-sr">1</td>
<td>
<input type="text" class="form-control" name="vital_observation[]" placeholder="Enter vital observation" required>
</td>
<td>
<input type="text" class="form-control" name="vaccination_given[]" placeholder="Enter vaccination given" required>
</td>
<td style="text-align:center;">
<button type="button" class="observation-add-btn" onclick="addObservationRow()">+</button>
</td>
</tr>
</tbody>
</table>
</div>
</div>
</div>


<div class="form-actions">
<button type="button" class="btn btn-secondary" onclick="closePopup()">Cancel</button>
<button type="submit" name="save" value="1" class="btn btn-primary">Save</button>
<button type="submit" name="save_print" value="1" class="btn btn-success">Save & Print</button>
</div>
</form>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function(){
const $motherSearch=$("#mother_ipd_search");
$motherSearch.select2({
width:"100%",
placeholder:"Search by Mother Name / IP No. / UHID / Mobile",
allowClear:true,
minimumInputLength:0,
dropdownParent:$(document.body),
ajax:{
url:"../ajax/search_newborn_ipd_patients.php",
type:"GET",
dataType:"json",
delay:250,
data:function(params){
return{
q:params.term || ""
};
},
processResults:function(data){
console.log("New Born Patient Search:",data);
return{
results:Array.isArray(data.results)?data.results:[]
};
},
error:function(xhr,status,error){
console.error("Patient Search Error:",status,error);
console.error(xhr.responseText);
},
cache:false
}
});
$("#mother_ipd_search").on("select2:select",function(e){
const admissionId=e.params.data.id;
loadMotherIPD(admissionId);
});
$("#mother_ipd_search").on("select2:clear",function(){
clearMotherIPD();
});
});
function loadMotherIPD(admissionId){
if(!admissionId){
clearMotherIPD();
return;
}
fetch("../ajax/get_newborn_ipd_patient.php?id="+encodeURIComponent(admissionId),{
credentials:"same-origin"
})
.then(function(response){
if(!response.ok){
throw new Error("Unable to load IPD patient.");
}
return response.json();
})
.then(function(res){
if(!res.success || !res.patient){
alert(res.message || "Unable to load IPD patient.");
clearMotherIPD();
return;
}
const p=res.patient;
document.getElementById("patient_id").value=p.patient_id || "";
document.getElementById("ipd_admission_id").value=p.ipd_admission_id || "";
document.getElementById("ipd_no").value=p.ipd_no || "";
document.getElementById("uhid").value=p.uhid || "";
document.getElementById("mother_name").value=p.mother_name || "";
document.getElementById("guardian_name").value=p.guardian_name || "";
document.getElementById("mobile").value=p.mobile || "";
document.getElementById("dob").value=p.dob || "";
document.getElementById("age_y").value=p.age_y || "";
document.getElementById("age_m").value=p.age_m || "";
document.getElementById("age_d").value=p.age_d || "";
document.getElementById("age").value=p.age || "";
document.getElementById("sex").value=p.sex || "";
document.getElementById("marital_status").value=p.marital_status || "";
document.getElementById("address").value=p.address || "";
document.getElementById("admission_date").value=p.admission_date || "";
document.getElementById("admission_time").value=p.admission_time || "";
document.getElementById("patient_status").value=p.admission_status || "";
document.getElementById("department_id").value=p.department_id || "";
document.getElementById("department_name").value=p.department_name || "";
document.getElementById("doctor_id").value=p.doctor_id || "";
document.getElementById("doctor_name").value=p.doctor_name || "";
document.getElementById("doctor_registration_no").value=p.doctor_registration_no || "";
document.getElementById("room_ward_type").value=p.room_ward_type || "";
document.getElementById("bed_detail").value=p.bed_detail || "";
})
.catch(function(error){
console.error(error);
alert("Unable to load IPD patient details.");
clearMotherIPD();
});
}
function clearMotherIPD(){
const ids=[
"patient_id",
"ipd_admission_id",
"ipd_no",
"uhid",
"mother_name",
"guardian_name",
"mobile",
"dob",
"age_y",
"age_m",
"age_d",
"age",
"sex",
"marital_status",
"address",
"admission_date",
"admission_time",
"patient_status",
"department_id",
"department_name",
"doctor_id",
"doctor_name",
"doctor_registration_no",
"room_ward_type",
"bed_detail"
];
ids.forEach(function(id){
const el=document.getElementById(id);
if(el){
el.value="";
}
});
}
function closePopup(){
if(window.parent && window.parent!==window){
if(typeof window.parent.closeNewBornCertificatePopup==="function"){
window.parent.closeNewBornCertificatePopup();
return;
}
}
window.location.href="newborn_certificate.php";
}
document.getElementById("newBornForm").addEventListener("submit",function(event){
const patientId=document.getElementById("patient_id").value;
const admissionId=document.getElementById("ipd_admission_id").value;
const born=parseInt(document.getElementById("born_child").value || "0",10);
const alive=parseInt(document.getElementById("alive_child").value || "0",10);
const stillBorn=parseInt(document.getElementById("still_born_count").value || "0",10);
if(!patientId || !admissionId){
event.preventDefault();
alert("Please select a Female Admitted IPD Patient.");
return;
}
if(born<=0){
event.preventDefault();
alert("Born Child must be at least 1.");
return;
}
if(alive<0 || stillBorn<0){
event.preventDefault();
alert("Alive Child and Still Born count cannot be negative.");
return;
}
if(alive+stillBorn>born){
event.preventDefault();
alert("Alive Child + Still Born cannot be greater than Born Child.");
return;
}
});
function toggleOxygenDuration(){
const oxygenRequired=document.getElementById("oxygen_required");
const oxygenDuration=document.getElementById("oxygen_duration");
if(oxygenRequired.checked){
oxygenDuration.disabled=false;
oxygenDuration.required=true;
oxygenDuration.focus();
}else{
oxygenDuration.value="";
oxygenDuration.required=false;
oxygenDuration.disabled=true;
}
}
function addObservationRow(){
const tbody=document.getElementById("observationBody");
const row=document.createElement("tr");
row.innerHTML=`
<td class="observation-sr"></td>
<td>
<input type="text" class="form-control" name="vital_observation[]" placeholder="Enter vital observation" required>
</td>
<td>
<input type="text" class="form-control" name="vaccination_given[]" placeholder="Enter vaccination given" required>
</td>
<td style="text-align:center;">
<button type="button" class="observation-remove-btn" onclick="removeObservationRow(this)">×</button>
</td>
`;
tbody.appendChild(row);
updateObservationSerial();
}
function removeObservationRow(button){
const tbody=document.getElementById("observationBody");
const row=button.closest("tr");
if(row){
row.remove();
}
updateObservationSerial();
}
function updateObservationSerial(){
const rows=document.querySelectorAll("#observationBody tr");
rows.forEach(function(row,index){
const sr=row.querySelector(".observation-sr");
if(sr){
sr.textContent=index+1;
}
});
}
</script>
</body>
</html>