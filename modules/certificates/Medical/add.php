<?php
session_start();
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
checkPermission('medical_certificate_add','add');
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$user_id=$_SESSION['user_id']??0;
$hospital_id=$_SESSION['hospital_id']??0;
$branch_id=$_SESSION['branch_id']??0;
$mode='Direct';
if(isset($_GET['appointment_id']) && (int)$_GET['appointment_id']>0){$mode='OPD';}
if(isset($_GET['ipd_admission_id']) && (int)$_GET['ipd_admission_id']>0){$mode='IPD';}
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
$certificate_no='';
$q=mysqli_query($conn,"SELECT certificate_no FROM medical_certificates ORDER BY id DESC LIMIT 1");
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
$certificate_no='MC-'.date('Ymd').'-'.$next;
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
}
if($mode=='IPD' && $ipd_admission_id>0){
$sql="SELECT
i.id,
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
}
$doctorList=mysqli_query($conn,"
SELECT
dm.id,
u.full_name
FROM doctor_master dm
INNER JOIN users u ON u.id=dm.user_id
WHERE u.status='Active'
ORDER BY u.full_name
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Create Medical Certificate</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/style.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link rel="icon" href="/assets/img/hms_logo.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
    /* Enhanced UI Styles */
    .main-content1 {
        padding: 5px 20px 20px 20px;
        background: #f0f2f5;
        min-height: 100vh;
    }
    @media (max-width: 768px) {
        .main-content {
            margin-left: 0;
            padding: 10px;
        }
    }
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }
    .card {
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 4px 12px rgba(0,0,0,.08);
        margin-bottom: 10px;
        overflow: hidden;
        transition: box-shadow 0.2s;
    }
    .card-header {
        background: #0d6efd;
        color: #fff;
        padding: 12px 18px;
        font-size: 15px;
        font-weight: bold;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .card-header h3 {
        margin: 0;
        font-size: 15px;
        font-weight: bold;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .card-header h3 i {
        font-size: 16px;
    }
    .card-body {
        padding: 5px 20px;
    }
    .col-md-3 { flex: 0 0 20%; max-width: 25%; padding: 0 8px; }
    .col-md-4 { flex: 0 0 20%; max-width: 33.333%; padding: 0 8px; }
    .col-md-5 { flex: 0 0 20%; max-width: 41.666%; padding: 0 8px; }
    .col-md-6 { flex: 0 0 20%; max-width: 50%; padding: 0 8px; }
    .col-md-12 { flex: 0 0 20%; max-width: 100%; padding: 0 8px; }
    @media (max-width: 768px) {
        .col-md-2, .col-md-3, .col-md-4, .col-md-5, .col-md-6 {
            flex: 0 0 100%;
            max-width: 100%;
            margin-bottom: 10px;
        }
    }
    label {
        font-weight: 600;
        font-size: 13px;
        margin-bottom: 4px;
        display: block;
        color: #1e293b;
    }
    .form-control {
        width: 100%;
        padding: 6px 10px;
        border: 1px solid #ced4da;
        border-radius: 4px;
        font-size: 13px;
        transition: 0.15s;
        background: #fff;
    }
    .form-control:focus {
        border-color: #0d6efd;
        outline: none;
        box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.15);
    }
    .form-control[readonly] {
        background: #f5f5f5;
    }
    .form-control:disabled {
        background: #e9ecef;
        cursor: not-allowed;
    }
    
    .text-danger {
        color: #dc3545;
    }
    .mt-3 { margin-top: 15px; }
    .mb-3 { margin-bottom: 15px; }
    .mt-4 { margin-top: 25px; }
    .mb-4 { margin-bottom: 25px; }
    .text-end {
        text-align: right;
    }
    /* Form section dividers */
    .form-section-title {
        font-size: 14px;
        font-weight: 600;
        color: #0d6efd;
        margin-bottom: 15px;
        padding-bottom: 8px;
        border-bottom: 2px solid #e9ecef;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    /* Responsive fixes */
    @media (max-width: 768px) {
        .page-header {
            flex-direction: column;
            align-items: flex-start;
        }
        .page-actions {
            width: 100%;
        }
        .page-actions .btn {
            flex: 1;
            text-align: center;
        }
        .text-end {
            text-align: center;
        }
        .text-end .btn {
            margin-bottom: 8px;
            width: 100%;
        }
    }
</style>
</head>
<body>
<div class="main-content1">
    <div class="page-header">
        
        <div class="page-actions">
            
        </div>
    </div>
    <form method="post" action="save.php" id="medicalCertificateForm" autocomplete="off">
        <input type="hidden" id="patient_id" name="patient_id" value="<?=e($patient['id'])?>">
<input type="hidden" id="selected_opd_id" name="selected_opd_id">
<input type="hidden" id="selected_ipd_id" name="selected_ipd_id">
        <input type="hidden" id="appointment_id" name="appointment_id" value="<?=e($appointment_id)?>">
        <input type="hidden" id="ipd_admission_id" name="ipd_admission_id" value="<?=e($ipd_admission_id)?>">
        <input type="hidden" name="hospital_id" value="<?=e($hospital_id)?>">
        <input type="hidden" name="branch_id" value="<?=e($branch_id)?>">
        <!-- Certificate Information Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-certificate"></i> Certificate Information</h3>
                        </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <label>Certificate No <span class="required-star">*</span></label>
                        <input type="text" name="certificate_no" class="form-control" value="<?=e($certificate_no)?>" readonly>
                    </div>
                    <div class="col-md-4">
                        <label>Certificate Date <span class="required-star">*</span></label>
                        <input type="date" name="certificate_date" class="form-control" value="<?=$certificate_date?>" required>
                    </div>
                    <div class="col-md-4">
                        <label>Patient Type <span class="required-star">*</span></label>
                        <select name="patient_type" id="patient_type" class="form-control" required <?=($mode!='Direct')?'disabled':'';?>>
                            <option value="Direct" <?=$mode=='Direct'?'selected':'';?>>Direct</option>
                            <option value="OPD" <?=$mode=='OPD'?'selected':'';?>>OPD</option>
                            <option value="IPD" <?=$mode=='IPD'?'selected':'';?>>IPD</option>
                        </select>
                        <input type="hidden" id="sex_hidden" name="sex" value="<?=e($patient['sex'])?>" <?=($mode=='Direct')?'disabled':'';?>>
                    </div>
                    
                    
                    <div class="row mb-3">
<div class="col-md-12" id="opd_patient_div" style="display:none;">
<label>Select OPD Patient</label>
<select id="opd_patient_search" class="form-control" style="width:100%;">
</select>
</div>
<div class="col-md-12" id="ipd_patient_div" style="display:none;">
<label>Select IPD Patient</label>
<select id="ipd_patient_search" class="form-control" style="width:100%;">
</select>
</div>
</div>
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
                        <input type="text" name="uhid" id="uhid" class="form-control" value="<?=e($patient['uhid'])?>" readonly>
                    </div>
                    <div class="col-md-5">
                        <label>Patient Name <span class="required-star">*</span></label>
                        <input type="text" name="patient_name" id="patient_name" class="form-control" value="<?=e($patient['patient_name'])?>" required <?=$mode=='Direct'?'':'readonly';?>>
                    </div>
                    <div class="col-md-4">
                        <label>Guardian Name</label>
                        <input type="text" name="guardian_name" id="guardian_name" class="form-control" value="<?=e($patient['guardian_name'])?>" <?=$mode=='Direct'?'':'readonly';?>>
                    </div>
                    <div class="col-md-3">
    <label>Age (Y | M | D)</label>
    <div style="display:flex;gap:6px;">
        <input
            type="number"
            id="age_y"
            name="age_y"
            class="form-control text-center"
            placeholder="Y"
            value="<?=e($patient['age_y'])?>"
            oninput="ageToDob()">
        <input
            type="number"
            id="age_m"
            name="age_m"
            class="form-control text-center"
            placeholder="M"
            value="<?=e($patient['age_m'])?>"
            oninput="ageToDob()">
        <input
            type="number"
            id="age_d"
            name="age_d"
            class="form-control text-center"
            placeholder="D"
            value="<?=e($patient['age_d'])?>"
            oninput="ageToDob()">
    </div>
</div>
<div class="col-md-3">
    <label>Date of Birth</label>
    <input
        type="date"
        id="dob"
        name="dob"
        class="form-control"
        value="<?=e($patient['dob'])?>"
        onchange="dobToAge()">
</div>
<div class="col-md-2">
                        <label>Gender</label>
                        <select name="sex" id="sex" class="form-control" <?=$mode=='Direct'?'':'disabled';?>>
                            <option value="">Select</option>
                            <option value="Male" <?=$patient['sex']=='Male'?'selected':'';?>>Male</option>
                            <option value="Female" <?=$patient['sex']=='Female'?'selected':'';?>>Female</option>
                            <option value="Other" <?=$patient['sex']=='Other'?'selected':'';?>>Other</option>
                        </select>
                        
                    </div>
                    <div class="col-md-4">
                        <label>Mobile</label>
                        <input type="text"
       name="mobile"
       id="mobile"
       class="form-control"
       value="<?=e($patient['mobile'])?>"
       maxlength="10"
       inputmode="numeric"
       pattern="[6-9][0-9]{9}"
       <?=$mode=='Direct'?'':'readonly';?>>
                    </div>
                    <div class="col-md-4">
                        <label>Marital Status</label>
                        <select name="marital_status" id="marital_status" class="form-control" <?=$mode=='Direct'?'':'disabled';?>>
                            <option value="">Select</option>
                            <option value="Unmarried" <?=($patient['marital_status']=='Unmarried' || $patient['marital_status']=='Single')?'selected':'';?>>Unmarried</option>
                            <option value="Married" <?=$patient['marital_status']=='Married'?'selected':'';?>>Married</option>
                            <option value="Divorced" <?=$patient['marital_status']=='Divorced'?'selected':'';?>>Divorced</option>
                        </select>
                        <input type="hidden"
       id="marital_status_hidden"
       name="marital_status"
       value="<?=e($patient['marital_status']=='Single'?'Unmarried':$patient['marital_status'])?>"
       <?=($mode=='Direct')?'disabled':'';?>>
                    </div>
                    <div class="col-md-12">
                        <label>Address</label>
                        <textarea name="address" id="address" class="form-control" rows="2" <?=$mode=='Direct'?'':'readonly';?>><?=e($patient['address'])?></textarea>
                    </div>
                </div>
            </div>
        </div>
        <!-- Medical Details Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-stethoscope"></i> Medical Details</h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <label>Suffering From <span class="required-star">*</span></label>
                        <textarea name="suffering_from" class="form-control" rows="3" required placeholder="Enter diagnosis or condition"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label>Purpose <span class="required-star">*</span></label>
                        <select name="purpose" id="purpose" class="form-control" required>
    <option value="">Select Purpose</option>
    <option value="Medical Leave">Medical Leave</option>
    <option value="Fitness">Fitness</option>
    <option value="Insurance">Insurance</option>
    <option value="School">School</option>
    <option value="Employment">Employment</option>
    <option value="Court">Court</option>
    <option value="Other">Other</option>
</select>
                    </div>
                    <div class="col-md-12" id="purpose_other_div" style="display:none;">
                        <label>Other Purpose</label>
                        <input type="text" name="purpose_other" id="purpose_other" class="form-control" placeholder="Please specify">
                    </div>
                    <div class="col-md-6">
    <label>Issued To <span class="required-star">*</span></label>
    <select name="issued_to" id="issued_to" class="form-control" required>
        <option value="">Select</option>
        <option value="Patient">Patient</option>
        <option value="Attendant">Attendant</option>
        <option value="Employer">Employer</option>
        <option value="School">School</option>
        <option value="Insurance Company">Insurance Company</option>
        <option value="Court">Court</option>
        <option value="Other">Other</option>
    </select>
</div>
<div class="col-md-6" id="issued_to_other_div" style="display:none;">
    <label>Other Issued To</label>
    <input
        type="text"
        name="issued_to_other"
        id="issued_to_other"
        class="form-control"
        placeholder="Please Specify">
</div>
                    <div class="col-md-3">
                        <label>Rest From</label>
                        <input type="date" name="rest_from" id="rest_from" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label>Rest To</label>
                        <input type="date" name="rest_to" id="rest_to" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label>Joining Date</label>
                        <input type="date" name="joining_date" id="joining_date" class="form-control" readonly style="background:#f5f5f5;">
                    </div>
                    <div class="col-md-3">
                        <label>Fitness Status <span class="required-star">*</span></label>
                        <select name="fitness_status" class="form-control" required>
                            <option value="">Select</option>
<option value="Unfit for Duty">Unfit for Duty</option>
<option value="Fit to Join">Fit to Join</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label>Doctor <span class="required-star">*</span></label>
                        <select name="doctor_id" id="doctor_id" class="form-control" required>
                            <option value="">Select Doctor</option>
                            <?php while($dr=mysqli_fetch_assoc($doctorList)){ ?>
                                <option value="<?=$dr['id']?>" <?=$doctor['id']==$dr['id']?'selected':'';?>><?=e($dr['full_name'])?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <input
type="hidden"
id="doctor_registration_no"
name="doctor_registration_no"
value="<?=e($doctor['registration_no'])?>">
                    <div class="col-md-12">
                        <label>Remarks</label>
                        <textarea name="remarks" class="form-control" rows="3" placeholder="Additional notes..."></textarea>
                    </div>
                </div>
            </div>
        </div>
        <!-- Action Buttons -->
        <div class="text-end mt-4 mb-4" style="padding: 0 10px;">
            <button type="submit" name="action" value="save" class="btn btn-success">
                <i class="fas fa-save"></i> Save
            </button>
            <button type="submit" name="action" value="save_print" class="btn btn-primary">
                <i class="fas fa-print"></i> Save & Print
            </button>
            <a href="medical_certificate.php" class="btn btn-secondary">
                <i class="fas fa-times"></i> Cancel
            </a>
        </div>
    </form>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
// Purpose show/hide
document.getElementById('purpose').addEventListener('change', function() {
    if (this.value === 'Other') {
        document.getElementById('purpose_other_div').style.display = 'block';
        document.getElementById('purpose_other').focus();
    } else {
        document.getElementById('purpose_other_div').style.display = 'none';
        document.getElementById('purpose_other').value = '';
    }
});
// Calculate joining date
document.getElementById('rest_from').addEventListener('change', calculateJoiningDate);
document.getElementById('rest_to').addEventListener('change', calculateJoiningDate);
function calculateJoiningDate() {
    let from = document.getElementById('rest_from').value;
    let to = document.getElementById('rest_to').value;
    if (from === '' || to === '') return;
    if (to < from) {
        alert('Rest To Date cannot be earlier than Rest From Date.');
        document.getElementById('rest_to').value = '';
        document.getElementById('joining_date').value = '';
        return;
    }
    let d = new Date(to);
    d.setDate(d.getDate() + 1);
    let y = d.getFullYear();
    let m = ('0' + (d.getMonth() + 1)).slice(-2);
    let day = ('0' + d.getDate()).slice(-2);
    document.getElementById('joining_date').value = y + '-' + m + '-' + day;
}
// Form validation
document.getElementById('medicalCertificateForm').addEventListener('submit', function(e) {
    let patient = document.getElementById('patient_name').value.trim();
    let doctor = document.getElementById('doctor_id').value;
    let purpose = document.getElementById('purpose').value;
    let fitness = document.querySelector('[name="fitness_status"]').value;
    let disease = document.querySelector('[name="suffering_from"]').value.trim();
    let issuedTo = document.getElementById('issued_to').value;
    let mobile=$("#mobile").val().trim();
if($("#patient_type").val()=="Direct"){
    if(mobile==""){
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
    if (patient === '') {
        alert('Please enter Patient Name.');
        document.getElementById('patient_name').focus();
        e.preventDefault();
        return;
    }
    if (disease === '') {
        alert('Please enter Suffering From.');
        document.querySelector('[name="suffering_from"]').focus();
        e.preventDefault();
        return;
    }
    if (purpose === '') {
        alert('Please select Purpose.');
        document.getElementById('purpose').focus();
        e.preventDefault();
        return;
    }
    if (purpose === 'Other' && document.getElementById('purpose_other').value.trim() === '') {
        alert('Please enter Other Purpose.');
        document.getElementById('purpose_other').focus();
        e.preventDefault();
        return;
    }
    if (issuedTo === '') {
    alert('Please select Issued To.');
    document.getElementById('issued_to').focus();
    e.preventDefault();
    return;
}

if (issuedTo === 'Other' && document.getElementById('issued_to_other').value.trim() === '') {
    alert('Please enter Other Issued To.');
    document.getElementById('issued_to_other').focus();
    e.preventDefault();
    return;
}
    if (doctor === '') {
        alert('Please select Doctor.');
        document.getElementById('doctor_id').focus();
        e.preventDefault();
        return;
    }
    if (fitness === '') {
        alert('Please select Fitness Status.');
        e.preventDefault();
        return;
    }
});
// On load
window.onload=function(){
    if(document.getElementById('purpose').value==="Other"){
        document.getElementById('purpose_other_div').style.display="block";
    }
    if(document.getElementById('issued_to').value==="Other"){
        document.getElementById('issued_to_other_div').style.display="block";
    }
};
/* ===========================================================
   Medical Certificate Patient Search
===========================================================*/
function togglePatientSearch(){
    let type=$("#patient_type").val();
    $("#opd_patient_div,#ipd_patient_div").hide();
    $("#opd_patient_search").val(null).trigger("change");
    $("#ipd_patient_search").val(null).trigger("change");
    $("#selected_opd_id").val("");
    $("#selected_ipd_id").val("");
    if(type!="Direct"){
    $("#doctor_id").val("").trigger("change");
    $("#doctor_registration_no").val("");
}
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
    $("#sex_hidden").val("");
$("#marital_status_hidden").val("");
    if(type=="Direct"){
    $("#doctor_id").val("").trigger("change");
    $("#doctor_registration_no").val("");
}
    if(type=="Direct"){
    $("#patient_name,#guardian_name,#mobile,#address").prop("readonly",false);
    $("#dob,#age_y,#age_m,#age_d").prop("readonly",false);
    $("#sex,#marital_status").prop("disabled",false);
    $("#sex_hidden").prop("disabled",true);
    $("#marital_status_hidden").prop("disabled",true);
}
    if(type=="OPD"){
        $("#opd_patient_div").show();
        $("#patient_name,#guardian_name,#mobile,#address").prop("readonly",true);
$("#dob,#age_y,#age_m,#age_d").prop("readonly",true);
        $("#sex,#marital_status").prop("disabled",true);
         $("#sex_hidden").prop("disabled",false);
         $("#marital_status_hidden").prop("disabled",false);
    }
    if(type=="IPD"){
        $("#ipd_patient_div").show();
        $("#patient_name,#guardian_name,#mobile,#address").prop("readonly",true);
$("#dob,#age_y,#age_m,#age_d").prop("readonly",true);
        $("#sex,#marital_status").prop("disabled",true);
        $("#sex_hidden").prop("disabled",false);
        $("#marital_status_hidden").prop("disabled",false);
    }
}
$("#patient_type").on("change",function(){
    togglePatientSearch();
});
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
        $("#uhid").val(res.uhid);
        $("#patient_name").val(res.patient_name);
        $("#guardian_name").val(res.guardian_name);
        $("#dob").val(res.dob);
dobToAge();
        $("#mobile").val(res.mobile);
        $("#address").val(res.address);
        $("#sex").val(res.sex);
$("#sex_hidden").val(res.sex);

let maritalStatus=res.marital_status;

if(maritalStatus==="Single"){
    maritalStatus="Unmarried";
}

$("#marital_status").val(maritalStatus);
$("#marital_status_hidden").val(maritalStatus);
        $("#doctor_id").val(res.doctor_id).trigger("change");
        $("#doctor_registration_no").val(res.doctor_registration_no);
    });
});
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
        $("#appointment_id").val(res.appointment_id);
        $("#uhid").val(res.uhid);
        $("#patient_name").val(res.patient_name);
        $("#guardian_name").val(res.guardian_name);
$("#dob").val(res.dob);
dobToAge();
        $("#mobile").val(res.mobile);
        $("#address").val(res.address);
        $("#sex").val(res.sex);
$("#sex_hidden").val(res.sex);

let maritalStatus=res.marital_status;

if(maritalStatus==="Single"){
    maritalStatus="Unmarried";
}

$("#marital_status").val(maritalStatus);
$("#marital_status_hidden").val(maritalStatus);
        $("#doctor_id").val(res.doctor_id).trigger("change");
        $("#doctor_registration_no").val(res.doctor_registration_no);
       
    });
});
document.getElementById('issued_to').addEventListener('change', function () {
    if(this.value==="Other"){
        document.getElementById('issued_to_other_div').style.display="block";
    }else{
        document.getElementById('issued_to_other_div').style.display="none";
        document.getElementById('issued_to_other').value="";
    }
});
$("#mobile").on("input", function(){

    this.value=this.value.replace(/\D/g,'');

    if(this.value.length>10){
        this.value=this.value.substring(0,10);
    }

});
$("#opd_patient_search").on("select2:clear",function(){
    togglePatientSearch();
});

$("#ipd_patient_search").on("select2:clear",function(){
    togglePatientSearch();
});
function dobToAge(){

    let dob=$("#dob").val();
    if(dob=="") return;
    let birth=new Date(dob);
    let today=new Date();
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
    $("#age_y").val(years);
    $("#age_m").val(months);
    $("#age_d").val(days);
}
function ageToDob(){
    let y=parseInt($("#age_y").val())||0;
    let m=parseInt($("#age_m").val())||0;
    let d=parseInt($("#age_d").val())||0;
    let today=new Date();
    let dob=new Date(
        today.getFullYear()-y,
        today.getMonth()-m,
        today.getDate()-d
    );
    let yyyy=dob.getFullYear();
    let mm=String(dob.getMonth()+1).padStart(2,'0');
    let dd=String(dob.getDate()).padStart(2,'0');
    $("#dob").val(yyyy+"-"+mm+"-"+dd);
}
</script>
</body>
</html>