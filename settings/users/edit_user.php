<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
/* LOGIN & PERMISSION */
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}
if (!checkPermission('users','update')) {
    die('Access Denied');
}
/* USER ID */
$user_id = (int)($_GET['id'] ?? 0);
if ($user_id <= 0) {
    die('Invalid User');
}
/* FETCH USER CORE */
$stmt = $conn->prepare("
    SELECT *
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) {
    die('User not found');
}
/* MASTER DATA */
$hospitals      = mysqli_query($conn,"SELECT id,hospital_name FROM hospital_master WHERE status=1 ORDER BY hospital_name");
$qualifications = mysqli_query($conn,"SELECT id,qualification_name FROM qualification_master WHERE status=1 ORDER BY qualification_name");
$empTypes       = mysqli_query($conn,"SELECT id,type_name FROM employee_type_master WHERE parent_id=0 AND status=1 ORDER BY type_name");
$roles          = mysqli_query($conn,"SELECT id,name FROM rights_types ORDER BY name");
$usersList      = mysqli_query($conn,"SELECT id,full_name FROM users WHERE status='Active' ORDER BY full_name");
$designations = mysqli_query($conn,"SELECT designation_code, designation_name FROM designation_master WHERE status=1 ORDER BY designation_name");
/* USER MAPPINGS */
function getIds($conn,$table,$col,$user_id){
    $ids=[];
    $q=mysqli_query($conn,"SELECT $col FROM $table WHERE user_id=$user_id");
    while($r=mysqli_fetch_assoc($q)){ $ids[]=$r[$col]; }
    return $ids;
}
$userHospitals      = getIds($conn,'user_hospitals','hospital_id',$user_id);
$userBranches       = getIds($conn,'user_branches','branch_id',$user_id);
$userDepartments    = getIds($conn,'user_departments','department_id',$user_id);
$userQualifications = getIds($conn,'user_qualifications','qualification_id',$user_id);
$userDesignations = getIds($conn,'user_designations','designation_code',$user_id);
$userSubTypes       = getIds($conn,'user_employee_sub_types','sub_type_id',$user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <style>
        .multi-select {
    position: relative;
}
.dropdown {
    position: absolute;
    background: #fff;
    border: 1px solid #ccc;
    max-height: 200px;
    overflow-y: auto;
    z-index: 9999;
    width: 100%;
}
.select-box {
    cursor: pointer;
}
    </style>
<meta charset="UTF-8">
<title>Edit User</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<body>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<div class="top-bar">  
<div class="breadcrumb">
    <a href="/dashboard/index.php">Home</a>
    <span>&gt;</span>
    <a href="/settings/settings.php">Settings</a>
    <span>&gt;</span>
    <a href="/settings/users/user_list.php">User Management</a>
    <span>&gt;</span>
</div>
<h2 style="margin:0 auto;">Edit User</h2>
</div>
<div class="user-wrapper">
<form method="post" action="save_user.php">
<input type="hidden" name="id" value="<?= $user_id ?>">
<div class="grid">
<!-- BASIC -->
<div><label>Employee ID</label><input name="employee_id" value="<?= htmlspecialchars($user['employee_id']) ?>" required></div>
<div><label>Full Name</label><input name="full_name" value="<?= htmlspecialchars($user['full_name']) ?>" required></div>
<div><label>Username</label><input name="username" value="<?= htmlspecialchars($user['username']) ?>" required></div>
<div><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required></div>
<div><label>Mobile No.</label><input type="text" name="mobile_no" value="<?= htmlspecialchars($user['mobile_no'] ?? '') ?>" maxlength="15" required> </div>
<!-- GENDER -->
<div>
    <label>Gender</label>
    <select name="gender" required>
        <option value="">Select Gender</option>
        <option value="Male" <?= $user['gender']=='Male'?'selected':'' ?>>Male</option>
        <option value="Female" <?= $user['gender']=='Female'?'selected':'' ?>>Female</option>
    </select>
</div>
<!-- DATE OF BIRTH -->
<div>
    <label>Date of Birth</label>
    <input
        type="date"
        name="dob"
        value="<?= htmlspecialchars($user['dob']) ?>"
        required
    >
</div>
<!-- MARITAL STATUS -->
<div>
    <label>Marital Status</label>
    <select name="marital_status" required>
        <option value="">Select Status</option>
        <option value="Married" <?= $user['marital_status']=='Married'?'selected':'' ?>>Married</option>
        <option value="Unmarried" <?= $user['marital_status']=='Unmarried'?'selected':'' ?>>Unmarried</option>
    </select>
</div>
<div><label>New Password</label><input type="password" name="password" placeholder="Leave blank to keep"></div>
<div><label>Confirm Password</label><input type="password" name="confirm_password"></div>
<div><label>Aadhar No</label><input name="aadhar_no" value="<?= htmlspecialchars($user['aadhar_no']) ?>"></div>
<!-- HOSPITAL -->
<div>
<label>Hospital</label>
<div class="multi-select">
 <div class="select-box" onclick="toggleDropdown('hospitalDropdown', event)">
<span><?= count($userHospitals) ?> selected</span>
</div>
<div class="dropdown" id="hospitalDropdown">
<?php while($h=mysqli_fetch_assoc($hospitals)){ ?>
<label>
<input type="checkbox" name="hospital_ids[]" value="<?= $h['id'] ?>"
<?= in_array($h['id'],$userHospitals)?'checked':'' ?>>
<?= htmlspecialchars($h['hospital_name']) ?>
</label>
<?php } ?>
</div>
</div>
</div>
<!-- BRANCH -->
<div>
<label>Branch</label>
<div class="multi-select">
<div class="select-box" onclick="toggleDropdown('branchDropdown', event)">
<span><?= count($userBranches) ?> selected</span>
</div>
<div class="dropdown" id="branchDropdown">
<?php
$bq=mysqli_query($conn,"SELECT id,branch_name FROM branch_master WHERE status=1");
while($b=mysqli_fetch_assoc($bq)){
?>
<label>
<input type="checkbox" name="branch_ids[]" value="<?= $b['id'] ?>"
<?= in_array($b['id'],$userBranches)?'checked':'' ?>>
<?= htmlspecialchars($b['branch_name']) ?>
</label>
<?php } ?>
</div>
</div>
</div>
<!-- DEPARTMENT -->
<div>
<label>Department</label>
<div class="multi-select">
<div class="select-box" onclick="toggleDropdown('departmentDropdown', event)">
<span><?= count($userDepartments) ?> selected</span>
</div>
<div class="dropdown" id="departmentDropdown">
<?php
$dq=mysqli_query($conn,"SELECT id,department_name FROM department_master WHERE status=1");
while($d=mysqli_fetch_assoc($dq)){
?>
<label>
<input type="checkbox" name="department_ids[]" value="<?= $d['id'] ?>"
<?= in_array($d['id'],$userDepartments)?'checked':'' ?>>
<?= htmlspecialchars($d['department_name']) ?>
</label>
<?php } ?>
</div>
</div>
</div>
<!-- QUALIFICATION -->
<div>
<label>Qualification</label>
<div class="multi-select">
<div class="select-box" onclick="toggleDropdown('qualificationDropdown', event)">
<span><?= count($userQualifications) ?> selected</span>
</div>
<div class="dropdown" id="qualificationDropdown">
<?php while($q=mysqli_fetch_assoc($qualifications)){ ?>
<label>
<input type="checkbox" name="qualification_ids[]" value="<?= $q['id'] ?>"
<?= in_array($q['id'],$userQualifications)?'checked':'' ?>>
<?= htmlspecialchars($q['qualification_name']) ?>
</label>
<?php } ?>
</div>
</div>
</div>
<!-- DESIGNATION -->
<div>
<label>Designation</label>
<div class="multi-select">
<div class="select-box" onclick="toggleDropdown('designationDropdown', event)">
<span><?= count($userDesignations) ?> selected</span>
</div>
<div class="dropdown" id="designationDropdown">
<?php while($d = mysqli_fetch_assoc($designations)){ ?>
<label>
<input type="checkbox"
       name="designation_codes[]"
       value="<?= $d['designation_code'] ?>"
       <?= in_array($d['designation_code'], $userDesignations) ? 'checked' : '' ?>>
<?= htmlspecialchars($d['designation_name']) ?>
</label>
<?php } ?>
</div>
</div>
</div>
<!-- EMPLOYEE TYPE -->
<div>
<label>Employee Type</label>
<select name="employee_type_id">
<option value="">Select</option>
<?php while($e=mysqli_fetch_assoc($empTypes)){ ?>
<option value="<?= $e['id'] ?>" <?= $e['id']==$user['employee_type_id']?'selected':'' ?>>
<?= htmlspecialchars($e['type_name']) ?>
</option>
<?php } ?>
</select>
</div>
<!-- SUB TYPE -->
<div>
<label>Employee Sub Type</label>
<div class="multi-select">
<div class="select-box" onclick="toggleDropdown('subTypeDropdown', event)">
<span><?= count($userSubTypes) ?> selected</span>
</div>
<div class="dropdown" id="subTypeDropdown">
<?php
$stq=mysqli_query($conn,"SELECT id,type_name FROM employee_type_master WHERE parent_id=".$user['employee_type_id']);
while($st=mysqli_fetch_assoc($stq)){
?>
<label>
<input type="checkbox" name="employee_sub_type_ids[]" value="<?= $st['id'] ?>"
<?= in_array($st['id'],$userSubTypes)?'checked':'' ?>>
<?= htmlspecialchars($st['type_name']) ?>
</label>
<?php } ?>
</div>
</div>
</div>
<!-- REPORTING OFFICER -->
<div>
<label>Reporting Officer</label>
<select name="reporting_officer_id">
<option value="">Select</option>
<?php while($u=mysqli_fetch_assoc($usersList)){ ?>
<option value="<?= $u['id'] ?>" <?= $u['id']==$user['reporting_officer_id']?'selected':'' ?>>
<?= htmlspecialchars($u['full_name']) ?>
</option>
<?php } ?>
</select>
</div>
<!-- RIGHTS -->
<div>
<label>Rights Type</label>
<select name="rights_type_id" required>
<?php while($r=mysqli_fetch_assoc($roles)){ ?>
<option value="<?= $r['id'] ?>" <?= $r['id']==$user['rights_type_id']?'selected':'' ?>>
<?= htmlspecialchars($r['name']) ?>
</option>
<?php } ?>
</select>
</div>
<div>
<label>Status</label>
<select name="status">
<option value="Active" <?= $user['status']=='Active'?'selected':'' ?>>Active</option>
<option value="Inactive" <?= $user['status']=='Inactive'?'selected':'' ?>>Inactive</option>
</select>
</div>
</div>
<br>
<button type="submit">Update User</button>
</form>
</div>
<script>
function toggleDropdown(id, event){
    event.stopPropagation(); // 🔥 critical
    const d = document.getElementById(id);
    // close all others
    document.querySelectorAll('.dropdown').forEach(x => {
        if (x.id !== id) x.style.display = 'none';
    });
    d.style.display = (d.style.display === 'block') ? 'none' : 'block';
}
// prevent dropdown click from closing
document.addEventListener('click', function () {
    document.querySelectorAll('.dropdown').forEach(d => d.style.display = 'none');
});
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.dropdown').forEach(d => {
        d.addEventListener('click', e => e.stopPropagation());
    });
});
</script>
</body>
</html>