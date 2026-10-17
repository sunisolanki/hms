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
            DELETE FROM lab_method_master
            WHERE id = $id
        ");
    }
    header("Location: method.php");
    exit;
}
/* SAVE (ADD / EDIT) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id          = (int)$_POST['id'];
    $method_name = mysqli_real_escape_string($conn,$_POST['method_name']);
    $description = mysqli_real_escape_string($conn,$_POST['description']);
    $status      = mysqli_real_escape_string($conn,$_POST['status']);
    if ($id > 0) {
        mysqli_query($conn,"
            UPDATE lab_method_master SET
            method_name = '$method_name',
            description = '$description',
            status      = '$status'
            WHERE id = $id
        ");

    } else {
        mysqli_query($conn,"
            INSERT INTO lab_method_master SET
            method_name = '$method_name',
            description = '$description',
            status      = 'Active'
        ");
    }
    header("Location: method.php");
    exit;
}
/* FETCH LIST */
$res = mysqli_query($conn,"
    SELECT *
    FROM lab_method_master
    ORDER BY id DESC
");
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Method Master</title>
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
        <a>Method Master</a>
    </div>
    <h2 style="margin:0 auto;">
        Method Master
    </h2>
    <?php if (checkPermission('masters','add')) { ?>
        <button class="btn" onclick="openModal()">
            + Add Method
        </button>
    <?php } ?>
</div>
<div class="container">
<table>
<thead>
<tr>
    <th>#</th>
    <th>Method Name</th>
    <th>Description</th>
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
        <?= htmlspecialchars($row['method_name']) ?>
    </td>
    <td>
        <?= htmlspecialchars($row['description']) ?>
    </td>
    <td>
        <?= $row['status']=='Active'
            ? '<span class="badge-success">Active</span>'
            : '<span class="badge-secondary">Inactive</span>' ?>
    </td>
    <td class="action-buttons">
        <?php if (checkPermission('masters','edit')) { ?>
        <button class="btn-icon btn-edit"
        onclick='editMethod(<?= json_encode($row) ?>)'>
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
<td colspan="5" align="center">
    No records found
</td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
<!-- MODAL -->
<div class="modal" id="methodModal">
<div class="modal-box">
<h3 id="modalTitle">
    Add Method
</h3>
<form method="post">
<input type="hidden" name="id" id="id">
<label>Method Name *</label>
<input
name="method_name"
id="method_name"
required
>
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
<option value="Active">
    Active
</option>
<option value="Inactive">
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
    >
        Cancel
    </button>
</div>
</form>
</div>
</div>
<script>
function openModal(){
    document.getElementById('methodModal').style.display='block';
    document.getElementById('modalTitle').innerText='Add Method';
    document.querySelector('#methodModal form').reset();
    document.getElementById('id').value='';
}
function closeModal(){
    document.getElementById('methodModal').style.display='none';
}
function editMethod(data){
    openModal();
    document.getElementById('modalTitle').innerText='Edit Method';
    document.getElementById('id').value=data.id;
    document.getElementById('method_name').value=data.method_name;
    document.getElementById('description').value=data.description;
    document.getElementById('status').value=data.status;
}
</script>
<!-- DELETE CONFIRM -->
<div id="deleteConfirm" class="confirm-modal">
<div class="confirm-box">
<p>
    Are you sure you want to delete this method?
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
        window.location.href = "method.php?delete=" + deleteId;
    };
});
</script>
</body>
</html>