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
if (!checkPermission('specialization_master','view')) {
    header('Location: /access_denied.php'); exit;
}

/* DELETE */
if (isset($_GET['delete']) && checkPermission('specialization_master','delete')) {
    $id = (int)$_GET['delete'];
    if ($id > 0) {

    $oldQry = mysqli_query(
        $conn,
        "SELECT * FROM specialization_master WHERE id=$id"
    );

    $oldData = mysqli_fetch_assoc($oldQry);

    mysqli_query($conn,"DELETE FROM specialization_master WHERE id=$id");

    addAuditLog(
        'Specialization Master',
        'DELETE',
        'Specialization Deleted : '.$oldData['specialization_name'],
        $id,
        '',
        ''
    );
}
    header("Location: specialization_master.php");
    exit;
}

/* SAVE (ADD / EDIT) */
if ($_SERVER['REQUEST_METHOD']==='POST') {

    if (
        ($_POST['id'] && !checkPermission('specialization_master','edit')) ||
        (!$_POST['id'] && !checkPermission('specialization_master','add'))
    ) {
        header('Location: /access_denied.php'); exit;
    }

    $id     = (int)$_POST['id'];
    $code   = mysqli_real_escape_string($conn,$_POST['specialization_code']);
    $name   = mysqli_real_escape_string($conn,$_POST['specialization_name']);
    $desc   = mysqli_real_escape_string($conn,$_POST['description']);
    $status = (int)$_POST['status'];

    if ($id > 0) {
        $oldQry = mysqli_query(
    $conn,
    "SELECT * FROM specialization_master WHERE id=$id"
);

$oldData = mysqli_fetch_assoc($oldQry);
        mysqli_query($conn,"
            UPDATE specialization_master SET
            specialization_code='$code',
            specialization_name='$name',
            description='$desc',
            status='$status'
            WHERE id=$id
        ");
        $oldValues = [];
$newValues = [];

if ($oldData['specialization_code'] != $code) {
    $oldValues['specialization_code'] = $oldData['specialization_code'];
    $newValues['specialization_code'] = $code;
}

if ($oldData['specialization_name'] != $name) {
    $oldValues['specialization_name'] = $oldData['specialization_name'];
    $newValues['specialization_name'] = $name;
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
        'Specialization Master',
        'UPDATE',
        'Specialization Updated : '.$name,
        $id,
        json_encode($oldValues),
        json_encode($newValues)
    );

}
    } else {
        mysqli_query($conn,"
    INSERT INTO specialization_master SET
    specialization_code='$code',
    specialization_name='$name',
    description='$desc',
    status=1
");

$specializationId = mysqli_insert_id($conn);

addAuditLog(
    'Specialization Master',
    'CREATE',
    'Specialization Created : '.$name,
    $specializationId,
    '',
    ''
);
    }

    header("Location: specialization_master.php");
    exit;
}

/* FETCH LIST */
$res = mysqli_query($conn,"
SELECT
    s.*,
    u.full_name AS created_by_name
FROM specialization_master s
LEFT JOIN users u
    ON u.id = s.created_by
ORDER BY s.id DESC
");
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Specialization Master</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- FAVICON -->
    <link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
    <link rel="apple-touch-icon" href="/assets/img/hms_logo.png">

<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
.header-bar{display:flex;justify-content:space-between;align-items:center;}
table{width:100%;border-collapse:collapse;background:#fff}
th,td{border:1px solid #ddd;padding:6px;font-size:13px}
th{background:#f1f1f1}
.btn{padding:6px 12px;font-size:13px;border-radius:4px;text-decoration:none;cursor:pointer;    max-width: fit-content;}
.btn-success{background:#28a745;color:#fff}
.btn-primary{background:#007bff;color:#fff}
.btn-danger{background:#dc3545;color:#fff}
.btn-sm{padding:4px 8px;font-size:12px}
.badge-success{background:#28a745;color:#fff;padding:3px 6px;border-radius:3px}
.badge-secondary{background:#6c757d;color:#fff;padding:3px 6px;border-radius:3px}
 .action-buttons{display:flex;gap:6px;justify-content:center;}
.btn-icon{border:none;width:32px;height:32px;border-radius:6px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:14px;transition:0.2s;}
/* Edit */
.btn-edit{background:#e8f5e9;color:#2e7d32;}
.btn-edit:hover{background:#2e7d32;color:#fff;}
/* Delete */
.btn-delete{background:#fdecea;color:#c62828;}
.btn-delete:hover{background:#c62828;color:#fff;
}
.btn{background:#2c7be5;color:#fff;padding:8px 14px;border:none;border-radius:5px;cursor:pointer;    width: auto;}
.btn:hover{    background:#1a5fd0;   /* darker blue */    color:#fff;           /* keep text white */}

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
        <a>Specialization Master</a>
    </div>

    <h2 style="margin:0 auto;">Specialization Master</h2>

    <?php if (checkPermission('specialization_master','add')) { ?>
        <button class="btn" onclick="openModal()">+ Add Specialization</button>
    <?php } ?>
</div>

<div class="container">

<table>
<thead>
<tr>
    <th>#</th>
    <th>Code</th>
    <th>Specialization Name</th>
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
    <td><?= htmlspecialchars($row['specialization_code']) ?></td>
    <td><?= htmlspecialchars($row['specialization_name']) ?></td>
    <td><?= htmlspecialchars($row['description']) ?></td>
    <td>
        <?= $row['status']==1
            ? '<span class="badge-success">Active</span>'
            : '<span class="badge-secondary">Inactive</span>' ?>
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

<?php if (checkPermission('specialization_master','edit')) { ?>
<button class="btn-icon btn-edit"
onclick='editSpecialization(<?= json_encode($row) ?>)'>
<i class="fa-solid fa-pen-to-square"></i>
</button>
<?php } ?>

<?php if (checkPermission('specialization_master','delete')) { ?>
<button class="btn-icon btn-delete"
onclick="confirmDelete(<?= $row['id'] ?>)">
<i class="fa-solid fa-trash"></i>
</button>
<?php } ?>

</td>
</tr>
<?php }} else { ?>
<tr><td colspan="9" align="center">No records found</td></tr>
<?php } ?>

</tbody>
</table>

</div>

<!-- MODAL -->
<div class="modal" id="specializationModal">
<div class="modal-box">

<h3 id="modalTitle">Add Specialization</h3>

<form method="post">

<input type="hidden" name="id" id="id">

<label>Specialization Code *</label>
<input name="specialization_code" id="specialization_code" required>

<label>Specialization Name *</label>
<input name="specialization_name" id="specialization_name" required>

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
    document.getElementById('specializationModal').style.display='block';
    document.getElementById('modalTitle').innerText='Add Specialization';
    document.querySelector('#specializationModal form').reset();
    document.getElementById('id').value='';
}

function closeModal(){
    document.getElementById('specializationModal').style.display='none';
}

function editSpecialization(data){
    openModal();
    document.getElementById('modalTitle').innerText='Edit Specialization';
    document.getElementById('id').value=data.id;
    document.getElementById('specialization_code').value=data.specialization_code;
    document.getElementById('specialization_name').value=data.specialization_name;
    document.getElementById('description').value=data.description;
    document.getElementById('status').value=data.status;
}
</script>
<div id="deleteConfirm" class="confirm-modal">
  <div class="confirm-box">
    <p>Are you sure you want to delete this specialization?</p>
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

        window.location.href = "specialization_master.php?delete=" + deleteId;

    };

});

</script>
</body>
</html>
