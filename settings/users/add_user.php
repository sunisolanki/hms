<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}
if (!checkPermission('users','create')) {
    die('Access Denied');
}
/* LOAD MASTERS */
$hospitals      = mysqli_query($conn,"SELECT id,hospital_name FROM hospital_master WHERE status=1 ORDER BY hospital_name");
$qualifications = mysqli_query($conn,"SELECT id,qualification_name FROM qualification_master WHERE status=1 ORDER BY qualification_name");
$empTypes       = mysqli_query($conn,"SELECT id,type_name FROM employee_type_master WHERE parent_id=0 AND status=1 ORDER BY type_name");
$users          = mysqli_query($conn,"SELECT id,full_name FROM users WHERE status='Active' ORDER BY full_name");
$roles          = mysqli_query($conn,"SELECT id,name FROM rights_types ORDER BY name");
$designations = mysqli_query($conn,"SELECT designation_code, designation_name FROM designation_master WHERE status=1 ORDER BY designation_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add User</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<style>
.error-message{
    color:red;
    font-size:12px;
    margin-top:4px;
}
.error-border{
    border:1px solid red !important;
}
</style>
<script>
/* COMMON */
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

/* HOSPITAL → BRANCH */
function updateHospitals(){
    let ids=[];
    document.querySelectorAll('#hospitalDropdown input:checked')
        .forEach(cb=>ids.push(cb.value));

    document.getElementById('hospitalText').innerText =
        ids.length ? ids.length+' selected' : 'Select Hospital(s)';

    fetch('load_user_data.php?type=branch&ids='+ids.join(','))
        .then(r=>r.text())
        .then(html=>{
            document.getElementById('branchOptions').innerHTML=html;
            document.getElementById('branchText').innerText='Select Branch(s)';
            document.getElementById('departmentOptions').innerHTML='';
            document.getElementById('departmentText').innerText='Select Department(s)';
        });
}
/* BRANCH → DEPARTMENT */
function updateBranches(){
    let ids=[];
    document.querySelectorAll('#branchOptions input:checked')
        .forEach(cb=>ids.push(cb.value));
    document.getElementById('branchText').innerText =
        ids.length ? ids.length+' selected' : 'Select Branch(s)';
    fetch('load_user_data.php?type=department&ids='+ids.join(','))
        .then(r=>r.text())
        .then(html=>{
            document.getElementById('departmentOptions').innerHTML=html;
            document.getElementById('departmentText').innerText='Select Department(s)';
        });
}
/* DEPARTMENT */
function updateDepartments(){
    let c=document.querySelectorAll('#departmentOptions input:checked').length;
    document.getElementById('departmentText').innerText =
        c ? c+' selected' : 'Select Department(s)';
}
/* QUALIFICATION */
function updateQualifications(){
    let c=document.querySelectorAll('#qualificationDropdown input:checked').length;
    document.getElementById('qualificationText').innerText =
        c ? c+' selected' : 'Select Qualification(s)';
}
/* EMPLOYEE TYPE → SUB TYPE */
function loadSubTypes(){
    let id=document.getElementById('employee_type_id').value;
    document.getElementById('employee_sub_type_options').innerHTML='';
    document.getElementById('subTypeText').innerText='Select Sub Type(s)';
    if(!id) return;

    fetch('load_user_data.php?type=subtype&id='+id)
        .then(r=>r.text())
        .then(html=>{
            document.getElementById('employee_sub_type_options').innerHTML=html;
        });
}
function updateSubTypes(){
    let c=document.querySelectorAll('#employee_sub_type_options input:checked').length;
    document.getElementById('subTypeText').innerText =
        c ? c+' selected' : 'Select Sub Type(s)';
}
</script>
<script>
function updateDesignations(){
    let count = document.querySelectorAll('#designationDropdown input:checked').length;
    document.getElementById('designationText').innerText =
        count ? count + ' selected' : 'Select Designation(s)';
}
</script>
</head>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<body>
<div class="top-bar">  
<div class="breadcrumb">
    <a href="/dashboard/index.php">Home</a>
    <span>&gt;</span>
    <a href="/settings/settings.php">Settings</a>
    <span>&gt;</span>
    <a href="/settings/users/user_list.php">User Management</a>
    <span>&gt;</span>
    <a>Add User</a>
</div>
<h2 style="margin:0 auto;">Add New User</h2>
</div>
<div class="user-wrapper">
<form method="post" action="save_user.php" onsubmit="return validateForm()">
<div class="grid">
    <div><label>Employee ID*</label><input name="employee_id" placeholder="Employee ID" required></div>
    <div><label>Full Name*</label><input name="full_name" placeholder="Full Name" required></div>
    <div>
    <label>Username*</label>
    <input 
        type="text"
        name="username"
        placeholder="Username"
        pattern="^\S+$"
        title="Username should not contain spaces"
        oninput="this.value = this.value.replace(/\s/g, '')"
        required
    >
</div>
    <div><label>Email*</label><input 
    type="email"
    name="email"
    placeholder="Email"
    pattern="^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[A-Za-z]{2,}$"
    title="Enter a valid email address"
    required
></div>
    <div class="form-group"><label>Mobile No.*</label><input 
    type="text"
    name="mobile_no"
    class="form-control"
    maxlength="15"
    pattern="^\+?[0-9]+$"
    placeholder="Enter Mobile Number"
    title="Only numbers and + are allowed"
    oninput="this.value = this.value.replace(/[^0-9+]/g, '').replace(/(?!^)\+/g, '')"
    required
></div>
    <!-- GENDER -->
<div>
    <label>Gender*</label>
    <select name="gender" required>
        <option value="">Select Gender</option>
        <option value="Male">Male</option>
        <option value="Female">Female</option>
    </select>
</div>
<!-- DATE OF BIRTH -->
<div>
    <label>Date of Birth*</label>
    <input type="date" name="dob" required>
</div>
<!-- MARITAL STATUS -->
<div>
    <label>Marital Status*</label>
    <select name="marital_status" required>
        <option value="">Select Status</option>
        <option value="Married">Married</option>
        <option value="Unmarried">Unmarried</option>
    </select>
</div>
    <div><label>Password*</label><input type="password" name="password" placeholder="Password" autocomplete="new-password" required></div>
    <div><label>Confirm Password*</label><input type="password" name="confirm_password" placeholder="Confirm Password" autocomplete="new-password" required>
    <div id="passwordError" class="error-message"></div></div>
    <div><label>Aadhar No.*</label><input type="text" name="aadhar_no" placeholder="Aadhaar Number" maxlength="12" pattern="[0-9]{12}" title="Enter 12 digit Aadhaar number"oninput="this.value = this.value.replace(/[^0-9]/g, '')" required></div>
<!-- HOSPITAL -->
<div><label>Hospital*</label><div class="multi-select"><div id="hospitalError" class="error-message"></div>
<div class="select-box" onclick="toggleDropdown('hospitalDropdown')"><span id="hospitalText">Select Hospital(s)</span></div>
<div class="dropdown" id="hospitalDropdown"><input type="text" placeholder="Search..." onkeyup="filterOptions(this,'hospitalDropdown')"><?php while($h=mysqli_fetch_assoc($hospitals)){ ?>
<label><input type="checkbox" name="hospital_ids[]" value="<?= $h['id'] ?>" onchange="updateHospitals()"><?= htmlspecialchars($h['hospital_name']) ?></label><?php } ?></div>
</div>
</div>
<!-- BRANCH -->
<div>
<label>Branch*</label>
<div class="multi-select"><div id="branchError" class="error-message"></div>
<div class="select-box" onclick="toggleDropdown('branchDropdown')">
<span id="branchText">Select Branch(s)</span>
</div>
<div class="dropdown" id="branchDropdown">
<input type="text" placeholder="Search..." onkeyup="filterOptions(this,'branchDropdown')">
<div id="branchOptions"></div>
</div>
</div>
</div>
<!-- DEPARTMENT -->
<div>
<label>Department*</label>
<div class="multi-select"><div id="departmentError" class="error-message"></div>
<div class="select-box" onclick="toggleDropdown('departmentDropdown')">
<span id="departmentText">Select Department(s)</span>
</div>
<div class="dropdown" id="departmentDropdown">
<input type="text" placeholder="Search..." onkeyup="filterOptions(this,'departmentDropdown')">
<div id="departmentOptions"></div>
</div>
</div>
</div>
<!-- DESIGNATION -->
<div>
<label>Designation*</label>
<div class="multi-select"><div id="designationError" class="error-message"></div>
    <div class="select-box" onclick="toggleDropdown('designationDropdown')">
        <span id="designationText">Select Designation(s)</span>
    </div>
    <div class="dropdown" id="designationDropdown">
        <input type="text" placeholder="Search..."
               onkeyup="filterOptions(this,'designationDropdown')">
        <?php while($d = mysqli_fetch_assoc($designations)){ ?>
            <label>
                <input type="checkbox"
                       name="designation_codes[]"
                       value="<?= $d['designation_code'] ?>"
                       onchange="updateDesignations()">
                <?= htmlspecialchars($d['designation_name']) ?>
            </label>
        <?php } ?>
    </div>
</div>
</div>
<!-- QUALIFICATION -->
<div>
<label>Qualification</label>
<div class="multi-select"><div id="qualificationError" class="error-message"></div>
<div class="select-box" onclick="toggleDropdown('qualificationDropdown')">
<span id="qualificationText">Select Qualification(s)</span>
</div>
<div class="dropdown" id="qualificationDropdown">
<input type="text" placeholder="Search..." onkeyup="filterOptions(this,'qualificationDropdown')">
<?php while($q=mysqli_fetch_assoc($qualifications)){ ?>
<label>
<input type="checkbox" name="qualification_ids[]" value="<?= $q['id'] ?>" onchange="updateQualifications()">
<?= htmlspecialchars($q['qualification_name']) ?>
</label>
<?php } ?>
</div>
</div>
</div>
<!-- EMPLOYEE TYPE -->
<div>
<label>Employee Type*</label>
<select name="employee_type_id" id="employee_type_id" onchange="loadSubTypes()" required>
<option value="">Select Type</option>
<?php while($e=mysqli_fetch_assoc($empTypes)){ ?>
<option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['type_name']) ?></option>
<?php } ?>
</select>
</div>
<!-- EMPLOYEE SUB TYPE -->
<div>
<label>Employee Sub Type*</label>
<div class="multi-select"><div id="subTypeError" class="error-message"></div>
<div class="select-box" onclick="toggleDropdown('subTypeDropdown')" >
<span id="subTypeText">Select Sub Type(s)</span>
</div>
<div class="dropdown" id="subTypeDropdown">
<input type="text" placeholder="Search..." onkeyup="filterOptions(this,'subTypeDropdown')">
<div id="employee_sub_type_options"></div>
</div>
</div></div>
<!-- REPORTING OFFICER -->
<div>
<label>Reporting Officer*</label>
<select name="reporting_officer_id" required>
<option value="">Select Officer</option>
<?php while($u=mysqli_fetch_assoc($users)){ ?>
<option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option>
<?php } ?>
</select>
</div>
<!-- RIGHTS -->
<div>
<label>Rights Type*</label>
<select name="rights_type_id" required>
<option value="">Select Rights</option>
<?php while($r=mysqli_fetch_assoc($roles)){ ?>
<option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
<?php } ?>
</select>
</div>
<div><label>Status*</label><select name="status">
<option value="Active">Active</option>
<option value="Inactive">Inactive</option>
</select></div>
</div>
<br>
<button type="submit">Create User</button>
</form>
</div>
<script>
function validateForm() {
    // Clear old errors
    document.querySelectorAll('.error-message').forEach(el => el.innerText='');
    document.querySelectorAll('.error-border').forEach(el => el.classList.remove('error-border'));
    // Hospital
    if (document.querySelectorAll('#hospitalDropdown input:checked').length === 0) {
        document.getElementById('hospitalError').innerText = 'Please select at least one Hospital';
        document.querySelector('#hospitalDropdown').classList.add('error-border');
        return false;
    }
    // Branch
    if (document.querySelectorAll('#branchOptions input:checked').length === 0) {
        document.getElementById('branchError').innerText = 'Please select at least one Branch';
        document.querySelector('#branchDropdown').classList.add('error-border');
        return false;
    }
    // Department
    if (document.querySelectorAll('#departmentOptions input:checked').length === 0) {
        document.getElementById('departmentError').innerText = 'Please select at least one Department';
        document.querySelector('#departmentDropdown').classList.add('error-border');
        return false;
    }
    // Designation
    if (document.querySelectorAll('#designationDropdown input:checked').length === 0) {
        document.getElementById('designationError').innerText = 'Please select at least one Designation';
        document.querySelector('#designationDropdown').classList.add('error-border');
        return false;
    }
    // Qualification
    if (document.querySelectorAll('#qualificationDropdown input:checked').length === 0) {
        document.getElementById('qualificationError').innerText = 'Please select at least one Qualification';
        document.querySelector('#qualificationDropdown').classList.add('error-border');
        return false;
    }
    // Employee Sub Type
    if (document.querySelectorAll('#employee_sub_type_options input:checked').length === 0) {
        document.getElementById('subTypeError').innerText = 'Please select at least one Employee Sub Type';
        document.querySelector('#subTypeDropdown').classList.add('error-border');
        return false;
    }
    // Password Match
    let password = document.querySelector('input[name="password"]').value;
    let confirmPassword = document.querySelector('input[name="confirm_password"]').value;
    if (password !== confirmPassword) {
        document.getElementById('passwordError').innerText = 'Passwords do not match';
        document.querySelector('input[name="confirm_password"]').classList.add('error-border');
        return false;
    }
    return true;
}
</script>
</body>
</html>