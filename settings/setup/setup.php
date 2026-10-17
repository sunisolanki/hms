<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
/* Hospitals */
$hospitalResult=mysqli_query($conn,"
SELECT
id,
hospital_name
FROM hospital_master
WHERE status='1'
ORDER BY hospital_name
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Prescription Setup - WebSplend HMS</title>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
.top-bar{
position:relative;
}
.top-bar h2{
position:absolute;
left:50%;
transform:translateX(-50%);
margin:0;
}
.setup-card{
background:#fff;
border-radius:10px;
padding:20px;
margin:20px;
box-shadow:0 2px 10px rgba(0,0,0,.08);
}
.success-msg{
background:#d1fae5;
color:#065f46;
padding:10px 15px;
border-radius:6px;
margin-bottom:15px;
border:1px solid #10b981;
}

.error-msg{
background:#fee2e2;
color:#991b1b;
padding:10px 15px;
border-radius:6px;
margin-bottom:15px;
border:1px solid #ef4444;
}
.setup-title{
font-size:18px;
font-weight:600;
margin-bottom:20px;
color:#1565c0;
}
.form-row{
display:flex;
gap:15px;
margin-bottom:20px;
align-items:end;
}
.form-group{
flex:1;
}
.form-group label{
display:block;
margin-bottom:6px;
font-weight:600;
}
.form-group select{
width:100%;
padding:9px;
border:1px solid #ccc;
border-radius:6px;
}
.section-title{
font-size:16px;
font-weight:600;
margin:20px 0 15px;
padding-bottom:8px;
border-bottom:1px solid #ddd;
}
/* Improved Tab Grid */
.tab-grid{
display:grid;
grid-template-columns:repeat(auto-fill, minmax(250px, 1fr));
gap:15px;
}
.tab-box{
background:#f8f9fb;
border:2px solid #e0e0e0;
border-radius:10px;
padding:15px;
display:flex;
align-items:center;
gap:12px;
cursor:pointer;
transition:all 0.3s ease;
position:relative;
}
.tab-box:hover{
background:#f0f7ff;
border-color:#1976d2;
transform:translateY(-2px);
box-shadow:0 4px 12px rgba(25,118,210,0.15);
}
.tab-box input[type="checkbox"]{
width:20px;
height:20px;
cursor:pointer;
accent-color:#1976d2;
}
.tab-box span{
font-size:15px;
font-weight:500;
color:#333;
}
/* Notes Card Styles */
.notes-card{
background:#fff;
border:2px solid #e0e0e0;
border-radius:10px;
padding:15px;
transition:all 0.3s ease;
}
.notes-card:hover{
border-color:#1976d2;
box-shadow:0 4px 12px rgba(25,118,210,0.1);
}
.notes-card .tab-box{
border:none;
padding:0 0 15px 0;
margin-bottom:0;
background:transparent;
}
.notes-card .tab-box:hover{
background:transparent;
transform:none;
box-shadow:none;
}
#notes-sections{
display:grid;
grid-template-columns:repeat(auto-fill, minmax(200px, 1fr));
gap:10px;
padding:10px 0 0 10px;
border-top:1px solid #f0f0f0;
margin-top:10px;
}
#notes-sections label{
display:flex;
align-items:center;
gap:8px;
padding:8px 10px;
background:#fafafa;
border-radius:6px;
cursor:pointer;
transition:all 0.2s ease;
font-size:14px;
}
#notes-sections label:hover{
background:#e8f4fd;
}
#notes-sections label input[type="checkbox"]{
width:16px;
height:16px;
cursor:pointer;
accent-color:#1976d2;
}
#notes-sections label.disabled{
opacity:0.5;
pointer-events:none;
background:#f5f5f5;
}
#parameters-sections{
display:grid;
grid-template-columns:repeat(auto-fill, minmax(200px,1fr));
gap:10px;
padding:10px 0 0 10px;
border-top:1px solid #f0f0f0;
margin-top:10px;
}

#parameters-sections label{
display:flex;
align-items:center;
gap:8px;
padding:8px 10px;
background:#fafafa;
border-radius:6px;
cursor:pointer;
font-size:14px;
}

#parameters-sections label input[type="checkbox"]{
width:16px;
height:16px;
cursor:pointer;
accent-color:#1976d2;
}
.button-row{
margin-top:25px;
display:flex;
justify-content:flex-end;
gap:10px;
}
.btn{
padding:10px 20px;
border:none;
border-radius:6px;
cursor:pointer;
font-size:14px;
font-weight:500;
transition:all 0.3s ease;
display:inline-flex;
align-items:center;
gap:8px;
}
.btn-primary{
background:#1976d2;
color:#fff;
}
.btn-primary:hover:not(:disabled){
background:#1565c0;
transform:translateY(-1px);
box-shadow:0 4px 8px rgba(25,118,210,0.3);
}
.btn-primary:disabled{
background:#b0bec5;
cursor:not-allowed;
}
.btn-secondary{
background:#fff;
color:#1976d2;
border:2px solid #1976d2;
}
.btn-secondary:hover{
background:#e3f2fd;
}
/* Selected state for tab boxes */
.tab-box.selected{
background:#e8f4fd;
border-color:#1976d2;
}
@media(max-width:900px){
.tab-grid{
grid-template-columns:repeat(2,1fr);
}
.form-row{
flex-direction:column;
}
#notes-sections{
grid-template-columns:1fr;
}
}
@media(max-width:600px){
.tab-grid{
grid-template-columns:1fr;
}
}
</style>
</head>
<body>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<div class="top-bar">
<div class="breadcrumb">
<a href="/dashboard/index.php">Home</a>
<span>&gt;</span>
<a href="/settings/settings.php">Settings</a>
<span>&gt;</span>
<a>Prescription Setup</a>
</div>
<h2>Prescription Setup</h2>
</div>
<div class="setup-card">
    <?php
if(isset($_SESSION['success'])){
?>
<div class="success-msg">
<?= $_SESSION['success']; ?>
</div>
<?php
unset($_SESSION['success']);
}
?>

<?php
if(isset($_SESSION['error'])){
?>
<div class="error-msg">
<?= $_SESSION['error']; ?>
</div>
<?php
unset($_SESSION['error']);
}
?>
<form
id="prescriptionSetupForm"
method="post"
action="save_prescription_setup.php">
<div class="form-row">
<div class="form-group">
<label>Hospital</label>
<select
name="hospital_id"
id="hospital_id"
required>
<option value="">
Select Hospital
</option>
<?php while($h=mysqli_fetch_assoc($hospitalResult)){ ?>
<option
value="<?= $h['id'] ?>">
<?= htmlspecialchars($h['hospital_name']) ?>
</option>
<?php } ?>
</select>
</div>
<div class="form-group">
<label>Branch</label>
<select
name="branch_id"
id="branch_id"
required>
<option value="">
Select Branch
</option>
</select>
</div>
<div class="form-group">
<label>Doctor</label>
<select
name="doctor_id"
id="doctor_id"
required>
<option value="">
Select Doctor
</option>
</select>
</div>
</div>
<div class="section-title">
<i class="fa fa-table"></i>
Prescription Tabs
</div>
<div class="tab-grid">
<!-- Notes Tab with Sub-sections -->
<div class="notes-card">
<label class="tab-box" id="notesMainTab">
    <input
        type="checkbox"
        id="notesTab"
        name="tabs[]"
        value="notes"
        checked>
    <span>📝 Notes</span>
</label>
<div id="notes-sections">

<label><input type="checkbox" name="note_sections[]" value="chief_complaint" checked> <span>Chief Complaint</span></label>
<label><input type="checkbox" name="note_sections[]" value="drug_history" checked> <span>Drug History</span></label>
<label><input type="checkbox" name="note_sections[]" value="past_history" checked> <span>Past History</span></label>
<label><input type="checkbox" name="note_sections[]" value="present_illness_history" checked> <span>Present Illness History</span></label>
<label><input type="checkbox" name="note_sections[]" value="diagnosis_section" checked> <span>Diagnosis</span></label>
<label><input type="checkbox" name="note_sections[]" value="medicines" checked> <span>Medicines</span></label>
<label><input type="checkbox" name="note_sections[]" value="procedure_advice" checked> <span>Procedure Advice</span></label>
<label><input type="checkbox" name="note_sections[]" value="advice" checked> <span>Advice</span></label>
<label><input type="checkbox" name="note_sections[]" value="prognosis" checked> <span>Prognosis</span></label>
<label><input type="checkbox" name="note_sections[]" value="remarks" checked> <span>Remarks</span></label>
</div>
</div>
<!-- Other Tabs -->
<label class="tab-box">
<input type="checkbox"
name="tabs[]"
value="diagnosis"
checked>
<span>🩺 Diagnosis</span>
</label>
<label class="tab-box">
<input type="checkbox"
name="tabs[]"
value="services"
checked>
<span>🏥 Services</span>
</label>
<label class="tab-box">
<input type="checkbox"
name="tabs[]"
value="diet_details"
checked>
<span>🥗 Diet Details</span>
</label>
<label class="tab-box">
<input type="checkbox"
name="tabs[]"
value="eye_details"
checked>
<span>👁 Eye Details</span>
</label>
<label class="tab-box">
<input type="checkbox"
name="tabs[]"
value="gynae_details"
checked>
<span>🤰 Gynae Details</span>
</label>
<label class="tab-box">
<input type="checkbox"
name="tabs[]"
value="embryology"
checked>
<span>🧬 Embryology</span>
</label>
<label class="tab-box">
<input type="checkbox"
name="tabs[]"
value="bariatric"
checked>
<span>⚖ Bariatric</span>
</label>
<label class="tab-box">
<input type="checkbox"
name="tabs[]"
value="nursing_notes"
checked>
<span>👩‍⚕️ Nursing Notes</span>
</label>
<div class="notes-card">

<label class="tab-box">
<input
type="checkbox"
name="tabs[]"
value="parameters"
checked
id="parametersTab">

<span>📈 Parameters</span>
</label>

<div id="parameters-sections">

<label>
<input
type="checkbox"
name="note_sections[]"
value="patient_vitals"
checked>

<span>📋 Patient Vitals</span>

</label>

</div>

</div>

</div>
<hr style="
margin:25px 0;
border:none;
border-top:1px solid #e5e7eb;
">
<div style="
display:flex;
justify-content:space-between;
align-items:center;
flex-wrap:wrap;
gap:15px;
">
<div>
<button
type="button"
id="selectAll"
class="btn btn-secondary">
<i class="fa fa-check-square"></i>
Select All
</button>
<button
type="button"
id="unselectAll"
class="btn btn-secondary">
<i class="fa fa-square"></i>
Unselect All
</button>
</div>
<div>
<button
type="submit"
id="saveBtn"
class="btn btn-primary"
disabled>
<i class="fa fa-save"></i>
Save Prescription Setup
</button>
</div>
</div>
</form>
</div>
<script>
function loadBranches(){
let hospital=document.getElementById('hospital_id').value;
document.getElementById('branch_id').innerHTML=
'<option value="">Loading...</option>';
document.getElementById('doctor_id').innerHTML=
'<option value="">Select Doctor</option>';
document.getElementById('saveBtn').disabled=true;
resetAllCheckboxes();
if(hospital==''){
document.getElementById('branch_id').innerHTML=
'<option value="">Select Branch</option>';
return;
}
fetch('load_prescription_setup.php?action=branches&hospital_id='+hospital)
.then(res=>res.text())
.then(function(data){
document.getElementById('branch_id').innerHTML=data;
});
}
function loadDoctors(){
let hospital=document.getElementById('hospital_id').value;
let branch=document.getElementById('branch_id').value;
document.getElementById('doctor_id').innerHTML=
'<option value="">Loading...</option>';
document.getElementById('saveBtn').disabled=true;
if(branch==''){
document.getElementById('doctor_id').innerHTML=
'<option value="">Select Doctor</option>';
return;
}
fetch(
'load_prescription_setup.php?action=doctor_list&hospital_id='
+hospital+
'&branch_id='
+branch
)
.then(res=>res.text())
.then(function(data){
document.getElementById('doctor_id').innerHTML=data;
});
}
function loadDoctorSetup(){
let doctor=document.getElementById('doctor_id').value;
document.getElementById('saveBtn').disabled = (doctor=="");
resetAllCheckboxes();
if(doctor==''){
return;
}
fetch(
'load_prescription_setup.php?action=load&doctor_id='
+doctor
)
.then(res=>res.json())
.then(function(data){
resetAllCheckboxes();
if(data.tabs){
    document.querySelectorAll('input[name="tabs[]"]').forEach(function(box){
        box.checked = false;
    });

    data.tabs.forEach(function(tab){
        let obj = document.querySelector(
            'input[name="tabs[]"][value="'+tab+'"]'
        );
        if(obj){
            obj.checked = true;
        }
    });
}
if(data.noteSections){
    document.querySelectorAll('input[name="note_sections[]"]').forEach(function(box){
        box.checked = false;
    });
    data.noteSections.forEach(function(section){
        let obj = document.querySelector(
            'input[name="note_sections[]"][value="'+section+'"]'
        );
        if(obj){
            obj.checked = true;
        }
    });
}
updateNotesSectionsState();
});
}
function resetAllCheckboxes(){
document
.querySelectorAll('input[type="checkbox"]')
.forEach(function(box){
    box.checked=true;
});
updateNotesSectionsState();
}
// Main checkbox (Notes) click handler
const notesTab=document.getElementById('notesTab');
const notesSections=document.getElementById('notes-sections');
const childCheckboxes = notesSections.querySelectorAll('input[type="checkbox"]');
function updateNotesSectionsState(){
    if(notesTab.checked){
        // Enable all child checkboxes
        childCheckboxes.forEach(function(child){
            child.disabled = false;
            child.parentElement.classList.remove('disabled');
        });
        notesSections.style.opacity = '1';
        notesSections.style.pointerEvents = 'auto';
    } else {
        // Disable and uncheck all child checkboxes
        childCheckboxes.forEach(function(child){
            child.checked = false;
            child.disabled = true;
            child.parentElement.classList.add('disabled');
        });
        notesSections.style.opacity = '0.5';
        notesSections.style.pointerEvents = 'none';
    }
    updateTabBoxStyle();
}
function updateTabBoxStyle(){
    const tabBoxes = document.querySelectorAll('.tab-box');
    tabBoxes.forEach(function(box){
        const checkbox = box.querySelector('input[type="checkbox"]');
        if(checkbox && checkbox.checked){
            box.classList.add('selected');
        } else {
            box.classList.remove('selected');
        }
    });
    // Update notes card style
    const notesCard = document.querySelector('.notes-card');
    if(notesTab.checked){
        notesCard.style.borderColor = '#1976d2';
    } else {
        notesCard.style.borderColor = '#e0e0e0';
    }
}
notesTab.addEventListener('change', updateNotesSectionsState);
// Also update when any child checkbox changes
childCheckboxes.forEach(function(child){
    child.addEventListener('change', function(){
        // If any child is unchecked, we could optionally uncheck the main notes tab
        // But for now, we'll just update the visual state
        updateTabBoxStyle();
    });
});
// Update other tab box styles when they change
document.querySelectorAll('.tab-box input[type="checkbox"]').forEach(function(box){
    if(box.id !== 'notesTab'){
        box.addEventListener('change', updateTabBoxStyle);
    }
});
// Initialize the state
updateNotesSectionsState();
updateTabBoxStyle();

document
.getElementById('hospital_id')
.addEventListener(
'change',
loadBranches
);
document
.getElementById('branch_id')
.addEventListener(
'change',
loadDoctors
);
document
.getElementById('doctor_id')
.addEventListener(
'change',
loadDoctorSetup
);
document
.getElementById('selectAll')
.addEventListener(
'click',
function(){
document
.querySelectorAll('input[type="checkbox"]')
.forEach(function(box){
    box.checked=true;
});
updateNotesSectionsState();
}
);
document
.getElementById('unselectAll')
.addEventListener(
'click',
function(){
document
.querySelectorAll('input[type="checkbox"]')
.forEach(function(box){
    box.checked=false;
});
updateNotesSectionsState();
}
);
</script>
</body>
</html>