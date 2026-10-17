<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
/* LOGIN */
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php'); exit;
}
/* VIEW PERMISSION */
if (!checkPermission('employee_type_master','view')) {
    header('Location: /access_denied.php'); exit;
}
/* DELETE */
if (isset($_GET['delete']) && checkPermission('employee_type_master','delete')) {
    $id = (int)$_GET['delete'];
    if ($id > 0) {

    $oldQry = mysqli_query(
        $conn,
        "SELECT * FROM employee_type_master WHERE id=$id"
    );

    $oldData = mysqli_fetch_assoc($oldQry);

    mysqli_query($conn,"DELETE FROM employee_type_master WHERE id=$id");

    addAuditLog(
        'Employee Type Master',
        'DELETE',
        'Employee Type Deleted : '.$oldData['type_name'],
        $id,
        '',
        ''
    );
}
    header("Location: employee_type_master.php");
    exit;
}
/* SAVE (ADD / EDIT) */
if ($_SERVER['REQUEST_METHOD']==='POST') {

    if (
        ($_POST['id'] && !checkPermission('employee_type_master','edit')) ||
        (!$_POST['id'] && !checkPermission('employee_type_master','add'))
    ) {
        header('Location: /access_denied.php'); exit;
    }

    $id        = (int)$_POST['id'];
    $parent_id= (int)$_POST['parent_id'];
    $code     = mysqli_real_escape_string($conn,$_POST['type_code']);
    $name     = mysqli_real_escape_string($conn,$_POST['type_name']);
    $desc     = mysqli_real_escape_string($conn,$_POST['description']);
    $status   = (int)$_POST['status'];

    if ($id > 0) {
        $oldQry = mysqli_query(
    $conn,
    "SELECT * FROM employee_type_master WHERE id=$id"
);

$oldData = mysqli_fetch_assoc($oldQry);
        mysqli_query($conn,"
            UPDATE employee_type_master SET
            parent_id='$parent_id',
            type_code='$code',
            type_name='$name',
            description='$desc',
            status='$status'
            WHERE id=$id
        ");
        $oldValues = [];
$newValues = [];

if ($oldData['parent_id'] != $parent_id) {
    $oldValues['parent_id'] = $oldData['parent_id'];
    $newValues['parent_id'] = $parent_id;
}

if ($oldData['type_code'] != $code) {
    $oldValues['type_code'] = $oldData['type_code'];
    $newValues['type_code'] = $code;
}

if ($oldData['type_name'] != $name) {
    $oldValues['type_name'] = $oldData['type_name'];
    $newValues['type_name'] = $name;
}

if ($oldData['description'] != $desc) {
    $oldValues['description'] = $oldData['description'];
    $newValues['description'] = $desc;
}

if ($oldData['status'] != $status) {
    $oldValues['status'] = $oldData['status'] == 1 ? 'Active' : 'Inactive';
    $newValues['status'] = $status == 1 ? 'Active' : 'Inactive';
}

if (!empty($oldValues)) {

    addAuditLog(
        'Employee Type Master',
        'UPDATE',
        'Employee Type Updated : '.$name,
        $id,
        json_encode($oldValues),
        json_encode($newValues)
    );

}
    } else {
        mysqli_query($conn,"
    INSERT INTO employee_type_master SET
    parent_id='$parent_id',
    type_code='$code',
    type_name='$name',
    description='$desc',
    status=1,
    created_by='{$_SESSION['user_id']}'
");

$typeId = mysqli_insert_id($conn);

addAuditLog(
    'Employee Type Master',
    'CREATE',
    'Employee Type Created : '.$name,
    $typeId,
    '',
    ''
);
    }

    header("Location: employee_type_master.php");
    exit;
}

/* FETCH LIST */
$res = mysqli_query($conn,"
    SELECT
    e.*,
    p.type_name AS parent_name,
    u.full_name AS created_by_name
    FROM employee_type_master e
    LEFT JOIN employee_type_master p ON p.id = e.parent_id
    LEFT JOIN users u ON u.id = e.created_by
    ORDER BY e.parent_id, e.type_name
");

/* PARENT TYPES (FOR DROPDOWN) */
$parents = mysqli_query($conn,"
    SELECT id, type_name 
    FROM employee_type_master 
    WHERE parent_id = 0 
    ORDER BY type_name
");
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Employee Type Master</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<!-- FAVICON -->
    <link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
    <link rel="apple-touch-icon" href="/assets/img/hms_logo.png">

<style>
.header-bar{display:flex;justify-content:space-between;align-items:center;}

/* MODAL */
.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5)}
.modal-box{background:#fff;width:520px;max-width:95%;margin:80px auto;padding:20px;border-radius:6px}
.modal h3{margin-top:0}
label{font-weight:600;font-size:13px;margin-top:10px;display:block}
input,textarea,select{width:100%;padding:7px;border:1px solid #ccc;border-radius:4px}
textarea{min-height:70px}
.actions{text-align:right;margin-top:15px}
</style>
</head>

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>

<body>

<div class="top-bar">  
    <div class="breadcrumb">
        <a href="/dashboard/index.php">Home</a>
        <span>&gt;</span>
        <a href="/settings/settings.php">Settings</a>
        <span>&gt;</span>
        <a href="/masters/masters.php">Masters</a>
        <span>&gt;</span>
        <a>Employee Type Master</a>
    </div>

    <h2 style="margin:0 auto;">Employee Type Master</h2>

    <?php if (checkPermission('employee_type_master','add')) { ?>
        <button class="btn" onclick="openModal()">+ Add Employee Type</button>
    <?php } ?>
</div>

<div class="container">

<table>
<thead>
<tr>
    <th>#</th>
    <th>Parent Type</th>
    <th>Type Code</th>
    <th>Type Name</th>
    <th>Description</th>
    <th>Status</th>
    <th>Created By</th>
    <th>Created</th>
    <th>Updated</th>
    <th>Action</th>
</tr>
</thead>
<tbody>

<?php
$i=1;
if ($res && mysqli_num_rows($res)>0) {
while($row=mysqli_fetch_assoc($res)) {
?>
<tr>
    <td><?= $i++ ?></td>
    <td><?= htmlspecialchars($row['parent_name'] ?: '—') ?></td>
    <td><?= htmlspecialchars($row['type_code']) ?></td>
    <td><?= htmlspecialchars($row['type_name']) ?></td>
    <td><?= htmlspecialchars($row['description']) ?></td>
    <td>
<?php if ($row['status'] == 1) { ?>
    <span class="badge badge-success">Active</span>
<?php } else { ?>
    <span class="badge badge-secondary">Inactive</span>
<?php } ?>
</td>
<td><?= htmlspecialchars($row['created_by_name'] ?? 'N/A') ?></td>

<td>
<?= !empty($row['created_at'])
? date('d-M-Y h:i A', strtotime($row['created_at']))
: '-' ?>
</td>

<td>
<?= !empty($row['updated_at'])
? date('d-M-Y h:i A', strtotime($row['updated_at']))
: '-' ?>
</td>
    <td class="action-buttons">

<?php if (checkPermission('employee_type_master','edit')) { ?>
<button class="btn-icon btn-edit"
onclick='editType(<?= json_encode($row) ?>)'>
<i class="fa-solid fa-pen-to-square"></i>
</button>
<?php } ?>

<?php if (checkPermission('employee_type_master','delete')) { ?>
<button class="btn-icon btn-delete"
onclick="confirmDelete(<?= $row['id'] ?>)">
<i class="fa-solid fa-trash"></i>
</button>
<?php } ?>

</td>
</tr>
<?php }} else { ?>
<tr><td colspan="7" align="center">No records found</td></tr>
<?php } ?>

</tbody>
</table>

</div>

<!-- MODAL -->
<div class="modal" id="typeModal">
<div class="modal-box">

<h3 id="modalTitle">Add Employee Type</h3>

<form method="post">

<input type="hidden" name="id" id="id">

<label>Parent Employee Type</label>
<select name="parent_id" id="parent_id">
    <option value="0">— Main Type —</option>
    <?php while($p=mysqli_fetch_assoc($parents)){ ?>
        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['type_name']) ?></option>
    <?php } ?>
</select>

<label>Type Code *</label>
<input name="type_code" id="type_code" required>

<label>Type Name *</label>
<input name="type_name" id="type_name" required>

<label>Description</label>
<textarea name="description" id="description"></textarea>

<label>Status</label>
<select name="status" id="status">
    <option value="1">Active</option>
    <option value="0">Inactive</option>
</select>

<div class="actions">
    <button class="btn btn-success">Save</button>
    <button type="button" class="btn btn-danger" onclick="closeModal()">Cancel</button>
</div>

</form>

</div>
</div>

<script>
function openModal(){
    document.getElementById('typeModal').style.display='block';
    document.getElementById('modalTitle').innerText='Add Employee Type';
    document.querySelector('#typeModal form').reset();
    document.getElementById('id').value='';
    document.getElementById('parent_id').value='0';
}

function closeModal(){
    document.getElementById('typeModal').style.display='none';
}

function editType(data){
    openModal();
    document.getElementById('modalTitle').innerText='Edit Employee Type';
    document.getElementById('id').value=data.id;
    document.getElementById('parent_id').value=data.parent_id;
    document.getElementById('type_code').value=data.type_code;
    document.getElementById('type_name').value=data.type_name;
    document.getElementById('description').value=data.description;
    document.getElementById('status').value=data.status;
}
</script>
<div id="deleteConfirm" class="confirm-modal">
  <div class="confirm-box">
    <p>Are you sure you want to delete this employee type?</p>
    <small style="color:red;">This action cannot be undone.</small>

    <div class="confirm-actions">
      <button class="btn-cancel" onclick="closeDelete()">Cancel</button>
      <button class="btn-confirm" id="confirmDeleteBtn">Delete</button>
    </div>
  </div>
</div>
<script>

let deleteId = null;

function confirmDelete(id){
    deleteId = id;
    document.getElementById("deleteConfirm").style.display = "flex";
}

function closeDelete(){
    document.getElementById("deleteConfirm").style.display = "none";
    deleteId = null;
}

document.addEventListener("DOMContentLoaded", function(){

    document.getElementById("confirmDeleteBtn").onclick = function(){

        if(!deleteId) return;

        window.location.href = "employee_type_master.php?delete=" + deleteId;

    };

});

</script>
</body>
</html>
