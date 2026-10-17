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
if(!checkPermission('ipd_admission','add')){
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
CHECK IF ALREADY ADMITTED
---------------------------------------------------*/
$stmt = $conn->prepare("
SELECT
id,
ipd_no
FROM ipd_admissions
WHERE appointment_id=?
LIMIT 1
");
$stmt->bind_param("i",$appointment_id);
$stmt->execute();
$existingAdmission = $stmt->get_result()->fetch_assoc();
if($existingAdmission){
header(
"Location:/modules/ipd/ipd_dashboard.php?admission_id=".$existingAdmission['id']
);
exit;
}
/*---------------------------------------------------
LOAD DOCTORS
---------------------------------------------------*/
$doctorList = [];
$resDoctor = mysqli_query($conn,"
SELECT
u.id,
u.full_name
FROM doctor_master dm
INNER JOIN users u
ON u.id=dm.user_id
WHERE u.status='Active'
ORDER BY u.full_name
");
while($row=mysqli_fetch_assoc($resDoctor)){
    $doctorList[]=$row;
}
$cities=[];

$q=mysqli_query($conn,"
SELECT id,name
FROM cities
ORDER BY name
");

while($r=mysqli_fetch_assoc($q)){
    $cities[]=$r;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>IPD Admission</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" href="/assets/img/hms_logo.png">
<style>
/* Compact UI Styles */
.page-container{
padding:5px;
max-width:1500px;
margin:0 auto;
}
.card{
background:#fff;
border-radius:10px;
box-shadow:0 8px 20px rgba(0,0,0,.08);
margin-bottom:10px;
overflow:hidden;
position:relative;
padding:0;
border:0;
}
.card h3{
background:#1976d2;
color:#fff;
margin:0;
padding:9px 15px;
font-size:15px;
font-weight:bold;
display:flex;
align-items:center;
gap:8px;
width:100%;
letter-spacing:.1px;
min-height:38px;
box-sizing:border-box;
}
.page-header{
background:#1976d2;
color:#fff;
padding:10px 16px;
border-radius:10px;
box-shadow:0 8px 20px rgba(0,0,0,.08);
margin-bottom:14px;
}
.page-header h2{
margin:0;
font-size:18px;
font-weight:bold;
color:#fff;
}
.info-grid{
display:grid;
grid-template-columns:repeat(5,minmax(0,1fr));
gap:8px 14px;
padding:11px 15px 13px;
}
.info-item{
display:flex;
align-items:baseline;
padding:2px 0;
}
.info-item .label{
font-weight:bold;
color:#475569;
font-size:12px;
min-width:72px;
margin-right:7px;
white-space:nowrap;
}
.info-item .value{
color:#1e293b;
font-size:13px;
font-weight:500;
word-break:break-word;
line-height:1.35;
}
.info-item .value.badge{
background:#d97706;
color:#fff;
padding:3px 10px;
border-radius:12px;
font-size:11px;
font-weight:600;
}
.form-grid .form-group input[readonly]{
background:#f1f5f9 !important;
color:#64748b;
cursor:not-allowed;
}
.form-grid{
display:grid;
grid-template-columns:repeat(5,minmax(0,1fr));
gap:1px 14px;
padding:12px 15px;
}
.form-grid .form-group[style*="grid-column: span 2"]{
grid-column:span 2;
}
.form-grid .form-group[style*="grid-column: 1 / -1"]{
grid-column:1 / -1;
}
.form-group{
display:flex;
flex-direction:column;
}
.form-group label{
display:block;
font-size:12px;
font-weight:bold;
color:#475569;
margin-bottom:4px;
}
.form-group input,
.form-group select{
width:100%;
height:36px;
min-height:36px;
padding:7px 8px;
border:1px solid #d1d5db;
border-radius:6px;
background:#fff;
color:#1e293b;
font-family:Arial,Helvetica,sans-serif;
font-size:14px;
box-sizing:border-box;
transition:border-color .18s ease,box-shadow .18s ease;
}
.form-group textarea{
width:100%;
height:auto;
padding:7px 8px;
border:1px solid #d1d5db;
border-radius:6px;
background:#fff;
color:#1e293b;
font-family:Arial,Helvetica,sans-serif;
font-size:14px;
box-sizing:border-box;
resize:vertical;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus{
outline:none;
border-color:#1976d2;
box-shadow:0 0 0 2px rgba(25,118,210,.12);
}
.form-group input[readonly]{
height:36px;
min-height:36px;
background:#f1f5f9 !important;
color:#64748b;
cursor:not-allowed;
}
.mlc-grid{
display:grid;
grid-template-columns:repeat(5,minmax(0,1fr));
gap:10px 14px;
}
.mlc-grid .form-group{
min-width:0;
grid-column:span 1;
}
.mlc-grid .form-group.full-width{
grid-column:span 1;
}
.card > .form-grid{
padding:12px 15px 5px;
}
.card > .info-grid{
padding:12px 15px 15px;
}
#mlcRow{
background:transparent;
border:0;
border-radius:0;

box-sizing:border-box;
}
#mlcRow .form-group label{
font-size:12px;
font-weight:bold;
color:#475569;
margin-bottom:4px;
}
#mlcRow .form-group input,
#mlcRow .form-group select,
#mlcRow .form-group textarea{
width:100%;
height:36px;
min-height:36px;
padding:7px 8px;
border:1px solid #d1d5db;
border-radius:6px;
background:#fff;
color:#1e293b;
font-family:Arial,Helvetica,sans-serif;
font-size:14px;
box-sizing:border-box;
}
#mlcRow .form-group input:focus,
#mlcRow .form-group select:focus,
#mlcRow .form-group textarea:focus{
outline:none;
border-color:#1976d2;
box-shadow:0 0 0 2px rgba(25,118,210,.12);
}
/* ===============================
   CITY MODAL STYLES (POPUP)
=============================== */
.modal{
display:none;
position:fixed;
inset:0;
width:100%;
height:100%;
background:rgba(15,23,42,.55);
backdrop-filter:blur(2px);
-webkit-backdrop-filter:blur(2px);
z-index:9999;
overflow:auto;
align-items:center;
justify-content:center;
padding:20px;
box-sizing:border-box;
}
.modal-box{
background:#fff;
width:700px;
max-width:100%;
margin:0 auto;
padding:0;
border-radius:10px;
box-shadow:0 15px 40px rgba(0,0,0,.22);
position:relative;
overflow:hidden;
}
.modal-box h3{
background:#1976d2;
color:#fff;
margin:0;
padding:12px 50px 12px 16px;
font-size:16px;
font-weight:bold;
border:0;
line-height:1.3;
}
.close{
position:absolute;
right:12px;
top:9px;
width:32px;
height:32px;
border:0;
border-radius:50%;
background:rgba(255,255,255,.16);
color:#fff;
font-size:22px;
font-weight:bold;
line-height:32px;
text-align:center;
cursor:pointer;
z-index:2;
transition:background .18s ease,color .18s ease;
}
.close:hover{
background:#fff;
color:#dc2626;
}
.btn{
min-height:36px;
padding:7px 16px;
border:1px solid transparent;
border-radius:6px;
font-size:13px;
font-weight:bold;
cursor:pointer;
transition:background .18s ease,border-color .18s ease;
text-decoration:none;
display:inline-flex;
align-items:center;
justify-content:center;
gap:6px;
}
.btn-primary{
background:#1976d2;
color:#fff;
border-color:#1565c0;
}
.btn-primary:hover{
background:#1565c0;
border-color:#0d47a1;
}
.btn-secondary{
background:#64748b;
color:#fff;
border-color:#475569;
}
.btn-secondary:hover{
background:#475569;
border-color:#334155;
}
.btn-group{
display:flex;
justify-content:flex-end;
align-items:center;
gap:8px;
margin-top:12px;
padding:10px 15px 5px;
border-top:1px solid #e2e8f0;
}
select[multiple]{
height:80px !important;
padding:4px !important;
}
select[multiple] option{
padding:3px 6px;
font-size:13px;
}
.multi-select{
position:relative;
width:100%;
}
.select-box{
width:100%;
min-height:34px;
padding:7px 8px;
border:1px solid #d1d5db;
border-radius:6px;
background:#fff;
color:#1e293b;
font-family:Arial,Helvetica,sans-serif;
font-size:14px;
box-sizing:border-box;
cursor:pointer;
display:flex;
align-items:center;
}
.select-box:hover{
border-color:#1976d2;
}
#doctorText{
color:#64748b;
}
.dropdown{
display:none;
position:absolute;
top:calc(100% + 4px);
left:0;
width:100%;
max-height:240px;
overflow:auto;
background:#fff;
border:1px solid #d1d5db;
border-radius:6px;
box-shadow:0 8px 20px rgba(0,0,0,.12);
z-index:1000;
padding:6px;
box-sizing:border-box;
}
.dropdown input[type="text"]{
width:100%;
min-height:34px;
padding:7px 8px;
margin-bottom:5px;
border:1px solid #d1d5db;
border-radius:6px;
box-sizing:border-box;
font-size:13px;
}
.dropdown input[type="text"]:focus{
outline:none;
border-color:#1976d2;
box-shadow:0 0 0 2px rgba(25,118,210,.12);
}
.dropdown label{
display:flex;
align-items:center;
gap:7px;
padding:7px 8px;
margin:0;
border-radius:5px;
font-size:13px;
font-weight:normal;
color:#1e293b;
cursor:pointer;
}
.dropdown label:hover{
background:#f1f5f9;
}
.dropdown label input[type="checkbox"]{
width:auto;
min-height:auto;
margin:0;
cursor:pointer;
}
.checkbox-group-field{
min-width:0;
}
.checkbox-group-field > label{
margin-bottom:4px;
}
.form-check{
display:grid;
grid-template-columns:repeat(2,minmax(0,1fr));
gap:10px;
padding:0;
width:100%;
box-sizing:border-box;
}
.form-check label{
display:flex;
align-items:center;
gap:8px;
width:100%;
height:36px;
min-height:36px;
padding:7px 8px;
margin:0;
border:1px solid #d1d5db;
border-radius:6px;
background:#fff;
color:#1e293b;
font-size:14px;
font-weight:500;
box-sizing:border-box;
cursor:pointer;
}
.form-check label:hover{
border-color:#1976d2;
background:#f8fafc;
}
.form-check input[type="checkbox"]{
appearance:none;
-webkit-appearance:none;
width:18px;
height:18px;
min-width:18px;
min-height:18px;
margin:0;
border:1px solid #94a3b8;
border-radius:4px;
background:#fff;
cursor:pointer;
position:relative;
box-sizing:border-box;
}
.form-check input[type="checkbox"]:checked{
background:#1976d2;
border-color:#1976d2;
}
.form-check input[type="checkbox"]:checked::after{
content:"✓";
position:absolute;
left:3px;
top:0;
color:#fff;
font-size:13px;
font-weight:bold;
line-height:17px;
}
.referral-form-grid{
grid-template-columns:repeat(2,minmax(0,1fr));
gap:12px 14px;
padding:0;
}
.referral-form-grid > div{
display:flex;
flex-direction:column;
}
.referral-form-grid label{
display:block;
font-size:12px;
font-weight:bold;
color:#475569;
margin-bottom:4px;
}
.referral-form-grid input,
.referral-form-grid select{
width:100%;
height:36px;
min-height:36px;
padding:7px 8px;
border:1px solid #d1d5db;
border-radius:6px;
background:#fff;
color:#1e293b;
font-family:Arial,Helvetica,sans-serif;
font-size:14px;
box-sizing:border-box;
transition:border-color .18s ease,box-shadow .18s ease;
}
.referral-form-grid input:focus,
.referral-form-grid select:focus{
outline:none;
border-color:#1976d2;
box-shadow:0 0 0 2px rgba(25,118,210,.12);
}
.referral-modal-actions{
display:flex;
justify-content:flex-end;
align-items:center;
gap:8px;
margin-top:16px;
padding-top:12px;
border-top:1px solid #e2e8f0;
}
.attendant-row{
display:grid;
grid-template-columns:2fr 1.5fr 1fr auto;
gap:10px 14px;
align-items:end;
padding:12px 15px 5px;
}
.attendant-actions{
display:flex;
align-items:flex-end;
gap:8px;
height:36px;
}
.attendant-actions .btn{
height:36px;
min-height:36px;
white-space:nowrap;
margin: 10px;
}
@media(max-width:900px){
.attendant-row{
grid-template-columns:repeat(2,minmax(0,1fr));
}
.attendant-actions{
grid-column:1 / -1;
}
}
@media(max-width:560px){
.attendant-row{
grid-template-columns:1fr;
}
.attendant-actions{
grid-column:1;
}
}
.modal-box > .referral-form-grid{
padding:16px;
box-sizing:border-box;
}
.referral-modal-actions{
display:flex;
justify-content:flex-end;
align-items:center;
gap:8px;
margin-top:0;
padding:12px 16px 16px;
border-top:1px solid #e2e8f0;
box-sizing:border-box;
}

#addReferralBtn:hover{
background:#1565c0 !important;
border-color:#0d47a1 !important;
}
.grid{
display:grid;
grid-template-columns:repeat(2,1fr);
gap:15px;
}
.grid div{
display:flex;
flex-direction:column;
}
@media(max-width:1100px){
.form-grid{
grid-template-columns:repeat(3,minmax(0,1fr));
}
}
@media(max-width:800px){
.form-grid{
grid-template-columns:repeat(2,minmax(0,1fr));
}
.mlc-grid{
grid-template-columns:repeat(2,minmax(0,1fr));
}
.mlc-grid .form-group.full-width{
grid-column:span 1;
}
}
@media(max-width:768px){
.info-grid{
grid-template-columns:1fr;
}
.mlc-grid{
grid-template-columns:1fr;
}
.mlc-grid .form-group.full-width{
grid-column:1;
}
.btn-group{
flex-direction:column;
align-items:stretch;
}
.btn{
justify-content:center;
}
.grid{
grid-template-columns:1fr;
}
}
@media(max-width:560px){
.form-grid{
grid-template-columns:1fr;
gap:10px;
}
.mlc-grid{
grid-template-columns:1fr;
}
}
@media print{
.btn-group{
display:none;
}
}
</style>
</head>
<body>
<div class="page-container">
        <form method="post" action="save_admission.php" id="admissionForm">
        <input type="hidden" name="appointment_id" value="<?= $appointment_id ?>">
        <input type="hidden" name="patient_id" value="<?= $appointment['patient_id'] ?>">
        <input type="hidden" name="hospital_id" value="<?= $hospital_id ?>">
        <input type="hidden" name="branch_id" value="<?= $branch_id ?>">
        <input type="hidden" name="doctor_id" value="<?= $appointment['entity_id'] ?>">
        <input type="hidden" name="department_id" value="<?= $appointment['department_id'] ?>">
        
        <!-- Patient Information -->
        <div class="card">
            <h3>🏥 IPD Admission : Patient Information</h3>
            <div class="info-grid">
                <div class="info-item"><span class="label">UHID:</span><span class="value"><?= htmlspecialchars($appointment['uhid']) ?></span></div>
                <div class="info-item"><span class="label">OPD No.:</span><span class="value"><?= htmlspecialchars($appointment['opd_no']) ?></span></div>
                <div class="info-item"><span class="label">IPD No.:</span><span class="value"><?= htmlspecialchars($appointment['ipd_no'] ?? '') ?></span></div>
                <div class="info-item"><span class="label">Status:</span><span class="value badge">Pending Admission</span></div>
                <div class="info-item"><span class="label">Name:</span><span class="value"><?= htmlspecialchars($appointment['title'].' '.$appointment['first_name'].' '.$appointment['last_name']) ?></span></div>
                <div class="info-item"><span class="label">Gender:</span><span class="value"><?= htmlspecialchars($appointment['sex']) ?></span></div>
                <div class="info-item"><span class="label">Age:</span><span class="value"><?= $appointment['age_y'] ?>Y <?= $appointment['age_m'] ?>M <?= $appointment['age_d'] ?>D</span></div>
                <div class="info-item"><span class="label">Mobile:</span><span class="value"><?= htmlspecialchars($appointment['mobile'] ?? '') ?></span></div>
                <div class="info-item"><span class="label">Doctor:</span><span class="value"><?= htmlspecialchars($appointment['doctor_name'] ?? '') ?></span></div>
                <div class="info-item"><span class="label">Department:</span><span class="value"><?= htmlspecialchars($appointment['department_name'] ?? '') ?></span></div>
                <div class="info-item"><span class="label">Date:</span><span class="value"><?= date('d-m-Y',strtotime($appointment['appointment_date'])) ?></span></div>
                <div class="info-item"><span class="label">Time:</span><span class="value"><?= htmlspecialchars($appointment['appointment_time'] ?? '') ?></span></div>
                <div class="info-item"><span class="label">City:</span><span class="value"><?= htmlspecialchars($appointment['city_name'] ?? '') ?></span></div>
                <div class="info-item"><span class="label">ABHA ID:</span><span class="value"><?= htmlspecialchars($appointment['abha_id'] ?? '') ?></span></div>
                <div class="info-item" style="grid-column: 1 / -1;"><span class="label">Address:</span><span class="value"><?= nl2br(htmlspecialchars($appointment['address'])) ?></span></div>
            </div>
        </div>
        
        <!-- Admission Details -->
        <div class="card">
            <h3>Admission Details</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label>Admission Date*</label>
                    <input type="date" name="admission_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label>Admission Time*</label>
                    <input type="time" name="admission_time" value="<?= date('H:i') ?>" required>
                </div>
                <div class="form-group">
                    <label>Admission Type*</label>
                    <select name="admission_type" required>
                        <option value="Routine">Routine</option>
                        <option value="Referral">Referral</option>
                        <option value="Transfer">Transfer</option>
                    </select>
                </div>
                <div class="form-group">
    <label>Referral Type</label>
    <select name="referral_type" id="referral_type">
        <option value="">Select</option>
        <option value="Internal">Internal</option>
        <option value="External">External</option>
    </select>
</div>

<div class="form-group">
    <label>
        Referral By
        <button type="button"
id="addReferralBtn"
onclick="openReferralModal()"
style="display:none;margin-left:6px;background:#1976d2;color:#fff;border:1px solid #1565c0;padding:3px 8px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:bold;">
+ Add
</button>
    </label>

    <select id="referral_by" name="referral_by">
        <option value="">Select Referral</option>
    </select>
</div>
                <div class="form-group">
                    <label>Expected Stay (Days)</label>
                    <input type="number" name="expected_stay" min="1" value="1">
                </div>
                <div class="form-group">
                    <label>Building*</label>
                    <select name="building_id" id="building_id" required>
                        <option value="">Select Building</option>
                        <?php
                        $bq=mysqli_query($conn,"SELECT id,building_name FROM building_master WHERE hospital_id='$hospital_id' AND branch_id='$branch_id' AND status='Active' ORDER BY building_name");
                        while($b=mysqli_fetch_assoc($bq)){
                        ?>
                        <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['building_name']) ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Floor*</label>
                    <select name="floor_id" id="floor_id" required>
                        <option value="">Select Floor</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Room*</label>
                    <select name="room_id" id="room_id" required>
                        <option value="">Select Room</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Bed*</label>
                    <select name="bed_id" id="bed_id" required>
                        <option value="">Select Bed</option>
                    </select>
                </div>
                <div class="form-group">
<label>Primary Diagnosis</label>
<input type="text" name="primary_diagnosis" maxlength="255" placeholder="Enter primary diagnosis">
</div>
                
                
                <div class="form-group">
    <label>Payment Type*</label>
    <select name="payment_type" id="payment_type" required>
        <option value="Cash">Cash</option>
        <option value="Credit">Credit</option>
    </select>
</div>
                <div class="form-group">
                    <label>File No.</label>
                    <input type="text" name="file_no" maxlength="100" placeholder="File number">
                </div>
                <div class="form-group">
                    <label>Credit Limit</label>
                    <input type="number"
       id="credit_limit"
       name="credit_limit"
       value="0"
       min="0"
       step="0.01">
                </div>
                    <!-- Other Doctors -->
           <div class="form-group">
<label>Other Doctors</label>
<div class="multi-select">
<div class="select-box" onclick="toggleDropdown('doctorDropdown')">
<span id="doctorText"> Select Doctor(s) </span>
</div>
<div class="dropdown" id="doctorDropdown">
<input  type="text"  placeholder="Search..." onkeyup="filterOptions(this,'doctorDropdown')">
<?php foreach($doctorList as $doc){

if($doc['id']==$appointment['entity_id']){
    continue;
}
?>
<label>
<input type="checkbox"
       name="other_doctors[]"
       value="<?= $doc['id'] ?>"
       onchange="updateDoctors()">
<?= htmlspecialchars($doc['full_name']) ?>
</label>
<?php } ?>
</div>
</div>
</div>
<div class="form-group">
<label>Admission Reason</label>
<textarea name="admission_reason" rows="1" placeholder="Reason for admission"></textarea>
</div>
 <div class="form-group">
<label>Remarks</label>
<textarea name="remarks" rows="1" placeholder="Additional remarks"></textarea>
</div>
                
                <div class="form-group checkbox-group-field" style="grid-column:span 2;">
<label>Special Flags</label>
<div class="form-check">
<label><input type="checkbox" name="is_vip" value="1"> VIP</label>
<label><input type="checkbox" name="is_mlc" id="is_mlc" value="1"> Medico Legal Case</label>
</div>
</div>
            </div>
            
            <!-- MLC Details -->
            <div id="mlcRow" style="display:none;">
                <div class="mlc-grid">
                    <div class="form-group">
                        <label>MLC Type</label>
                        <select name="mlc_type">
                            <option value="">Select</option>
                            <option value="Road Traffic Accident">Road Traffic Accident</option>
                            <option value="Assault">Assault</option>
                            <option value="Poisoning">Poisoning</option>
                            <option value="Burn Case">Burn Case</option>
                            <option value="Fall Injury">Fall Injury</option>
                            <option value="Workplace Injury">Workplace Injury</option>
                            <option value="Unknown">Unknown</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Police Station</label>
                        <input type="text" name="police_station" placeholder="Police station name">
                    </div>
                    <div class="form-group">
                        <label>Accident Place</label>
                        <input type="text" name="accident_place" placeholder="Accident location">
                    </div>
                    <div class="form-group">
                        <label>Certificate / FIR No.</label>
                        <input type="text" name="fir_no" placeholder="FIR number">
                    </div>
                    <div class="form-group full-width">
                        <label>MLC Remarks</label>
                        <textarea name="mlc_remarks" rows="2" placeholder="MLC remarks"></textarea>
                    </div>
                </div>
            </div>
            
           
    </div>
    
        <!-- Attendant Details -->
        <div class="card">
<h3>Attendant Details</h3>
<div class="attendant-row">
<div class="form-group">
<label>Attendant Name</label>
<input type="text" name="attendant_name" placeholder="Attendant full name">
</div>
<div class="form-group">
<label>Attendant Mobile</label>
<input type="tel" name="attendant_mobile" id="attendant_mobile" maxlength="10" inputmode="numeric" pattern="[6-9][0-9]{9}" placeholder="Mobile number" title="Enter a valid 10-digit mobile number">
</div>
<div class="form-group">
<label>Status</label>
<input type="text" value="Admitted" readonly style="background:#f1f5f9;cursor:not-allowed;">
</div>
<div class="attendant-actions">
<button type="submit" class="btn btn-primary">🏥 Admit Patient</button>
<a href="javascript:history.back()" class="btn btn-secondary">Cancel</a>
</div>
</div>
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
        .then(response=>response.text())
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
        .then(response=>response.text())
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
        .then(response=>response.text())
        .then(data=>{
            document.getElementById('bed_id').innerHTML=data;
        });
});

document.getElementById('is_mlc').addEventListener('change',function(){
    document.getElementById('mlcRow').style.display = this.checked ? 'block' : 'none';
});
function toggleDropdown(id){
    const d=document.getElementById(id);
    d.style.display=d.style.display==='block'?'none':'block';
}
function filterOptions(input,id){
    let v=input.value.toLowerCase();
    document.querySelectorAll('#'+id+' label').forEach(l=>{
        l.style.display=l.textContent.toLowerCase().includes(v)?'':'none';
    });
}
document.addEventListener('click',e=>{
    document.querySelectorAll('.dropdown').forEach(d=>{
        if(!d.parentElement.contains(e.target)) d.style.display='none';
    });
});
function updateDoctors(){

let count=document.querySelectorAll('#doctorDropdown input:checked').length;

document.getElementById('doctorText').innerText=

count
? count+' selected'
: 'Select Doctor(s)';

}
document.getElementById('referral_type').addEventListener('change',function(){

    const type=this.value;
    const dd=document.getElementById('referral_by');

    dd.innerHTML='<option value="">Loading...</option>';

    if(type===''){
        dd.innerHTML='<option value="">Select Referral</option>';
        return;
    }

    if(type==='Internal'){
        fetch('/modules/registration/ajax/get_active_users.php')
        .then(r=>r.json())
        .then(data=>{

            dd.innerHTML='<option value="">Select User</option>';

            data.forEach(u=>{
                dd.innerHTML+=`<option value="${u.id}">${u.full_name}</option>`;
            });

        });
    }

    if(type==='External'){

    document.getElementById('addReferralBtn').style.display='inline-block';

    loadExternalReferrals();

}else{

    document.getElementById('addReferralBtn').style.display='none';

}

});
</script>
<script>
    function loadExternalReferrals() {
    fetch('/modules/registration/ajax/get_external_referrals.php')
        .then(res => res.json())
        .then(data => {
            const dd = document.getElementById('referral_by');
            dd.innerHTML = '<option value="">Select Referral</option>';
            if (!Array.isArray(data) || data.length === 0) {
                dd.innerHTML += '<option value="">No Referrals Found</option>';
                return;
            }
            data.forEach(r => {
                dd.innerHTML +=
                    `<option value="${r.id}">${r.name}</option>`;
            });
        })
        .catch(err => {
            console.error(err);
            alert('Failed to load referrals');
        });
}
</script>
<!-- ================= REFERRAL MODAL ================= -->
<div class="modal" id="referralModal" style="display:none;">
  <div class="modal-box">
    <span class="close" onclick="closeReferralModal()">×</span>
    <h3>Add External Referral</h3>
    <div class="grid referral-form-grid">
        <div>
            <label>Name *</label>
            <input type="text" id="rm_name">
        </div>
        <div>
            <label>Category *</label>
            <select id="rm_category">
                <option value="">Select</option>
                <option value="Hospital">Hospital</option>
                <option value="Clinic">Clinic</option>
                <option value="Laboratory">Laboratory</option>
                <option value="Trust">Trust</option>
                <option value="In Person">In Person</option>
            </select>
        </div>
        <div>
            <label>Organization Name</label>
            <input type="text" id="rm_org_name">
        </div>
        <div>
            <label>Mobile</label>
            <input type="text" id="rm_mobile">
        </div>
        <div>
            <label>City *</label>
            <select id="rm_city">
                <option value="">Select City</option>
                <?php foreach ($cities as $c): ?>
                    <option value="<?= $c['id'] ?>">
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="referral-modal-actions">
<button type="button" class="btn btn-secondary" onclick="closeReferralModal()">Cancel</button>
<button type="button" class="btn btn-primary" onclick="saveReferral()">Save</button>
</div>
  </div>
</div>
<script>
    function openReferralModal(){
    document.getElementById('referralModal').style.display = 'flex';
}
function closeReferralModal(){
    document.getElementById('referralModal').style.display = 'none';
}
function saveReferral(){
    const name     = document.getElementById('rm_name').value.trim();
    const category = document.getElementById('rm_category').value;
    const org_name = document.getElementById('rm_org_name').value.trim();
    const mobile   = document.getElementById('rm_mobile').value.trim();
    const city_id  = document.getElementById('rm_city').value;
    if (!name || !category || !city_id) {
        alert('Please fill required fields');
        return;
    }
    fetch('/modules/registration/ajax/save_referral.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:
            `name=${encodeURIComponent(name)}`
            + `&category=${encodeURIComponent(category)}`
            + `&org_name=${encodeURIComponent(org_name)}`
            + `&mobile=${encodeURIComponent(mobile)}`
            + `&city_id=${encodeURIComponent(city_id)}`
    })
    .then(res=>res.json())
    .then(data=>{

        if(data.error){
            alert(data.error);
            return;
        }
        if(data.id){
            loadExternalReferrals();
            setTimeout(()=>{
                document.getElementById('referral_by').value = data.id;
            },300);
            closeReferralModal();
        }
    })
    .catch(err=>{
        console.error(err);
        alert('Failed to save referral');
    });
}
</script>
<script>

const payment=document.getElementById("payment_type");
const credit=document.getElementById("credit_limit");

function toggleCredit(){

    if(payment.value==="Cash"){

        credit.value=0;
        credit.readOnly=true;
        credit.style.background="#eeeeee";

    }else{

        credit.readOnly=false;
        credit.style.background="#ffffff";

    }

}

payment.addEventListener("change",toggleCredit);

toggleCredit();
document.getElementById('attendant_mobile').addEventListener('input',function(){
this.value=this.value.replace(/\D/g,'').slice(0,10);
});
document.getElementById('admissionForm').addEventListener('submit',function(e){
const mobile=document.getElementById('attendant_mobile').value.trim();
if(mobile!==''&&!/^[6-9][0-9]{9}$/.test(mobile)){
e.preventDefault();
alert('Please enter a valid 10-digit Attendant Mobile number.');
document.getElementById('attendant_mobile').focus();
}
});
</script>
</body>
</html>