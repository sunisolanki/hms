<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';

if (!isset($_SESSION['user_id'])) {
    header('Location:/index.php'); exit;
}
if (!checkPermission('uom_master','view')) {
    header('Location:/access_denied.php');
    exit;
}
$user_id = $_SESSION['user_id'];
/* DELETE */
if (isset($_GET['delete_id'])) {
    if (!checkPermission('uom_master','delete')) {
        header('Location:/access_denied.php');
        exit;
    }
    $id = (int)$_GET['delete_id'];

/* GET OLD DATA */
$stmt = $conn->prepare("
    SELECT *
    FROM uom_master
    WHERE id=?
");
$stmt->bind_param("i",$id);
$stmt->execute();
$result = $stmt->get_result();
$oldData = $result->fetch_assoc();
$result->free();
$stmt->close();

/* DELETE */
$conn->query("DELETE FROM uom_master WHERE id=$id");

/* AUDIT */
if($oldData){
    $oldValues = [
        'UOM Name'    => $oldData['uom_name'],
        'Description' => $oldData['description'],
        'Branch ID'   => $oldData['branch_id'],
        'Status'      => $oldData['status']
    ];

    addAuditLog(
        'UOM Master',
        'Delete',
        'UOM Deleted : '.$oldData['uom_name'],
        $id,
        json_encode($oldValues),
        '',
        'SUCCESS'
    );
}
    header("Location: uom_master.php");
    exit;
}
/* SAVE */
if ($_SERVER['REQUEST_METHOD']=='POST') {

    $id = (int)($_POST['id'] ?? 0);

    if (
        ($id > 0 && !checkPermission('uom_master','update')) ||
        ($id == 0 && !checkPermission('uom_master','add'))
    ) {
        header('Location:/access_denied.php');
        exit;
    }
    $name = $_POST['uom_name'];
    $desc = $_POST['description'];
    $branch = $_POST['branch_id'];
    $status = $_POST['status'];

    if($id){
        
        /* GET OLD DATA */
$oldData=[];

$stmtOld=$conn->prepare("
SELECT *
FROM uom_master
WHERE id=?
");
$stmtOld->bind_param("i",$id);
$stmtOld->execute();
$res=$stmtOld->get_result();
$oldData=$res->fetch_assoc();
$res->free();
$stmtOld->close();

        $stmt = $conn->prepare("UPDATE uom_master 
        SET uom_name=?, description=?, branch_id=?, status=? WHERE id=?");
        $stmt->bind_param("ssisi",$name,$desc,$branch,$status,$id);
    } else {
        $stmt = $conn->prepare("INSERT INTO uom_master 
        (uom_name, description, branch_id, status, created_by) 
        VALUES (?,?,?,?,?)");
        $stmt->bind_param("ssisi",$name,$desc,$branch,$status,$user_id);
    }

    $stmt->execute();
    if(!$id && $stmt->affected_rows > 0){
    addAuditLog(
        'UOM Master',
        'Create',
        'UOM Created : '.$name,
        $stmt->insert_id,
        '',
        '',
        'SUCCESS'
    );
}
    if($id && $stmt->affected_rows > 0){

    $oldValues=[];
    $newValues=[];

    if(($oldData['uom_name'] ?? '')!=$name){
        $oldValues['UOM Name']=$oldData['uom_name'];
        $newValues['UOM Name']=$name;
    }

    if(($oldData['description'] ?? '')!=$desc){
        $oldValues['Description']=$oldData['description'];
        $newValues['Description']=$desc;
    }

    if(($oldData['branch_id'] ?? '')!=$branch){
        $oldValues['Branch ID']=$oldData['branch_id'];
        $newValues['Branch ID']=$branch;
    }

    if(($oldData['status'] ?? '')!=$status){
        $oldValues['Status']=$oldData['status'];
        $newValues['Status']=$status;
    }

    if(!empty($oldValues)){
        addAuditLog(
            'UOM Master',
            'Update',
            'UOM Updated : '.$name,
            $id,
            json_encode($oldValues),
            json_encode($newValues),
            'SUCCESS'
        );
    }
}
$stmt->close();
    header("Location: uom_master.php"); exit;
}

/* FETCH */
$uoms = $conn->query("
SELECT u.*, b.branch_name, u2.full_name as created_by_name
FROM uom_master u
LEFT JOIN branch_master b ON b.id=u.branch_id
LEFT JOIN users u2 ON u2.id=u.created_by
ORDER BY u.id DESC
");

$branches = $conn->query("SELECT id, branch_name FROM branch_master WHERE status=1");
?>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>

<!DOCTYPE html>
<html>
<head>
<title>UOM Master</title>

<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" href="/assets/img/hms_logo.png">
<!-- FAVICON -->
    <link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
    <link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
    .modal{
    display:none;
    position:fixed;
    left:0;
    top:0;
    width:100%;
    height:100%;
    background:rgba(0,0,0,.5);
    z-index:9999;
}

.modal-content{
    width:500px;
    background:#fff;
    margin:5% auto;
    padding:20px;
    border-radius:10px;
}
</style>
</head>
<body>
<div class="top-bar">
    <div class="breadcrumb">
        <a href="/dashboard/index.php">Home</a> >
        <a href="/settings/settings.php">Settings</a> >
        <a href="/masters/masters.php">Masters</a> >
        <a href="/masters/inventory/inventory_master.php">Inventory</a> >
        <a>UOM Master</a>
    </div>
    <h2>UOM Master</h2>
    <div>
<?php if(checkPermission('uom_master','add')){ ?>
    <button class="btn" onclick="openModal()">+ Add UOM</button>
<?php } ?>
</div>
</div>
<div class="container">
<table class="table">
<tr>
<th>UOM</th>
<th>Description</th>
<th>Branch</th>
<th>Status</th>
<th>Created</th>
<th>Created By</th>
<th>Action</th>
</tr>
<?php while($r=$uoms->fetch_assoc()){ ?>
<tr>

<td><?= $r['uom_name'] ?></td>
<td><?= $r['description'] ?></td>
<td><?= $r['branch_name'] ?></td>

<td>
<?php if(checkPermission('uom_master','update')){ ?>
<select class="status-dropdown <?= $r['status']=='Active' ? 'active' : 'inactive' ?>"
onchange="updateStatus(this,<?= $r['id'] ?>)">
    <option value="Active" <?= $r['status']=='Active' ? 'selected' : '' ?>>Active</option>
    <option value="Inactive" <?= $r['status']=='Inactive' ? 'selected' : '' ?>>Inactive</option>
</select>
<?php } else { ?>
    <?= htmlspecialchars($r['status']) ?>
<?php } ?>
</td>

<td><?= date('d-M-Y',strtotime($r['created_at'])) ?></td>
<td><?= $r['created_by_name'] ?></td>

<td class="action-buttons">
<?php if(checkPermission('uom_master','update')){ ?>
<button class="btn-icon btn-edit" onclick='editUOM(<?= json_encode($r) ?>)'>
    <i class="fa-solid fa-pen-to-square"></i>
</button>
<?php } ?>

<?php if(checkPermission('uom_master','delete')){ ?>
<button class="btn-icon btn-delete" onclick="deleteUOM(<?= $r['id'] ?>)">
    <i class="fa-solid fa-trash"></i>
</button>
<?php } ?>
</td>

</tr>
<?php } ?>

</table>
</div>

<!-- MODAL -->
<div class="modal" id="modal">
<div class="modal-content">
<form method="POST">

<input type="hidden" name="id" id="id">

<label>UOM Name</label>
<input type="text" name="uom_name" id="name" required>

<label>Description</label>
<textarea name="description" id="desc"></textarea>

<label>Branch</label>
<select name="branch_id" id="branch" required>
<?php while($b=$branches->fetch_assoc()){ ?>
<option value="<?= $b['id'] ?>"><?= $b['branch_name'] ?></option>
<?php } ?>
</select>

<label>Status</label>
<select name="status" id="status">
<option>Active</option>
<option>Inactive</option>
</select>

<br><br>
<button type="submit">Save</button>
<button type="button" onclick="closeModal()">Cancel</button>

</form>
</div>
</div>

<!-- DELETE -->
<div id="deleteConfirm" class="confirm-modal">
<div class="confirm-box">
<p>Are you sure you want to delete this UOM?</p>
    <small>This action cannot be undone.</small>
    <div class="confirm-actions">
      <button class="btn-cancel" onclick="closeDelete()">Cancel</button>
      <button class="btn-confirm" id="confirmDelete">Delete</button>
</div>
</div>
</div>
<script>
function openModal(){ document.getElementById('modal').style.display='block'; }
function closeModal(){ document.getElementById('modal').style.display='none'; }

function editUOM(d){
    openModal();

    document.getElementById('id').value = d.id;
    document.getElementById('name').value = d.uom_name;
    document.getElementById('desc').value = d.description;
    document.getElementById('branch').value = d.branch_id;
    document.getElementById('status').value = d.status;
}

let deleteId=null;
function deleteUOM(id){
deleteId=id;
document.getElementById('deleteConfirm').style.display='flex';
}
function closeDelete(){ document.getElementById('deleteConfirm').style.display='none'; }
function confirmDelete(){ window.location='uom_master.php?delete_id='+deleteId; }

function updateStatus(el,id){
fetch('update_uom_status.php',{
method:'POST',
headers:{'Content-Type':'application/x-www-form-urlencoded'},
body:`id=${id}&status=${el.value}`
}).then(()=>{
el.className='status-dropdown '+(el.value=='Active'?'active':'inactive');
});
}
</script>

</body>
</html>