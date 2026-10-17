<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: /login/login.php');
    exit;
}

if (!checkPermission('discount_master','view')) {
    header('Location: /access_denied.php');
    exit;
}
/* ===========================
   SAVE / UPDATE
=========================== */
if(isset($_POST['save_discount']))
{
    $id = (int)($_POST['id'] ?? 0);

if (
    ($id > 0 && !checkPermission('discount_master','update')) ||
    ($id == 0 && !checkPermission('discount_master','add'))
) {
    header('Location: /access_denied.php');
    exit;
}
    $id = $_POST['id'] ?? '';
    $oldDiscount = [];
$changedFields = [];
if (!empty($id)) {
    $stmtOld = $conn->prepare("
        SELECT *
        FROM discount_master
        WHERE id=?
    ");
    $stmtOld->bind_param("i", $id);
    $stmtOld->execute();
    $oldDiscount = $stmtOld->get_result()->fetch_assoc();
    $stmtOld->close();
}
if (!empty($oldDiscount)) {

    $fields = [

        'discount_name' => 'Discount Name',
        'description' => 'Description',
        'status' => 'Status',
        'consultation_type' => 'Consultation Type',
        'consultation_value' => 'Consultation Value',
        'receipt_type' => 'Receipt Type',
        'receipt_value' => 'Receipt Value'
    ];

    foreach ($fields as $dbField => $label) {

        $oldValue = (string)($oldDiscount[$dbField] ?? '');
        $newValue = (string)($_POST[$dbField] ?? '');

        if ($oldValue !== $newValue) {

            $changedFields[$label] = [
                'old' => $oldValue,
                'new' => $newValue
            ];
        }
    }
}
    $name = trim($_POST['discount_name']);
    $description = trim($_POST['description']);
    $status = $_POST['status'];

    $consultation_type = !empty($_POST['consultation_type']) ? $_POST['consultation_type'] : NULL;
    $consultation_value = ($_POST['consultation_value'] !== '') ? (float)$_POST['consultation_value'] : NULL;

    $receipt_type = !empty($_POST['receipt_type']) ? $_POST['receipt_type'] : NULL;
    $receipt_value = ($_POST['receipt_value'] !== '') ? (float)$_POST['receipt_value'] : NULL;

    $created_by = $_SESSION['username'] ?? 'Admin';

    /* ================= INSERT ================= */
    if(empty($id))
    {
        $stmt = $conn->prepare("INSERT INTO discount_master 
        (discount_name,description,status,
        consultation_type,consultation_value,
        receipt_type,receipt_value,
        created_by)
        VALUES (?,?,?,?,?,?,?,?)");

        $stmt->bind_param("ssssdsds",
            $name,
            $description,
            $status,
            $consultation_type,
            $consultation_value,
            $receipt_type,
            $receipt_value,
            $created_by
        );
    }
    /* ================= UPDATE ================= */
    else
    {
        $stmt = $conn->prepare("UPDATE discount_master SET
    discount_name=?,
    description=?,
    status=?,
    consultation_type=?,
    consultation_value=?,
    receipt_type=?,
    receipt_value=?,
    updated_date=NOW()
WHERE id=?");
        $stmt->bind_param("ssssdsdi",
            $name,
            $description,
            $status,
            $consultation_type,
            $consultation_value,
            $receipt_type,
            $receipt_value,
            $id
        );
    }
        if ($stmt->execute()) {
    /* ================= CREATE AUDIT ================= */
    if (empty($id)) {
        $newValues = [
            'Discount Name' => $name,
            'Description' => $description,
            'Status' => $status,
            'Consultation Type' => $consultation_type,
            'Consultation Value' => $consultation_value,
            'Receipt Type' => $receipt_type,
            'Receipt Value' => $receipt_value
        ];
        addAuditLog(
            'Discount Master',
            'Create',
            "Discount Created : ".$name,
            $conn->insert_id,
            '',
            json_encode($newValues),
            'SUCCESS'
        );
    }
    /* ================= UPDATE AUDIT ================= */
    if (!empty($id) && !empty($changedFields)) {
        $oldValues = [];
        $newValues = [];
        foreach ($changedFields as $field => $values) {
            $oldValues[$field] = $values['old'];
            $newValues[$field] = $values['new'];
        }
        addAuditLog(
            'Discount Master',
            'Update',
            "Discount Updated : ".$name,
            $id,
            json_encode($oldValues),
            json_encode($newValues),
            'SUCCESS'
        );
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}
}
/* ===========================
   FETCH ALL
=========================== */
$result = $conn->query("SELECT * FROM discount_master ORDER BY id DESC");
?>
<?php
if (isset($_GET['delete']) && checkPermission('discount_master','delete')) {
    $id = (int)$_GET['delete'];
    $stmt = $conn->prepare("
        SELECT *
        FROM discount_master
        WHERE id=?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $oldDiscount = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($oldDiscount) {
        addAuditLog(
            'Discount Master',
            'Delete',
            "Discount Deleted : ".$oldDiscount['discount_name'],
            $id,
            json_encode($oldDiscount),
            '',
            'SUCCESS'
        );
    }
    mysqli_query($conn, "DELETE FROM discount_master WHERE id=$id");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Discount Master</title>
<!-- FAVICON -->
    <link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
    <link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
       
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<style>
body{font-family:Arial;background:#f5f6fa;}
.container{width:95%;}

.modal{
display:none;
position:fixed;
top:0;left:0;
width:100%;height:100%;
background:rgba(0,0,0,0.5);
}
.modal-content{
background:#fff;
width:600px;
margin:60px auto;
padding:20px;
border-radius:5px;
position:relative;
}
.close{
position:absolute;
right:10px;
top:10px;
cursor:pointer;
font-weight:bold;
}
input,select,textarea{
width:100%;
padding:6px;
margin-bottom:10px;
}
</style>
</head>

<body>

<div class="container">

<div class="top-bar">  
    <div class="breadcrumb">
        <a href="/dashboard/index.php">Home</a>
        <span>&gt;</span>
        <a href="/settings/settings.php">Settings</a>
        <span>&gt;</span>
        <a href="/masters/masters.php">Masters</a>
        <span>&gt;</span>
        <a>Discount Master</a>
    </div>

    <h2 style="margin:0 auto;">Discount Master</h2>

    <?php if(checkPermission('discount_master','add')){ ?>
<button class="btn" onclick="openModal()">+ Add Discount</button>
<?php } ?>
</div>
<table>
<tr>
<th>#</th>
<th>Discount</th>
<th>Status</th>
<th>Consultation</th>
<th>Receipt</th>
<th>Created By</th>
<th>Created Date</th>
<th>Updated</th>
<th>Action</th>
</tr>

<?php $i=1; while($row=$result->fetch_assoc()): ?>
<tr>
<td><?= $i++ ?></td>
<td><?= $row['discount_name'] ?></td>
<td>
<?php
echo $row['status']=='Active'
? "<span class='status-badge status-active'>Active</span>"
: "<span class='status-badge status-inactive'>Inactive</span>";
?>
</td>
<td>
<?= $row['consultation_type'] ?> 
<?= $row['consultation_value'] ?>
</td>
<td>
<?= $row['receipt_type'] ?> 
<?= $row['receipt_value'] ?>
</td>
<td><?= $row['created_by'] ?></td>
<td><?= $row['created_date'] ?></td>
<td><?= $row['updated_date'] ?></td>
<td class="action-buttons">

<?php if(checkPermission('discount_master','update')){ ?>
<button class="btn-icon btn-edit"
onclick='editDiscount(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
    <i class="fa-solid fa-pen-to-square"></i>
</button>
<?php } ?>

<?php if(checkPermission('discount_master','delete')){ ?>
<button class="btn-icon btn-delete"
onclick="confirmDelete(<?= $row['id'] ?>)">
    <i class="fa-solid fa-trash"></i>
</button>
<?php } ?>
</td>
</tr>
<?php endwhile; ?>
</table>

</div>

<!-- MODAL -->

<div class="modal" id="discountModal">
<div class="modal-content">

<span class="close" onclick="closeModal()">X</span>

<h3 id="modalTitle">Add Discount</h3>

<form method="POST">

<input type="hidden" name="id" id="id">

<label>Discount *</label>
<input type="text" name="discount_name" id="discount_name" required>

<label>Description</label>
<textarea name="description" id="description"></textarea>

<label>Status</label>
<select name="status" id="status">
<option value="Active">Active</option>
<option value="Inactive">Inactive</option>
</select>

<hr>

<h4>Consultation</h4>

<select name="consultation_type" id="consultation_type">
<option value="">Select</option>
<option value="Percentage">Percentage</option>
<option value="Flat">Flat</option>
</select>

<input type="number" step="0.01" name="consultation_value" id="consultation_value" placeholder="Value">

<hr>

<h4>Receipt</h4>

<select name="receipt_type" id="receipt_type">
<option value="">Select</option>
<option value="Percentage">Percentage</option>
<option value="Flat">Flat</option>
</select>

<input type="number" step="0.01" name="receipt_value" id="receipt_value" placeholder="Value">

<br>

<?php if(
    checkPermission('discount_master','add') ||
    checkPermission('discount_master','update')
){ ?>
<button type="submit" name="save_discount" class="btn btn-primary">
    Save
</button>
<?php } ?>

</form>

</div>
</div>

<script>

function openModal(){
    document.getElementById("discountModal").style.display="block";
    document.getElementById("modalTitle").innerText="Add Discount";
    document.querySelector("form").reset();

    document.getElementById("id").value = "";
}

function closeModal(){
document.getElementById("discountModal").style.display="none";
}

function editDiscount(data){
openModal();
document.getElementById("modalTitle").innerText="Edit Discount";

document.getElementById("id").value=data.id;
document.getElementById("discount_name").value=data.discount_name;
document.getElementById("description").value=data.description;
document.getElementById("status").value=data.status;
document.getElementById("consultation_type").value=data.consultation_type;
document.getElementById("consultation_value").value=data.consultation_value;
document.getElementById("receipt_type").value=data.receipt_type;
document.getElementById("receipt_value").value=data.receipt_value;
}

window.onclick=function(event){
let modal=document.getElementById("discountModal");
if(event.target==modal){
modal.style.display="none";
}
}

</script>
<div id="deleteConfirm" class="confirm-modal">
  <div class="confirm-box">
    <p>Delete this discount?</p>
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

document.getElementById("confirmDeleteBtn").onclick = function(){
    if(!deleteId) return;
    window.location.href = "?delete=" + deleteId;
};
</script>
</body>
</html>