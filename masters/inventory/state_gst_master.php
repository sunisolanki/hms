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
if (isset($_GET['delete_id']) && is_numeric($_GET['delete_id'])) {

    if (!checkPermission('masters','delete')) {
        header('Location:/access_denied.php'); exit;
    }

    $id = (int)$_GET['delete_id'];
    $stmt = $conn->prepare("DELETE FROM state_gst_master WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    header("Location: state_gst_master.php");
    exit;
}

/* SAVE */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $id            = $_POST['id'] ?? '';
    $state_id      = $_POST['state_id'];
    $gst_state_no  = $_POST['gst_state_no'];
    $sgst          = $_POST['sgst'];
    $cgst          = $_POST['cgst'];
    $igst          = $_POST['igst'];
    $effective     = $_POST['effective_date'] ?? '';

    if (empty($effective)) {
        die("Effective Date not coming from form");
    }

    // Validate date format
    $dateTime = DateTime::createFromFormat('Y-m-d', $effective);
    if (!$dateTime || $dateTime->format('Y-m-d') !== $effective) {
        die("Invalid date format. Please use YYYY-MM-DD");
    }

    $created_by    = $_SESSION['user_id'];

    /* VALIDATION */
    if (abs(($sgst + $cgst) - $igst) > 0.01) {
        die("SGST + CGST must be equal to IGST");
    }

    /* DUPLICATE CHECK */
    if (!empty($id)) {
        $check = $conn->prepare("SELECT id FROM state_gst_master WHERE state_id=? AND id!=?");
        $check->bind_param("ii", $state_id, $id);
    } else {
        $check = $conn->prepare("SELECT id FROM state_gst_master WHERE state_id=?");
        $check->bind_param("i", $state_id);
    }
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        die("State GST already exists!");
    }

    if (!empty($id)) {
        // UPDATE - 7 parameters: state_id, gst_state_no, sgst_ugst, cgst, igst, effective_date, id
        $stmt = $conn->prepare("UPDATE state_gst_master 
            SET state_id=?, gst_state_no=?, sgst_ugst=?, cgst=?, igst=?, effective_date=? 
            WHERE id=?");
        $stmt->bind_param("isdddsi", $state_id, $gst_state_no, $sgst, $cgst, $igst, $effective, $id);
    } else {
        // INSERT - 7 parameters: state_id, gst_state_no, sgst_ugst, cgst, igst, effective_date, created_by
        $stmt = $conn->prepare("INSERT INTO state_gst_master 
            (state_id, gst_state_no, sgst_ugst, cgst, igst, effective_date, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isdddsi", $state_id, $gst_state_no, $sgst, $cgst, $igst, $effective, $created_by);
    }

    if ($stmt->execute()) {
        header("Location: state_gst_master.php");
        exit;
    } else {
        die("Error saving data: " . $stmt->error);
    }
}

/* FETCH */
$data = $conn->query("
    SELECT sgm.*, sm.name as state_name, u.full_name as created_by_name
    FROM state_gst_master sgm
    LEFT JOIN states sm ON sm.id = sgm.state_id
    LEFT JOIN users u ON u.id = sgm.created_by
    ORDER BY sgm.id DESC
");

$states = $conn->query("SELECT id, name FROM states ORDER BY name ASC");
?>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>

<!DOCTYPE html>
<html>
<head>
<title>State GST Master</title>

<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
.modal {
    display:none;
    position:fixed;
    top:0; left:0;
    width:100%; height:100%;
    background:rgba(0,0,0,0.5);
    z-index: 1000;
}

.modal-content {
    background:#fff;
    padding:20px;
    margin:5% auto;
    width:450px;
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
        <a>State GST Master</a>
    </div>

    <h2>State GST Master</h2>

    <div class="top-actions">
        <button class="btn" onclick="openModal()">+ Add</button>
    </div>
</div>

<table class="table">
    <thead>
        <tr>
            <th>State</th>
            <th>GST State No.</th>
            <th>SGST/UGST %</th>
            <th>CGST %</th>
            <th>IGST %</th>
            <th>Effective Date</th>
            <th>Created</th>
            <th>Created By</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php while($row = $data->fetch_assoc()) { ?>
        <tr>
            <td><?= htmlspecialchars($row['state_name']) ?></td>
            <td><?= htmlspecialchars($row['gst_state_no']) ?></td>
            <td><?= htmlspecialchars($row['sgst_ugst']) ?></td>
            <td><?= htmlspecialchars($row['cgst']) ?></td>
            <td><?= htmlspecialchars($row['igst']) ?></td>
            <td><?= date('d-M-Y', strtotime($row['effective_date'])) ?></td>
            <td><?= date('d-M-Y h:iA', strtotime($row['created_at'])) ?></td>
            <td><?= htmlspecialchars($row['created_by_name']) ?></td>
            <td class="action-buttons">
                <button class="btn-icon btn-edit" title="Edit" onclick='editData(<?= json_encode($row) ?>)'>
                    <i class="fa-solid fa-pen-to-square"></i>
                </button>
                <button class="btn-icon btn-delete" title="Delete" onclick="deleteData(<?= $row['id'] ?>)">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </td>
        </tr>
        <?php } ?>
    </tbody>
</table>

<!-- MODAL -->
<div class="modal" id="modal">
    <div class="modal-content">
        <form method="POST" onsubmit="return validateGST()">
            <input type="hidden" name="id" id="id">

            <label>State *</label>
            <select name="state_id" id="state_id" required>
                <option value="">Select State</option>
                <?php 
                $states->data_seek(0);
                while($s = $states->fetch_assoc()) { ?>
                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                <?php } ?>
            </select>

            <label>GST State No.</label>
            <input type="text" name="gst_state_no" id="gst_state_no">

            <label>SGST/UGST % *</label>
            <input type="number" step="0.01" name="sgst" id="sgst" required>

            <label>CGST % *</label>
            <input type="number" step="0.01" name="cgst" id="cgst" required>

            <label>IGST % *</label>
            <input type="number" step="0.01" name="igst" id="igst" required readonly style="background-color:#f5f5f5;">

            <label>Effective Date *</label>
            <input type="date" name="effective_date" id="effective_date" required>

            <br><br>
            <button type="submit" class="btn">Save</button>
            <button type="button" onclick="closeModal()">Cancel</button>
        </form>
    </div>
</div>

<!-- DELETE POPUP -->
<div id="deleteConfirm" class="confirm-modal">
    <div class="confirm-box">
        <p>Are you sure you want to delete this record?</p>
        <small>This action cannot be undone.</small>
        <div class="confirm-actions">
            <button onclick="closeDelete()">Cancel</button>
            <button id="confirmDeleteBtn" class="btn-delete">Delete</button>
        </div>
    </div>
</div>

<script>

function openModal(){
    document.getElementById('modal').style.display = 'block';
    
    document.getElementById('id').value = '';
    document.getElementById('state_id').value = '';
    document.getElementById('gst_state_no').value = '';
    document.getElementById('sgst').value = '';
    document.getElementById('cgst').value = '';
    document.getElementById('igst').value = '';
    
    let today = new Date();
    let year = today.getFullYear();
    let month = String(today.getMonth() + 1).padStart(2, '0');
    let day = String(today.getDate()).padStart(2, '0');
    let todayDate = year + '-' + month + '-' + day;
    document.getElementById('effective_date').value = todayDate;
}

function closeModal(){
    document.getElementById('modal').style.display = 'none';
}

function editData(d){
    openModal();
    document.getElementById('id').value = d.id;
    document.getElementById('state_id').value = d.state_id;
    document.getElementById('gst_state_no').value = d.gst_state_no || '';
    document.getElementById('sgst').value = d.sgst_ugst;
    document.getElementById('cgst').value = d.cgst;
    document.getElementById('igst').value = d.igst;
    document.getElementById('effective_date').value = d.effective_date;
}

function validateGST(){
    let sgst = parseFloat(document.getElementById('sgst').value) || 0;
    let cgst = parseFloat(document.getElementById('cgst').value) || 0;
    let igst = parseFloat(document.getElementById('igst').value) || 0;
    
    if(Math.abs((sgst + cgst) - igst) > 0.01){
        alert("SGST + CGST must equal IGST");
        return false;
    }
    
    let effectiveDate = document.getElementById('effective_date').value;
    if(!effectiveDate){
        alert("Please select effective date");
        return false;
    }
    
    return true;
}

let deleteId = null;

function deleteData(id){
    deleteId = id;
    document.getElementById("deleteConfirm").style.display = "flex";
}

function closeDelete(){
    deleteId = null;
    document.getElementById("deleteConfirm").style.display = "none";
}

document.getElementById("confirmDeleteBtn").onclick = function(){
    if(deleteId){
        window.location.href = "state_gst_master.php?delete_id=" + deleteId;
    }
};

document.getElementById('sgst').addEventListener('input', calcGST);
document.getElementById('cgst').addEventListener('input', calcGST);

function calcGST(){
    let sgst = parseFloat(document.getElementById('sgst').value) || 0;
    let cgst = parseFloat(document.getElementById('cgst').value) || 0;
    let total = sgst + cgst;
    document.getElementById('igst').value = total.toFixed(2);
}

window.onclick = function(event) {
    let modal = document.getElementById('modal');
    let confirmModal = document.getElementById('deleteConfirm');
    if (event.target == modal) {
        closeModal();
    }
    if (event.target == confirmModal) {
        closeDelete();
    }
}

</script>

</body>
</html>