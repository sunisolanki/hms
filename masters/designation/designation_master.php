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
if (!checkPermission('designation_master','view')) {
    header('Location: /access_denied.php'); exit;
}

/* DELETE */
if (isset($_GET['delete']) && checkPermission('designation_master','delete')) {
    $id = (int)$_GET['delete'];
    if ($id > 0) {

    $oldQry = mysqli_query(
        $conn,
        "SELECT * FROM designation_master WHERE id=$id"
    );

    $oldData = mysqli_fetch_assoc($oldQry);

    mysqli_query(
        $conn,
        "DELETE FROM designation_master WHERE id=$id"
    );

    addAuditLog(
        'Designation Master',
        'DELETE',
        'Designation Deleted : '.($oldData['designation_name'] ?? ''),
        $id,
        '',
        ''
    );
}
    header("Location: designation_master.php");
    exit;
}

/* SAVE (ADD / EDIT) */
if ($_SERVER['REQUEST_METHOD']==='POST') {

    if (
        ($_POST['id'] && !checkPermission('designation_master','edit')) ||
        (!$_POST['id'] && !checkPermission('designation_master','add'))
    ) {
        header('Location: /access_denied.php'); exit;
    }

    $id     = (int)$_POST['id'];
    $code   = mysqli_real_escape_string($conn,$_POST['designation_code']);
    $name   = mysqli_real_escape_string($conn,$_POST['designation_name']);
    $desc   = mysqli_real_escape_string($conn,$_POST['description']);
    $status = (int)$_POST['status'];

    if ($id > 0) {
        $oldQry = mysqli_query(
    $conn,
    "SELECT * FROM designation_master WHERE id=$id"
);

$oldData = mysqli_fetch_assoc($oldQry);
        mysqli_query($conn,"
    UPDATE designation_master SET
    designation_code='$code',
    designation_name='$name',
    description='$desc',
    status='$status',
    updated_at=NOW()
    WHERE id=$id
");
        $changesOld = [];
$changesNew = [];

if (($oldData['designation_code'] ?? '') != $code) {
    $changesOld['designation_code'] = $oldData['designation_code'];
    $changesNew['designation_code'] = $code;
}

if (($oldData['designation_name'] ?? '') != $name) {
    $changesOld['designation_name'] = $oldData['designation_name'];
    $changesNew['designation_name'] = $name;
}

if (($oldData['description'] ?? '') != $desc) {
    $changesOld['description'] = $oldData['description'];
    $changesNew['description'] = $desc;
}

if ((int)($oldData['status'] ?? 0) != $status) {
    $changesOld['status'] = $oldData['status'];
    $changesNew['status'] = $status;
}

if (!empty($changesNew)) {

    addAuditLog(
        'Designation Master',
        'UPDATE',
        'Designation Updated : '.$name,
        $id,
        json_encode($changesOld),
        json_encode($changesNew)
    );

}
    } else {
        mysqli_query($conn,"
    INSERT INTO designation_master SET
    designation_code='$code',
    designation_name='$name',
    description='$desc',
    status=1,
    created_by='{$_SESSION['user_id']}'
");
        $designationId = mysqli_insert_id($conn);

addAuditLog(
    'Designation Master',
    'CREATE',
    'Designation Created : '.$name,
    $designationId,
    '',
    ''
);
    }

    header("Location: designation_master.php");
    exit;
}

/* FETCH LIST */
$res = mysqli_query($conn,"
SELECT
    d.*,
    u.full_name AS created_by_name
FROM designation_master d
LEFT JOIN users u
    ON u.id = d.created_by
ORDER BY d.id DESC
");
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Designation Master</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- FAVICON -->
    <link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
    <link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
* { box-sizing: border-box; font-family: Arial, Helvetica, sans-serif; }
body {    margin: 0;    background: #f4f6f9;}
.container {    max-width: 98%;    margin: auto;    padding: 15px;}
h2 {    margin: 0;}
.btn{background:#2c7be5;color:#fff;padding:8px 14px;border:none;border-radius:5px;cursor:pointer;    width: auto;}
.btn:hover{    background:#1a5fd0;   /* darker blue */    color:#fff;           /* keep text white */}
.btn-success { background: #28a745; color: #fff; }
.btn-primary { background: #007bff; color: #fff; }
.btn-danger  { background: #dc3545; color: #fff; }
.action-buttons{display:flex;gap:6px;justify-content:center;}
.btn-icon{border:none;width:32px;height:32px;border-radius:6px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:14px;transition:0.2s;}
/* Edit */
.btn-edit{background:#e8f5e9;color:#2e7d32;}
.btn-edit:hover{background:#2e7d32;color:#fff;}
/* Delete */
.btn-delete{background:#fdecea;color:#c62828;}
.btn-delete:hover{background:#c62828;color:#fff;}
.btn-sm {    padding: 4px 8px;    font-size: 12px;}
.table-responsive {    overflow-x: auto;}
table {    width: 100%;    border-collapse: collapse;    background: #fff;}
th, td {    border: 1px solid #ddd;    padding: 6px;    font-size: 13px;    vertical-align: top;}
th {    background: #f1f1f1;    font-weight: bold;}
tr:nth-child(even) {    background: #fafafa;}
.badge {    padding: 4px 6px;    font-size: 12px;    border-radius: 3px;    color: #fff;}
.badge-success { background: #28a745; }
.badge-secondary { background: #6c757d; }
.header-bar {    display: flex;    align-items: center;    justify-content: space-between;}
.header-bar h2 {    margin: 0;}
</style>
</head>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>
<body>
<div class="top-bar">  
    <div class="breadcrumb">
    <a href="/dashboard/index.php">Home</a>
    <span>&gt;</span>
    <a href="/settings/settings.php">Settings</a>
    <span>&gt;</span>
    <a href="/masters/masters.php">Masters</a>
    <span>&gt;</span>
    <a>Designation Master</a>
    </div>
<h2 style="margin:0 auto;">Designation Master</h2>
    <?php if (checkPermission('designation_master','add')) { ?>
        <button class="btn btn-add" onclick="openModal()">+ Add Designation</button>
    <?php } ?>
 </div>
<div class="header-bar">
     </div>
    <div class="table-responsive">
<table>
<thead>
<tr>
    <th>#</th>
    <th>Code</th>
    <th>Name</th>
    <th>Description</th>
    <th>Status</th>
    <th>Created</th>
    <th>Created By</th>
    <th>Updated At</th>
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
    <td><?= htmlspecialchars($row['designation_code']) ?></td>
    <td><?= htmlspecialchars($row['designation_name']) ?></td>
    <td><?= htmlspecialchars($row['description']) ?></td>
     <td>
                    <?php if ($row['status'] == 1) { ?>
                        <span class="badge badge-success">Active</span>
                    <?php } else { ?>
                        <span class="badge badge-secondary">Inactive</span>
                    <?php } ?>
                </td>
                <td><?= date('d F, Y h:i A', strtotime($row['created_at'])); ?></td>
                <td><?= htmlspecialchars($row['created_by_name'] ?? 'N/A') ?></td>

<td>
<?= !empty($row['updated_at'])
    ? date('d-M-Y h:i A', strtotime($row['updated_at']))
    : '-' ?>
</td>
               <td class="action-buttons">

<?php if (checkPermission('designation_master','edit')) { ?>
<button class="btn-icon btn-edit"
onclick='editDesignation(<?= json_encode($row) ?>)'>
<i class="fa-solid fa-pen-to-square"></i>
</button>
<?php } ?>

<?php if (checkPermission('designation_master','delete')) { ?>
<button class="btn-icon btn-delete"
onclick="confirmDelete(<?= $row['id'] ?>)">
<i class="fa-solid fa-trash"></i>
</button>
<?php } ?>

</td>
</tr>
<?php }} else { ?>
<tr><td colspan="8" align="center">No records found</td></tr>
<?php } ?>

</tbody>
</table>

</div>

<!-- MODAL -->
<div class="modal" id="designationModal">
<div class="modal-box">

<h3 id="modalTitle">Add Designation</h3>

<form method="post">

<input type="hidden" name="id" id="id">

<label>Designation Code *</label>
<input name="designation_code" id="designation_code" required>

<label>Designation Name *</label>
<input name="designation_name" id="designation_name" required>

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
    document.getElementById('designationModal').style.display='block';
    document.getElementById('modalTitle').innerText='Add Designation';
    document.querySelector('form').reset();
    document.getElementById('id').value='';
}

function closeModal(){
    document.getElementById('designationModal').style.display='none';
}

function editDesignation(data){
    openModal();
    document.getElementById('modalTitle').innerText='Edit Designation';
    document.getElementById('id').value=data.id;
    document.getElementById('designation_code').value=data.designation_code;
    document.getElementById('designation_name').value=data.designation_name;
    document.getElementById('description').value=data.description;
    document.getElementById('status').value=data.status;
}
</script>
<div id="deleteConfirm" class="confirm-modal">
  <div class="confirm-box">
    <p>Are you sure you want to delete this designation?</p>
    <small>This action cannot be undone.</small>

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

        window.location.href = "designation_master.php?delete=" + deleteId;

    };

});

</script>
</body>
</html>
