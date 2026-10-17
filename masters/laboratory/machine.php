<?php
session_start();

require_once __DIR__ . '/../../includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

/* LOGIN */
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

/* VIEW PERMISSION */
if (!checkPermission('masters','view')) {
    header('Location: /access_denied.php');
    exit;
}

/* DELETE */
if (isset($_GET['delete']) && checkPermission('masters','delete')) {

    $id = (int)$_GET['delete'];

    if ($id > 0) {

        mysqli_query($conn,"
            DELETE FROM lab_machine_master
            WHERE id = $id
        ");
    }

    header("Location: machine.php");
    exit;
}

/* SAVE (ADD / EDIT) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $id           = (int)$_POST['id'];
    $machine_name = mysqli_real_escape_string($conn,$_POST['machine_name']);
    $description  = mysqli_real_escape_string($conn,$_POST['description']);
    $operator_id  = (int)$_POST['operator_id'];
    $status       = (int)$_POST['status'];

    if ($id > 0) {

        mysqli_query($conn,"
            UPDATE lab_machine_master SET
            machine_name = '$machine_name',
            description  = '$description',
            operator_id  = '$operator_id',
            status       = '$status'
            WHERE id = $id
        ");

    } else {

        mysqli_query($conn,"
            INSERT INTO lab_machine_master SET
            machine_name = '$machine_name',
            description  = '$description',
            operator_id  = '$operator_id',
            status       = '1'
        ");
    }

    header("Location: machine.php");
    exit;
}
/* FETCH USERS */
$users = mysqli_query($conn,"
    SELECT id, full_name
    FROM users
    WHERE status='Active'
    ORDER BY full_name ASC
");
/* FETCH LIST */
$res = mysqli_query($conn,"
    SELECT
        lm.*,
        u.full_name AS operator_name
    FROM lab_machine_master lm
    LEFT JOIN users u
        ON u.id = lm.operator_id
    ORDER BY lm.id DESC
");
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Machine Master</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
        <a href="/masters/laboratory/laboratory_master.php">
            Laboratory Master
        </a>
        <span>&gt;</span>
        <a>Machine Master</a>
    </div>
    <h2 style="margin:0 auto;">
        Machine Master
    </h2>
    <?php if (checkPermission('masters','add')) { ?>
        <button class="btn" onclick="openModal()">
            + Add Machine
        </button>
    <?php } ?>
</div>
<div class="container">
<table>
<thead>
<tr>
    <th>#</th>
    <th>Machine Name</th>
    <th>Description</th>
    <th>Operator</th>
    <th>Status</th>
    <th>Action</th>
</tr>
</thead>
<tbody>
<?php
$i = 1;
if ($res && mysqli_num_rows($res) > 0) {
while($row = mysqli_fetch_assoc($res)) {
?>
<tr>
    <td><?= $i++ ?></td>
    <td>
        <?= htmlspecialchars($row['machine_name']) ?>
    </td>
    <td>
        <?= htmlspecialchars($row['description']) ?>
    </td>
    <td>
        <?= htmlspecialchars($row['operator_name']) ?>
    </td>
    <td>
        <?= $row['status']==1
            ? '<span class="badge-success">Active</span>'
            : '<span class="badge-secondary">Inactive</span>' ?>
    </td>
    <td class="action-buttons">
        <?php if (checkPermission('masters','edit')) { ?>
        <button class="btn-icon btn-edit"
        onclick='editMachine(<?= json_encode($row) ?>)'>
            <i class="fa-solid fa-pen-to-square"></i>
        </button>
        <?php } ?>
        <?php if (checkPermission('masters','delete')) { ?>
        <button class="btn-icon btn-delete"
        onclick="confirmDelete(<?= $row['id'] ?>)">
            <i class="fa-solid fa-trash"></i>
        </button>
        <?php } ?>
    </td>
</tr>
<?php
}} else {
?>
<tr>
<td colspan="6" align="center">
    No records found
</td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
<!-- MODAL -->
<div class="modal" id="machineModal">
<div class="modal-box">
<h3 id="modalTitle">
    Add Machine
</h3>
<form method="post">
<input type="hidden" name="id" id="id">
<label>Machine Name *</label>
<input
name="machine_name"
id="machine_name"
required
>
<label>Operator *</label>
<select
name="operator_id"
id="operator_id"
required
>
<option value="">
    Select Operator
</option>
<?php while($user = mysqli_fetch_assoc($users)) { ?>
<option value="<?= $user['id'] ?>">
    <?= htmlspecialchars($user['full_name']) ?>
</option>
<?php } ?>
</select>
<label>Description</label>
<textarea
name="description"
id="description"
></textarea>
<label>Status</label>
<select
name="status"
id="status"
>
<option value="1">
    Active
</option>
<option value="0">
    Inactive
</option>
</select>
<div class="actions">
    <button class="btn btn-success">
        Save
    </button>
    <button
    type="button"
    class="btn btn-danger"
    onclick="closeModal()"
    >Cancel
    </button>
</div>
</form>
</div>
</div>
<script>
function openModal(){
    document.getElementById('machineModal').style.display='block';
    document.getElementById('modalTitle').innerText='Add Machine';
    document.querySelector('#machineModal form').reset();
    document.getElementById('id').value='';
}
function closeModal(){
    document.getElementById('machineModal').style.display='none';
}
function editMachine(data){
    openModal();
    document.getElementById('modalTitle').innerText='Edit Machine';
    document.getElementById('id').value=data.id;
    document.getElementById('machine_name').value=data.machine_name;
    document.getElementById('description').value=data.description;
    document.getElementById('operator_id').value=data.operator_id;
    document.getElementById('status').value=data.status;
}
</script>
<!-- DELETE CONFIRM -->
<div id="deleteConfirm" class="confirm-modal">
<div class="confirm-box">
<p>
    Are you sure you want to delete this machine?
</p>
<small style="color:red;">
    This action cannot be undone.
</small>
<div class="confirm-actions">
    <button class="btn-cancel"
    onclick="closeDelete()">
        Cancel
    </button>
    <button class="btn-confirm"
    id="confirmDeleteBtn">
        Delete
    </button>
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
        window.location.href = "machine.php?delete=" + deleteId;
    };
});
</script>
</body>
</html>