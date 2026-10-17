<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
/* LOGIN */
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}
/* MODE */
$isEdit = !empty($_POST['id']);
/* PERMISSION */
if ($isEdit && !checkPermission('users','update')) {
    die('Access Denied');
}
if (!$isEdit && !checkPermission('users','create')) {
    die('Access Denied');
}
/* REQUIRED FIELDS */
$required = [
    'employee_id',
    'full_name',
    'username',
    'email',
    'rights_type_id',
    'status',
    'gender',
    'dob',
    'marital_status'
];
if (!$isEdit) {
    $required[] = 'password';
    $required[] = 'confirm_password';
}
foreach ($required as $field) {
    if (empty($_POST[$field])) {
        die("Missing field: {$field}");
    }
}
/* PASSWORD CHECK */
if (!$isEdit && $_POST['password'] !== $_POST['confirm_password']) {
    die('Passwords do not match');
}
/* UNIQUE CHECK */
if ($isEdit) {
    $check = $conn->prepare("
        SELECT id FROM users
        WHERE (username = ? OR email = ?)
        AND id != ?
        LIMIT 1
    ");
    $check->bind_param("ssi", $_POST['username'], $_POST['email'], $_POST['id']);
} else {
    $check = $conn->prepare("
        SELECT id FROM users
        WHERE username = ? OR email = ?
        LIMIT 1
    ");
    $check->bind_param("ss", $_POST['username'], $_POST['email']);
}
$check->execute();
$check->store_result();
if ($check->num_rows > 0) {
    die('Username or Email already exists');
}
$check->close();
/* OPTIONAL */
$aadhar_no         = $_POST['aadhar_no'] ?? null;
$employee_type_id  = $_POST['employee_type_id'] ?: null;
$reporting_officer = $_POST['reporting_officer_id'] ?: null;
$mobile_no = $_POST['mobile_no'] ?? null;
$gender         = $_POST['gender'];
$dob            = $_POST['dob'];
$marital_status = $_POST['marital_status'];
/* =========================
   ADD USER
========================= */
if (!$isEdit) {
    $plainPassword = $_POST['password'];   // original password (for email/SMS only)
    $password = password_hash($plainPassword, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("
        INSERT INTO users (
    employee_id,
    full_name,
    username,
    email,
    mobile_no,
    gender,
    dob,
    marital_status,
    password,
    aadhar_no,
    employee_type_id,
    reporting_officer_id,
    rights_type_id,
    status
)
 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->bind_param(
     "ssssssssssiiss",
    $_POST['employee_id'],
    $_POST['full_name'],
    $_POST['username'],
    $_POST['email'],
    $mobile_no,
    $gender,
    $dob,
    $marital_status,
    $password,
    $aadhar_no,
    $employee_type_id,
    $reporting_officer,
    $_POST['rights_type_id'],
    $_POST['status']
);
    $stmt->execute();
    $user_id = $stmt->insert_id;
    $stmt->close();
    addAuditLog(
    'User Management',
    'Create',
    'User Created : '.$_POST['full_name'],
    $user_id,
    '',
    '',
    'SUCCESS'
);
    require_once $_SERVER['DOCUMENT_ROOT'].'/communication/send_mail.php';
$username = $_POST['username'];
$full_name = $_POST['full_name'];
$emailPassword = $plainPassword;
$tpl = $_SERVER['DOCUMENT_ROOT']
    . '/communication/email_templates/user_credentials.php';
if (!empty($_POST['email']) && file_exists($tpl)) {
    $emailData = require $tpl;
    sendSMTPMail(
    $_POST['email'],
    $emailData['subject'],
    $emailData['message']
);
}
require_once $_SERVER['DOCUMENT_ROOT'].'/communication/sms/send_sms.php';
if (!empty($mobile_no)) {
    $smsMessage = require $_SERVER['DOCUMENT_ROOT']
        . '/communication/sms/sms_templates/user_credentials.php';
    sendSMS($mobile_no, $smsMessage);
}
require_once $_SERVER['DOCUMENT_ROOT'].'/communication/whatsapp/send_whatsapp.php';
if (!empty($mobile_no)) {
    $waMessage = require $_SERVER['DOCUMENT_ROOT']
        . '/communication/whatsapp/whatsapp_templates/user_credentials.php';
    sendWhatsApp($mobile_no, $waMessage);
}
}
/* =========================
   UPDATE USER
========================= */
else {
    $oldUser = [];
$changedFields = [];

$stmtOld = $conn->prepare("
    SELECT *
    FROM users
    WHERE id = ?
");
$stmtOld->bind_param("i", $_POST['id']);
$stmtOld->execute();
$resultOld = $stmtOld->get_result();
$oldUser = $resultOld->fetch_assoc();
$resultOld->free();
$stmtOld->close();
$fields = [
    'employee_id'         => 'Employee ID',
    'full_name'           => 'Full Name',
    'username'            => 'Username',
    'email'               => 'Email',
    'mobile_no'           => 'Mobile No',
    'gender'              => 'Gender',
    'dob'                 => 'Date of Birth',
    'marital_status'      => 'Marital Status',
    'aadhar_no'           => 'Aadhar No',
    'employee_type_id'    => 'Employee Type',
    'reporting_officer_id'=> 'Reporting Officer',
    'rights_type_id'      => 'Rights Type',
    'status'              => 'Status'
];
foreach ($fields as $dbField => $label) {
    $oldValue = (string)($oldUser[$dbField] ?? '');
    $newValue = (string)($_POST[$dbField] ?? '');
    if ($oldValue !== $newValue) {
        $changedFields[$label] = [
            'old' => $oldValue,
            'new' => $newValue
        ];
    }
}
    $user_id = (int)$_POST['id'];
    if (!empty($_POST['password'])) {
        if ($_POST['password'] !== $_POST['confirm_password']) {
            die('Passwords do not match');
        }
        $plainPassword = $_POST['password'];   // TEMPORARY – DO NOT STORE
        $password = password_hash($plainPassword, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("
            UPDATE users SET
                employee_id=?,
                full_name=?,
                username=?,
                email=?,
                mobile_no=?,
                gender=?,
                dob=?,
                marital_status=?,
                password=?,
                aadhar_no=?,
                employee_type_id=?,
                reporting_officer_id=?,
                rights_type_id=?,
                status=?
            WHERE id=?
        ");
        $stmt->bind_param(
    "ssssssssssiissi",
    $_POST['employee_id'],
    $_POST['full_name'],
    $_POST['username'],
    $_POST['email'],
    $mobile_no,
    $gender,
    $dob,
    $marital_status,
    $password,
    $aadhar_no,
    $employee_type_id,
    $reporting_officer,
    $_POST['rights_type_id'],
    $_POST['status'],
    $user_id
);
    } else {

        $stmt = $conn->prepare("
            UPDATE users SET
                employee_id=?,
                full_name=?,
                username=?,
                email=?,
                mobile_no=?,
                gender=?,
                dob=?,
                marital_status=?,
                aadhar_no=?,
                employee_type_id=?,
                reporting_officer_id=?,
                rights_type_id=?,
                status=?
            WHERE id=?
        ");
        $stmt->bind_param(
            "sssssssssiiisi",
            $_POST['employee_id'],
            $_POST['full_name'],
            $_POST['username'],
            $_POST['email'],
            $mobile_no,
            $gender,
            $dob,
            $marital_status,
            $aadhar_no,
            $employee_type_id,
            $reporting_officer,
            $_POST['rights_type_id'],
            $_POST['status'],
            $user_id
        );
    }
    $stmt->execute();
    $stmt->close();
    
/* OLD HOSPITALS */
$oldHospitals = [];
$q = mysqli_query($conn,"
    SELECT hospital_id
    FROM user_hospitals
    WHERE user_id = $user_id
");
while($r = mysqli_fetch_assoc($q)){
    $oldHospitals[] = $r['hospital_id'];
}
/* OLD BRANCHES */
$oldBranches = [];
$q = mysqli_query($conn,"
    SELECT branch_id
    FROM user_branches
    WHERE user_id = $user_id
");
while($r = mysqli_fetch_assoc($q)){
    $oldBranches[] = $r['branch_id'];
}
/* OLD DEPARTMENTS */
$oldDepartments = [];
$q = mysqli_query($conn,"
    SELECT department_id
    FROM user_departments
    WHERE user_id = $user_id
");
while($r = mysqli_fetch_assoc($q)){
    $oldDepartments[] = $r['department_id'];
}
/* OLD QUALIFICATIONS */
$oldQualifications = [];
$q = mysqli_query($conn,"
    SELECT qualification_id
    FROM user_qualifications
    WHERE user_id = $user_id
");
while($r = mysqli_fetch_assoc($q)){
    $oldQualifications[] = $r['qualification_id'];
}
/* OLD DESIGNATIONS */
$oldDesignations = [];
$q = mysqli_query($conn,"
    SELECT designation_code
    FROM user_designations
    WHERE user_id = $user_id
");
while($r = mysqli_fetch_assoc($q)){
    $oldDesignations[] = $r['designation_code'];
}
/* OLD EMPLOYEE SUB TYPES */
$oldSubTypes = [];

$q = mysqli_query($conn,"
    SELECT sub_type_id
    FROM user_employee_sub_types
    WHERE user_id = $user_id
");

while($r = mysqli_fetch_assoc($q)){
    $oldSubTypes[] = $r['sub_type_id'];
}
    /* CLEAR OLD MAPPINGS */
    $conn->query("DELETE FROM user_hospitals WHERE user_id=$user_id");
    $conn->query("DELETE FROM user_branches WHERE user_id=$user_id");
    $conn->query("DELETE FROM user_departments WHERE user_id=$user_id");
    $conn->query("DELETE FROM user_qualifications WHERE user_id=$user_id");
    $conn->query("DELETE FROM user_employee_sub_types WHERE user_id=$user_id");
    $conn->query("DELETE FROM user_designations WHERE user_id=$user_id");
}
/* =========================
   SAVE MULTI SELECT MAPPINGS
========================= */
$newHospitals = $_POST['hospital_ids'] ?? [];
$newBranches = $_POST['branch_ids'] ?? [];
$newDepartments = $_POST['department_ids'] ?? [];
$newQualifications = $_POST['qualification_ids'] ?? [];
$newDesignations = $_POST['designation_codes'] ?? [];
$newSubTypes = $_POST['employee_sub_type_ids'] ?? [];
/* HOSPITALS */
if (!empty($_POST['hospital_ids'])) {
    $q = $conn->prepare("INSERT INTO user_hospitals (user_id, hospital_id) VALUES (?,?)");
    foreach ($_POST['hospital_ids'] as $hid) {
        $q->bind_param("ii", $user_id, $hid);
        $q->execute();
    }
    $q->close();
}
/* BRANCHES */
if (!empty($_POST['branch_ids'])) {
    $q = $conn->prepare("INSERT INTO user_branches (user_id, branch_id) VALUES (?,?)");
    foreach ($_POST['branch_ids'] as $bid) {
        $q->bind_param("ii", $user_id, $bid);
        $q->execute();
    }
    $q->close();
}
/* DEPARTMENTS */
if (!empty($_POST['department_ids'])) {
    $q = $conn->prepare("INSERT INTO user_departments (user_id, department_id) VALUES (?,?)");
    foreach ($_POST['department_ids'] as $did) {
        $q->bind_param("ii", $user_id, $did);
        $q->execute();
    }
    $q->close();
}
/* QUALIFICATIONS */
if (!empty($_POST['qualification_ids'])) {
    $q = $conn->prepare("INSERT INTO user_qualifications (user_id, qualification_id) VALUES (?,?)");
    foreach ($_POST['qualification_ids'] as $qid) {
        $q->bind_param("ii", $user_id, $qid);
        $q->execute();
    }
    $q->close();
}
/* DESIGNATIONS */
if (!empty($_POST['designation_codes'])) {
    $q = $conn->prepare(
        "INSERT INTO user_designations (user_id, designation_code) VALUES (?, ?)"
    );
    foreach ($_POST['designation_codes'] as $code) {
        $q->bind_param("is", $user_id, $code);
        $q->execute();
    }
    $q->close();
}
/* SUB TYPES */
if (!empty($_POST['employee_sub_type_ids'])) {
    $q = $conn->prepare("INSERT INTO user_employee_sub_types (user_id, sub_type_id) VALUES (?,?)");
    foreach ($_POST['employee_sub_type_ids'] as $sid) {
        $q->bind_param("ii", $user_id, $sid);
        $q->execute();
    }
    $q->close();
}
$oldValues = [];
$newValues = [];

if (!empty($changedFields)) {

    foreach ($changedFields as $field => $value) {
        $old = $value['old'];
        $new = $value['new'];
        // Rights Type
        if ($field == 'Rights Type') {
            $q = mysqli_query($conn,"SELECT name FROM rights_types WHERE id='".(int)$old."'");
            $r = mysqli_fetch_assoc($q);
            $old = $r['name'] ?? '';
            $q = mysqli_query($conn,"SELECT name FROM rights_types WHERE id='".(int)$new."'");
            $r = mysqli_fetch_assoc($q);
            $new = $r['name'] ?? '';
        }
        // Reporting Officer
        if ($field == 'Reporting Officer') {
            $q = mysqli_query($conn,"SELECT full_name FROM users WHERE id='".(int)$old."'");
            $r = mysqli_fetch_assoc($q);
            $old = $r['full_name'] ?? '';
            $q = mysqli_query($conn,"SELECT full_name FROM users WHERE id='".(int)$new."'");
            $r = mysqli_fetch_assoc($q);
            $new = $r['full_name'] ?? '';
        }
        // Employee Type
        if ($field == 'Employee Type') {
            $q = mysqli_query($conn,"SELECT type_name FROM employee_type_master WHERE id='".(int)$old."'");
            $r = mysqli_fetch_assoc($q);
            $old = $r['type_name'] ?? '';
            $q = mysqli_query($conn,"SELECT type_name FROM employee_type_master WHERE id='".(int)$new."'");
            $r = mysqli_fetch_assoc($q);
            $new = $r['type_name'] ?? '';
        }
        $oldValues[$field] = $old;
        $newValues[$field] = $new;
    }
}
/* HOSPITAL CHANGES */
sort($oldHospitals);
sort($newHospitals);
if ($oldHospitals != $newHospitals) {
    $oldHospitalNames = [];
    foreach ($oldHospitals as $id) {
        $q = mysqli_query($conn,"SELECT hospital_name FROM hospital_master WHERE id=".(int)$id);
        $r = mysqli_fetch_assoc($q);
        if ($r) {
            $oldHospitalNames[] = $r['hospital_name'];
        }
    }
    $newHospitalNames = [];
    foreach ($newHospitals as $id) {
        $q = mysqli_query($conn,"SELECT hospital_name FROM hospital_master WHERE id=".(int)$id);
        $r = mysqli_fetch_assoc($q);
        if ($r) {
            $newHospitalNames[] = $r['hospital_name'];
        }
    }
    $oldValues['Hospital'] = implode(', ', $oldHospitalNames);
    $newValues['Hospital'] = implode(', ', $newHospitalNames);
}
/* BRANCH CHANGES */
sort($oldBranches);
sort($newBranches);
if ($oldBranches != $newBranches) {
    $oldBranchNames = [];
    foreach ($oldBranches as $id) {
        $q = mysqli_query($conn,"SELECT branch_name FROM branch_master WHERE id=".(int)$id);
        $r = mysqli_fetch_assoc($q);
        if ($r) {
            $oldBranchNames[] = $r['branch_name'];
        }
    }
    $newBranchNames = [];
    foreach ($newBranches as $id) {
        $q = mysqli_query($conn,"SELECT branch_name FROM branch_master WHERE id=".(int)$id);
        $r = mysqli_fetch_assoc($q);
        if ($r) {
            $newBranchNames[] = $r['branch_name'];
        }
    }
    $oldValues['Branch'] = implode(', ', $oldBranchNames);
    $newValues['Branch'] = implode(', ', $newBranchNames);
}
/* DEPARTMENT CHANGES */
sort($oldDepartments);
sort($newDepartments);
if ($oldDepartments != $newDepartments) {
    $oldDepartmentNames = [];
    foreach ($oldDepartments as $id) {
        $q = mysqli_query($conn,"SELECT department_name FROM department_master WHERE id=".(int)$id);
        $r = mysqli_fetch_assoc($q);
        if ($r) {
            $oldDepartmentNames[] = $r['department_name'];
        }
    }
    $newDepartmentNames = [];
    foreach ($newDepartments as $id) {
        $q = mysqli_query($conn,"SELECT department_name FROM department_master WHERE id=".(int)$id);
        $r = mysqli_fetch_assoc($q);
        if ($r) {
            $newDepartmentNames[] = $r['department_name'];
        }
    }
    $oldValues['Department'] = implode(', ', $oldDepartmentNames);
    $newValues['Department'] = implode(', ', $newDepartmentNames);
}
/* QUALIFICATION CHANGES */
sort($oldQualifications);
sort($newQualifications);
if ($oldQualifications != $newQualifications) {
    $oldQualificationNames = [];
    foreach ($oldQualifications as $id) {
        $q = mysqli_query($conn,"SELECT qualification_name FROM qualification_master WHERE id=".(int)$id);
        $r = mysqli_fetch_assoc($q);
        if ($r) {
            $oldQualificationNames[] = $r['qualification_name'];
        }
    }
    $newQualificationNames = [];
    foreach ($newQualifications as $id) {
        $q = mysqli_query($conn,"SELECT qualification_name FROM qualification_master WHERE id=".(int)$id);
        $r = mysqli_fetch_assoc($q);
        if ($r) {
            $newQualificationNames[] = $r['qualification_name'];
        }
    }
    $oldValues['Qualification'] = implode(', ', $oldQualificationNames);
    $newValues['Qualification'] = implode(', ', $newQualificationNames);
}
/* DESIGNATION CHANGES */
sort($oldDesignations);
sort($newDesignations);

if ($oldDesignations != $newDesignations) {

    $oldValues['Designation'] = implode(', ', $oldDesignations);
    $newValues['Designation'] = implode(', ', $newDesignations);
}
/* EMPLOYEE SUB TYPE CHANGES */
sort($oldSubTypes);
sort($newSubTypes);

if ($oldSubTypes != $newSubTypes) {

    $oldSubTypeNames = [];

    foreach ($oldSubTypes as $id) {

        $q = mysqli_query($conn,"SELECT type_name FROM employee_type_master WHERE id=".(int)$id);
        $r = mysqli_fetch_assoc($q);

        if ($r) {
            $oldSubTypeNames[] = $r['type_name'];
        }
    }

    $newSubTypeNames = [];

    foreach ($newSubTypes as $id) {

        $q = mysqli_query($conn,"SELECT type_name FROM employee_type_master WHERE id=".(int)$id);
        $r = mysqli_fetch_assoc($q);

        if ($r) {
            $newSubTypeNames[] = $r['type_name'];
        }
    }

    $oldValues['Employee Sub Type'] = implode(', ', $oldSubTypeNames);
    $newValues['Employee Sub Type'] = implode(', ', $newSubTypeNames);
}
if (!empty($oldValues) || !empty($newValues)) {
    addAuditLog(
        'User Management',
        'Update',
        'User Updated : '.$_POST['full_name'],
        $user_id,
        json_encode($oldValues),
        json_encode($newValues),
        'SUCCESS'
    );
}
/* REDIRECT */
header('Location: user_list.php');
exit;