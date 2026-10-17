<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if (!isset($_SESSION['user_id'])) {
    header('Location:/index.php'); exit;
}
if (!checkPermission('store_master','view')) {
    header('Location:/access_denied.php');
    exit;
}
if (isset($_GET['delete_id']) && is_numeric($_GET['delete_id'])) {

    if (!checkPermission('store_master','delete')) {
        header('Location:/access_denied.php');
        exit;
    }
    $id = (int)$_GET['delete_id'];
    $stmt = $conn->prepare("DELETE FROM stores WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: store_master.php");
    exit;
}
/* SAVE */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $id = (int)($_POST['id'] ?? 0);

if (
    ($id > 0 && !checkPermission('store_master','update')) ||
    ($id == 0 && !checkPermission('store_master','add'))
) {
    header('Location:/access_denied.php');
    exit;
}

    $hospital_id = $_POST['hospital_id'];
    $branch_id   = $_POST['branch_id'];
    $store_name  = $_POST['store_name'];
    $description = $_POST['description'];
    $gstin       = $_POST['gstin'];
    $owner       = $_POST['owner_name'];
    $status      = $_POST['status'];
    if (!empty($id)) {
        $stmt = $conn->prepare("UPDATE stores 
            SET hospital_id=?, branch_id=?, store_name=?, description=?, gstin=?, owner_name=?, status=? 
            WHERE id=?");
        $stmt->bind_param("iisssssi",$hospital_id,$branch_id,$store_name,$description,$gstin,$owner,$status,$id);
    } else {
        $stmt = $conn->prepare("INSERT INTO stores 
            (hospital_id, branch_id, store_name, description, gstin, owner_name, status) 
            VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param("iisssss",$hospital_id,$branch_id,$store_name,$description,$gstin,$owner,$status);
    }
    $stmt->execute();
    header("Location: store_master.php");
    exit;
}
/* FETCH */
$stores = $conn->query("
    SELECT 
        s.*, 
        h.hospital_name, 
        b.branch_name
    FROM stores s
    LEFT JOIN hospital_master h ON h.id = s.hospital_id
    LEFT JOIN branch_master b ON b.id = s.branch_id
    ORDER BY s.id DESC
");
$hospitals = $conn->query("SELECT id, hospital_name FROM hospital_master WHERE status = 1");
?>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>
<!DOCTYPE html>
<html>
<head>
<title>Store Master</title>
<!-- FAVICON -->
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>

.modal {
    display:none;
    position:fixed;
    top:0; left:0;
    width:100%; height:100%;
    background:rgba(0,0,0,0.5);
}
.modal-content {
    background:#fff;
    padding:20px;
    margin:5% auto;
    width:500px;
    border-radius:10px;
}
</style>
</head>
<body>
<div class="top-bar">
    <!-- LEFT: Breadcrumb -->
    <div class="breadcrumb">
        <a href="/dashboard/index.php">Home</a>
        <span>&gt;</span>
        <a href="/settings/settings.php">Settings</a>
        <span>&gt;</span>
        <a href="/masters/masters.php">Masters</a>
        <span>&gt;</span>
        <a href="/masters/inventory/inventory_master.php">Inventory</a>
        <span>&gt;</span>
        <a>Store Master</a>
    </div>
    <!-- CENTER: Title -->
    <h2>Store Master</h2>
    <!-- RIGHT: Button -->
    <div class="top-actions">
    <?php if(checkPermission('store_master','add')){ ?>
        <button class="btn" onclick="openModal()">+ Add Store</button>
    <?php } ?>
</div>
</div>
<table class="table">
<tr>
<th>Hospital</th>
<th>Branch</th>
<th>Store</th>
<th>Description</th>
<th>Owner</th>
<th>GSTIN</th>
<th>Status</th>
<th>Created</th>
<th>Action</th>
</tr>
<?php while($row = $stores->fetch_assoc()) { ?>
<tr>
<td><?= $row['hospital_name'] ?></td>
<td><?= $row['branch_name'] ?></td>
<td><?= $row['store_name'] ?></td>
<td><?= $row['description'] ?></td>
<td><?= $row['owner_name'] ?></td>
<td><?= $row['gstin'] ?></td>
<td>
<?php if(checkPermission('store_master','update')){ ?>
    <select
        class="status-dropdown <?= $row['status'] == 'Active' ? 'active' : 'inactive' ?>"
        onchange="updateStatus(this, <?= $row['id'] ?>)">
        <option value="Active" <?= $row['status']=='Active'?'selected':'' ?>>Active</option>
        <option value="Inactive" <?= $row['status']=='Inactive'?'selected':'' ?>>Inactive</option>
    </select>
<?php } else { ?>
    <?= htmlspecialchars($row['status']) ?>
<?php } ?>
</td>
<td>
    <?= !empty($row['created_at']) 
        ? date('d-M-Y h:iA', strtotime($row['created_at'])) 
        : '-' ?>
</td>
    <td class="action-buttons">
       <!-- EDIT -->
<?php if(checkPermission('store_master','update')){ ?>
<button class="btn-icon btn-edit" title="Edit"
    onclick='editStore(<?= json_encode($row) ?>)'>
    <i class="fa-solid fa-pen-to-square"></i>
</button>
<?php } ?>
   <!-- DELETE -->
<?php if(checkPermission('store_master','delete')){ ?>
<button class="btn-icon btn-delete" title="Delete"
    onclick="deleteStore(<?= $row['id'] ?>)">
    <i class="fa-solid fa-trash"></i>
</button>
<?php } ?>
</td>
</tr>
<?php } ?>
</table>
<!-- MODAL -->
<div class="modal" id="storeModal">
<div class="modal-content">
<form method="POST">
<input type="hidden" name="id" id="id">
<label>Hospital</label>
<select name="hospital_id" id="hospital" required onchange="loadBranches(this.value)">
    <option value="">Select</option>
    <?php while($h = $hospitals->fetch_assoc()) { ?>
        <option value="<?= $h['id'] ?>"><?= $h['hospital_name'] ?></option>
    <?php } ?>
</select>
<label>Branch</label>
<select name="branch_id" id="branch" required></select>
<label>Store Name</label>
<input type="text" name="store_name" id="store_name" required>
<label>Description</label>
<textarea name="description" id="description"></textarea>
<label>GSTIN</label>
<input type="text" name="gstin" id="gstin">
<label>Owner Name</label>
<input type="text" name="owner_name" id="owner">
<label>Status</label>
<select name="status" id="status">
    <option value="Active">Active</option>
    <option value="Inactive">Inactive</option>
</select>
<br><br>
<?php if(checkPermission('store_master','add') || checkPermission('store_master','update')){ ?>
<button type="submit">Save</button>
<?php } ?>
<button type="button" onclick="closeModal()">Cancel</button>
</form>
</div>
</div>
</div>
<script>
function openModal(){
    document.getElementById('storeModal').style.display='block';
}

function closeModal(){
    document.getElementById('storeModal').style.display='none';
}
/* EDIT */
function editStore(data){
    openModal();
    document.getElementById('id').value = data.id;
    document.getElementById('hospital').value = data.hospital_id;
    loadBranches(data.hospital_id, data.branch_id);
    document.getElementById('store_name').value = data.store_name;
    document.getElementById('description').value = data.description;
    document.getElementById('gstin').value = data.gstin;
    document.getElementById('owner').value = data.owner_name;
    document.getElementById('status').value = data.status;
}
/* LOAD BRANCH */
function loadBranches(hospital_id, selected=''){
    fetch('get_branches.php?hospital_id='+hospital_id)
    .then(res=>res.json())
    .then(data=>{
        let html = '<option value="">Select</option>';
        data.forEach(b=>{
            let sel = (b.id == selected) ? 'selected' : '';
            html += `<option value="${b.id}" ${sel}>${b.branch_name}</option>`;
        });
        document.getElementById('branch').innerHTML = html;
    });
}
</script>
<script>
let deleteId = null;
function deleteStore(id){
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
        window.location.href = "store_master.php?delete_id=" + deleteId;
    };
});
</script>
<script>
function toggleStatus(id, currentStatus){
    let newStatus = (currentStatus === 'Active') ? 'Inactive' : 'Active';
    if(!confirm("Change status to " + newStatus + "?")) return;
    fetch('update_store_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${id}&status=${newStatus}`
    })
    .then(res => res.text())
    .then(() => {
        location.reload();
    });
}
</script>
<script>
function updateStatus(el, id){
    let status = el.value;
    fetch('update_store_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${id}&status=${status}`
    })
    .then(res => res.text())
    .then(() => {
        // update color dynamically
        el.classList.remove('active','inactive');
        el.classList.add(status === 'Active' ? 'active' : 'inactive');
    });
}
</script>
<div id="deleteConfirm" class="confirm-modal">
  <div class="confirm-box">
    <p>Are you sure you want to delete this store?</p>
    <small>This action cannot be undone.</small>
    <div class="confirm-actions">
      <button class="btn-cancel" onclick="closeDelete()">Cancel</button>
      <button class="btn-confirm" id="confirmDeleteBtn">Delete</button>
    </div>
  </div>
</div>
</body>
</html>