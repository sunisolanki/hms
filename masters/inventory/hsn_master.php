<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';

if (!isset($_SESSION['user_id'])) {
    header('Location:/index.php'); exit;
}

if (!checkPermission('hsn_master','view')) {
    header('Location:/access_denied.php');
    exit;
}

/* DELETE */
if (isset($_GET['delete_id']) && is_numeric($_GET['delete_id'])) {

    if (!checkPermission('hsn_master','delete')) {
    header('Location:/access_denied.php');
    exit;
}

    $id = (int)$_GET['delete_id'];
    $stmt = $conn->prepare("DELETE FROM hsn_master WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    header("Location: hsn_master.php");
    exit;
}

/* SAVE */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);

if (
    ($id > 0 && !checkPermission('hsn_master','update')) ||
    ($id == 0 && !checkPermission('hsn_master','add'))
) {
    header('Location:/access_denied.php');
    exit;
}

    $hsn_code  = trim($_POST['hsn_code']);
    $gst       = (float)$_POST['gst'];
    $wef_date  = $_POST['wef_date'];

    /* GST VALIDATION */
    $allowed_gst = [0,5,12,18,28];
    if (!in_array($gst, $allowed_gst)) {
        die("Invalid GST %");
    }

    /* DUPLICATE CHECK */
    if (!empty($id)) {
        $check = $conn->prepare("SELECT id FROM hsn_master WHERE hsn_code=? AND id!=?");
        $check->bind_param("si", $hsn_code, $id);
    } else {
        $check = $conn->prepare("SELECT id FROM hsn_master WHERE hsn_code=?");
        $check->bind_param("s", $hsn_code);
    }
    $check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    $check->close();
    die("HSN already exists!");
}

$check->close();

    if (!empty($id)) {
        $stmt = $conn->prepare("UPDATE hsn_master 
            SET hsn_code=?, gst_percentage=?, wef_date=? 
            WHERE id=?");
        $stmt->bind_param("sdsi", $hsn_code, $gst, $wef_date, $id);
    } else {
        $stmt = $conn->prepare("INSERT INTO hsn_master 
            (hsn_code, gst_percentage, wef_date) 
            VALUES (?,?,?)");
        $stmt->bind_param("sds", $hsn_code, $gst, $wef_date);
    }

   $stmt->execute();
$stmt->close();

header("Location: hsn_master.php");
exit;
}

/* FETCH */
$hsn_list = $conn->query("SELECT * FROM hsn_master ORDER BY id DESC");
?>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>

<!DOCTYPE html>
<html>
<head>
<title>HSN Master</title>
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
    width:400px;
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
        <a>HSN Master</a>
    </div>

    <h2>HSN Master</h2>

    <div class="top-actions">
        <?php if(checkPermission('hsn_master','add')){ ?>
<button class="btn" onclick="openModal()">+ Add HSN</button>
<?php } ?>
    </div>
</div>

<table class="table">
<tr>
    <th>HSN Code</th>
    <th>GST %</th>
    <th>W.e.f Date</th>
    <th>Created</th>
    <th>Action</th>
</tr>

<?php while($row = $hsn_list->fetch_assoc()) { ?>
<tr>
    <td><?= $row['hsn_code'] ?></td>
    <td><?= $row['gst_percentage'] ?>%</td>
    <td><?= date('d-M-Y', strtotime($row['wef_date'])) ?></td>

    <td>
        <?= !empty($row['created_at']) 
            ? date('d-M-Y h:iA', strtotime($row['created_at'])) 
            : '-' ?>
    </td>

    <td class="action-buttons">
        <?php if(checkPermission('hsn_master','update')){ ?>
<button class="btn-icon btn-edit" title="Edit"
onclick='editHSN(<?= json_encode($row) ?>)'>
    <i class="fa-solid fa-pen-to-square"></i>
</button>
<?php } ?>

        <?php if(checkPermission('hsn_master','delete')){ ?>
<button class="btn-icon btn-delete" title="Delete"
onclick="deleteHSN(<?= $row['id'] ?>)">
    <i class="fa-solid fa-trash"></i>
</button>
<?php } ?>
    </td>
</tr>
<?php } ?>
</table>

<!-- MODAL -->
<div class="modal" id="hsnModal">
<div class="modal-content">

<form method="POST" onsubmit="return validateGST()">

<input type="hidden" name="id" id="id">

<label>HSN Code</label>
<input type="text" name="hsn_code" id="hsn_code" required>

<label>GST %</label>
<input type="number" name="gst" id="gst" required>

<label>W.e.f Date</label>
<input type="date" name="wef_date" id="wef_date" required>

<br><br>

<?php if(checkPermission('hsn_master','add') || checkPermission('hsn_master','update')){ ?>
<button type="submit">Save</button>
<?php } ?>
<button type="button" onclick="closeModal()">Cancel</button>

</form>

</div>
</div>

<script>

/* GST VALIDATION FRONTEND */
function validateGST(){
    let gst = parseFloat(document.getElementById('gst').value);
    let allowed = [0,5,12,18,28];

    if(!allowed.includes(gst)){
        alert("GST must be 0, 5, 12, 18 or 28");
        return false;
    }
    return true;
}

function openModal(){
    document.querySelector('#hsnModal form').reset();
    document.getElementById('id').value = '';
    document.getElementById('hsnModal').style.display='block';
}
function closeModal(){
    document.getElementById('hsnModal').style.display='none';
}

function editHSN(data){
    openModal();
    document.getElementById('id').value = data.id;
    document.getElementById('hsn_code').value = data.hsn_code;
    document.getElementById('gst').value = data.gst_percentage;
    document.getElementById('wef_date').value = data.wef_date;
}

/* DELETE */
let deleteId = null;

function deleteHSN(id){
    deleteId = id;
    document.getElementById("deleteConfirm").style.display = "flex";
}

function closeDelete(){
    deleteId = null;
    document.getElementById("deleteConfirm").style.display = "none";
}

document.addEventListener("DOMContentLoaded", function(){
    document.getElementById("confirmDeleteBtn").onclick = function(){
        if(!deleteId) return;
        window.location.href = "hsn_master.php?delete_id=" + deleteId;
    };
});

</script>
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
</body>
</html>