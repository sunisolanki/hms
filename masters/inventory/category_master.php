<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location:/index.php'); exit;
}

if (!checkPermission('masters','view')) {
    header('Location:/access_denied.php'); exit;
}

/* DELETE */
if (isset($_GET['delete_id'])) {
    $id = (int)$_GET['delete_id'];
    $conn->query("DELETE FROM category_master WHERE id=$id");
    header("Location: category_master.php"); exit;
}

/* SAVE */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $id = $_POST['id'] ?? '';
    $hospital = $_POST['hospital_id'];
    $branch   = $_POST['branch_id'];
    $parent   = $_POST['parent_id'] ?: 0;
    $name     = $_POST['category_name'];
    $desc     = $_POST['description'];
    $status   = $_POST['status'];

    if($id){
        $stmt = $conn->prepare("UPDATE category_master 
        SET hospital_id=?, branch_id=?, parent_id=?, category_name=?, description=?, status=? 
        WHERE id=?");
        $stmt->bind_param("iiisssi",$hospital,$branch,$parent,$name,$desc,$status,$id);
    }else{
        $stmt = $conn->prepare("INSERT INTO category_master 
        (hospital_id,branch_id,parent_id,category_name,description,status)
        VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("iiisss",$hospital,$branch,$parent,$name,$desc,$status);
    }

    $stmt->execute();
    header("Location: category_master.php"); exit;
}

/* FETCH */
$data = $conn->query("
SELECT c.*, 
p.category_name AS parent_name,
h.hospital_name,
b.branch_name
FROM category_master c
LEFT JOIN category_master p ON p.id = c.parent_id
LEFT JOIN hospital_master h ON h.id = c.hospital_id
LEFT JOIN branch_master b ON b.id = c.branch_id
ORDER BY c.id DESC
");

/* DROPDOWN */
$categories = $conn->query("SELECT id,category_name FROM category_master WHERE parent_id=0");
$hospitals = $conn->query("SELECT id, hospital_name FROM hospital_master WHERE status=1");
?>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>

<!DOCTYPE html>
<html>
<head>
<title>Category Master</title>

<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<!-- FAVICON -->
    <link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
    <link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<style>
.modal {display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5);}
.modal-content {background:#fff; padding:20px; margin:5% auto; width:450px; border-radius:10px;}
</style>
</head>

<body>

<div class="top-bar">
    <div class="breadcrumb">
        <a href="/dashboard/index.php">Home</a> >
        <a href="/settings/settings.php">Settings</a> >
        <a href="/masters/masters.php">Masters</a> >
        <a href="/masters/inventory/inventory_master.php">Inventory</a> >
        <a>Category Master</a>
    </div>

<h2>Category Master</h2>

<div class="top-actions">
<button class="btn" onclick="openModal()">+ Add Category</button>
</div>
</div>

<table class="table">
<tr>
<th>Hospital</th>
<th>Branch</th>
<th>Category</th>
<th>Parent</th>
<th>Description</th>
<th>Status</th>
<th>Created</th>
<th>Action</th>
</tr>

<?php while($row=$data->fetch_assoc()){ ?>
<tr>
<td><?= htmlspecialchars($row['hospital_name']) ?></td>
<td><?= htmlspecialchars($row['branch_name']) ?></td>
<td><?= htmlspecialchars($row['category_name']) ?></td>
<td><?= htmlspecialchars($row['parent_name'] ?? '-') ?></td>
<td><?= htmlspecialchars($row['description'] ?? '') ?></td>

<td>
<select onchange="updateStatus(this,<?= $row['id'] ?>)" 
        class="status-dropdown <?= strtolower($row['status']) ?>">
    
<option value="Active" <?= $row['status']=='Active'?'selected':'' ?>>Active</option>
<option value="Inactive" <?= $row['status']=='Inactive'?'selected':'' ?>>Inactive</option>

</select>
</td>

<td><?= date('d-M-Y',strtotime($row['created_at'])) ?></td>

 <td class="action-buttons">
<button class="btn-icon btn-edit"  title="Edit" onclick='editData(<?= json_encode($row) ?>)'><i class="fa-solid fa-pen-to-square"></i></button>
<button class="btn-icon btn-delete" title="Delete" onclick="deleteData(<?= $row['id'] ?>)"><i class="fa-solid fa-trash"></i></button>
</td>
</tr>
<?php } ?>
</table>

<!-- MODAL -->
<div class="modal" id="modal">
<div class="modal-content">

<form method="POST">

<input type="hidden" name="id" id="id">

<label>Hospital</label>
<select name="hospital_id" id="hospital" required onchange="loadBranches(this.value)">
<option value="">Select Hospital</option>
<?php while($h = $hospitals->fetch_assoc()) { ?>
<option value="<?= $h['id'] ?>"><?= $h['hospital_name'] ?></option>
<?php } ?>
</select>

<label>Branch</label>
<select name="branch_id" id="branch" required>
<option value="">Select Branch</option>
</select>

<label>Parent Category</label>
<select name="parent_id" id="parent">
<option value="">Main Category</option>
<?php while($c=$categories->fetch_assoc()){ ?>
<option value="<?= $c['id'] ?>"><?= $c['category_name'] ?></option>
<?php } ?>
</select>

<label>Category Name</label>
<input type="text" name="category_name" id="name" required>

<label>Description</label>
<textarea name="description" id="desc"></textarea>

<label>Status</label>
<select name="status" id="status">
<option value="Active">Active</option>
<option value="Inactive">Inactive</option>
</select>

<br><br>

<button type="submit">Save</button>
<button type="button" onclick="closeModal()">Cancel</button>

</form>
</div>
</div>

<!-- DELETE POPUP -->


<div id="deleteConfirm" class="confirm-modal">
  <div class="confirm-box">
    <p>Are you sure you want to delete this HSN?</p>
    <small>This action cannot be undone.</small>
    <div class="confirm-actions">
      <button class="btn-cancel" onclick="closeDelete()">Cancel</button>
      <button class="btn-confirm" id="confirmDeleteBtn">Delete</button>
    </div>
  </div>
</div>
<script>
function openModal(){
    document.getElementById('modal').style.display='block';

    document.getElementById('id').value='';
    document.getElementById('hospital').value='';
    document.getElementById('branch').innerHTML='<option value="">Select Branch</option>';
    document.getElementById('parent').value='';
    document.getElementById('name').value='';
    document.getElementById('desc').value='';
    document.getElementById('status').value='Active';
}

function closeModal(){
    document.getElementById('modal').style.display='none';
}

function editData(d){
    openModal();

    document.getElementById('id').value = d.id;
    document.getElementById('hospital').value = d.hospital_id;

    // 🔥 Load branches FIRST, then select
    loadBranches(d.hospital_id, d.branch_id);

    document.getElementById('name').value = d.category_name;
    document.getElementById('desc').value = d.description;
    document.getElementById('status').value = d.status;
    document.getElementById('parent').value = d.parent_id;
}

/* DELETE */
let deleteId=null;

function deleteData(id){
deleteId=id;
document.getElementById("deleteConfirm").style.display="flex";
}

function closeDelete(){
document.getElementById("deleteConfirm").style.display="none";
}

document.addEventListener("DOMContentLoaded", function(){
    document.getElementById("confirmDeleteBtn").onclick = function(){
        if(!deleteId) return;
        window.location.href="category_master.php?delete_id="+deleteId;
    };
});

/* STATUS */
function updateStatus(el,id){
fetch('update_category_status.php',{
method:'POST',
headers:{'Content-Type':'application/x-www-form-urlencoded'},
body:`id=${id}&status=${el.value}`
});
}

function loadBranches(hospital_id, selected=''){
    fetch('get_branches.php?hospital_id='+hospital_id)
    .then(res=>res.json())
    .then(data=>{
        let html = '<option value="">Select Branch</option>';

        data.forEach(b=>{
            let sel = (b.id == selected) ? 'selected' : '';
            html += `<option value="${b.id}" ${sel}>${b.branch_name}</option>`;
        });

        document.getElementById('branch').innerHTML = html;
    });
}

window.onclick = function(event){
    let modal = document.getElementById('modal');
    if(event.target === modal){
        closeModal();
    }
}
document.addEventListener("keydown", function(e){
    if(e.key === "Escape"){
        closeModal();
    }
});
function closeModal(){
    document.getElementById('modal').style.display='none';
}
document.querySelectorAll('.status-dropdown').forEach(el=>{
    el.addEventListener('change', function(){
        this.classList.remove('active','inactive');
        this.classList.add(this.value.toLowerCase());
    });
});
</script>
</body>
</html>