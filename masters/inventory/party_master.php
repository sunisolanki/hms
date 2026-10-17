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
    $conn->query("DELETE FROM party_master WHERE id=$id");
    header("Location: party_master.php"); exit;
}

/* SAVE */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $party_name = $_POST['party_name'];
    $party_type = $_POST['party_type'];
    $mobile = $_POST['mobile'];
    $gstin = $_POST['gstin'];
    $status = $_POST['status'];

    if($id){
        $stmt = $conn->prepare("UPDATE party_master 
        SET party_name=?, party_type=?, mobile=?, gstin=?, status=? 
        WHERE id=?");
        $stmt->bind_param("sssssi", $party_name, $party_type, $mobile, $gstin, $status, $id);
    }else{
        $stmt = $conn->prepare("INSERT INTO party_master 
        (party_name, party_type, mobile, gstin, status)
        VALUES (?,?,?,?,?)");
        $stmt->bind_param("sssss", $party_name, $party_type, $mobile, $gstin, $status);
    }

    $stmt->execute();
    header("Location: party_master.php"); exit;
}

/* FETCH */
$data = $conn->query("
SELECT p.*, 
c.name AS city_name,
cc.name AS customer_city_name,
s.name AS gst_state_name

FROM party_master p

LEFT JOIN cities c 
    ON c.id = p.city

LEFT JOIN cities cc 
    ON cc.id = p.customer_city

LEFT JOIN states s
    ON s.id = p.gst_state

ORDER BY p.id DESC
");
?>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>

<!DOCTYPE html>
<html>
<head>
<title>Party Master</title>

<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<!-- FAVICON -->
    <link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
    <link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
</head>
<style>
    .table {overflow: auto; display: block;
    
</style>
<body>

<div class="top-bar">
    <div class="breadcrumb">
        <a href="/dashboard/index.php">Home</a> >
        <a href="/settings/settings.php">Settings</a> >
        <a href="/masters/masters.php">Masters</a> >
        <a href="/masters/inventory/inventory_master.php">Inventory</a> >
        <a>Party Master</a>
    </div>

    <h2>Party Master</h2>

    <div class="top-actions">
        <a href="party_form.php" class="btn btn-primary" target="_blank">
    <i class="fa-solid fa-plus"></i> Add Party
</a>
    </div>
</div>

<table class="table">
    <thead>
        <tr>
            <th>Party Name</th>
            <th>Party Type</th>
            <th>Address</th>
            <th>City</th>
            <th>Pincode</th>
            <th>Mobile</th>
            <th>Phone</th>
            <th>TIN No</th>
            <th>Type</th>
            <th>Quality</th>
            <th>Bank Details</th>
            <th>Customer Type</th>
            <th>Email</th>
            <th>Website</th>
            <th>Contact Person</th>
            <th>Customer City</th>
            <th>Registered Address</th>
            <th>Customer Mobile</th>
            <th>Customer Phone</th>
            <th>GSTIN</th>
            <th>GST State</th>
            <th>Exemption</th>
            <th>Exemption Remark</th>
            <th>VAT No</th>
            <th>VAT Date</th>
            <th>CST No</th>
            <th>CST Date</th>
            <th>PAN No</th>
            <th>Credit Period</th>
            <th>Credit Amount</th>
            <th>Special Discount</th>
            <th>Excise Reg No</th>
            <th>Drug License No</th>
            <th>TDS Type</th>
            <th>TDS Rate</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php while($row = $data->fetch_assoc()){ ?>
        <tr>
            <td><?= htmlspecialchars($row['party_name']) ?></td>
            <td><?= htmlspecialchars($row['party_type']) ?></td>
            <td><?= htmlspecialchars($row['address'] ?? '-') ?></td>
            <td><?= htmlspecialchars($row['city_name'] ?? '-') ?></td>
            <td><?= htmlspecialchars($row['pincode'] ?? '-') ?></td>
            <td><?= htmlspecialchars($row['mobile']) ?></td>
            <td><?= htmlspecialchars($row['phone'] ?? '-') ?></td>
            <td><?= htmlspecialchars($row['tin_no'] ?? '-') ?></td>
            <td><?= htmlspecialchars($row['type'] ?? '-') ?></td>
            <td><?= htmlspecialchars($row['quality'] ?? '-') ?></td>
            <td><?= !empty($row['bank_details']) ? nl2br(htmlspecialchars($row['bank_details']))  : '-' ?> </td>
            <td><?= htmlspecialchars($row['customer_type'] ?? '-') ?></td>
            <td><?= !empty($row['email']) ? htmlspecialchars($row['email']) : '-' ?></td>
            <td><?= !empty($row['website']) ? htmlspecialchars($row['website']) : '-' ?></td>
            <td><?= htmlspecialchars($row['contact_person'] ?? '-') ?></td>
            <td><?= htmlspecialchars($row['customer_city_name'] ?? '-') ?></td>
            <td><?= !empty($row['reg_address']) ? nl2br(htmlspecialchars($row['reg_address'])) : '-' ?></td>
            <td><?= htmlspecialchars($row['customer_mobile'] ?? '-') ?></td>
            <td><?= htmlspecialchars($row['customer_phone'] ?? '-') ?></td>
            <td><?= htmlspecialchars($row['gstin']) ?></td>
            <td><?= htmlspecialchars($row['gst_state_name'] ?? '-') ?></td>
            <td><?= htmlspecialchars($row['exemption'] ?? '-') ?></td>
            <td class="remark-cell"><?= !empty($row['exemption_remark']) ? nl2br(htmlspecialchars($row['exemption_remark'])) : '-' ?></td>
            <td><?= htmlspecialchars($row['vat_no'] ?? '-') ?></td>
            <td><?= !empty($row['vat_date']) && $row['vat_date'] != '0000-00-00' ? date('d-M-Y', strtotime($row['vat_date'])) : '-' ?></td>
            <td><?= htmlspecialchars($row['cst_no'] ?? '-') ?></td>
            <td><?= !empty($row['cst_date']) && $row['cst_date'] != '0000-00-00' ? date('d-M-Y', strtotime($row['cst_date'])) : '-' ?></td>
            <td><?= strtoupper(htmlspecialchars($row['pan_no'] ?? '-')) ?></td>
            <td><?= !empty($row['credit_period']) ? htmlspecialchars($row['credit_period']).' Days' : '-' ?></td>
            <td><?= !empty($row['credit_amt']) ? number_format($row['credit_amt'],2) : '-' ?></td>
            <td><?= !empty($row['spcl_discount']) ? htmlspecialchars($row['spcl_discount']).'%' : '-' ?></td>
            <td><?= htmlspecialchars($row['excise_reg_no'] ?? '-') ?></td>
            <td><?= htmlspecialchars($row['drug_license_no'] ?? '-') ?></td>
            <td><?= htmlspecialchars($row['tds_type'] ?? '-') ?></td>
            <td><?= !empty($row['tds_rate']) ? htmlspecialchars($row['tds_rate']).'%' : '-' ?></td>
            <td><select onchange="updateStatus(this,<?= $row['id'] ?>)" 
                class="status-dropdown <?= strtolower($row['status']) ?>">
                <option value="Active" <?= $row['status']=='Active'?'selected':'' ?>>Active</option>
                <option value="Inactive" <?= $row['status']=='Inactive'?'selected':'' ?>>Inactive</option>
                </select></td>
             <td class="action-buttons"><a href="party_form.php?id=<?= $row['id'] ?>" class="btn-icon btn-edit" title="Edit"><i class="fa-solid fa-pen-to-square"></i></a></button>
                <button class="btn-icon btn-delete" title="Delete" onclick="deleteData(<?= $row['id'] ?>)"><i class="fa-solid fa-trash"></i></button></td>
         </tr>
        <?php } ?>
    </tbody>
</table>

<!-- MODAL -->
<div class="modal" id="modal">
    <div class="modal-content">
        <form method="POST">
            <input type="hidden" name="id" id="id">
            
            <label>Party Name</label>
            <input type="text" name="party_name" id="party_name" required>
            
            <label>Party Type</label>
            <select name="party_type" id="party_type" required>
                <option value="">Select Party Type</option>
                <option value="Supplier">Supplier</option>
                <option value="Customer">Customer</option>
                <option value="Both">Both</option>
            </select>
            
            <label>Mobile</label>
            <input type="text" name="mobile" id="mobile" maxlength="10">
            
            <label>GSTIN</label>
            <input type="text" name="gstin" id="gstin" maxlength="15">
            
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
        <p>Are you sure you want to delete this Party?</p>
        <small>This action cannot be undone.</small>
        <div class="confirm-actions">
            <button class="btn-cancel" onclick="closeDelete()">Cancel</button>
            <button class="btn-confirm" id="confirmDeleteBtn">Delete</button>
        </div>
    </div>
</div>

<script>
/* DELETE */
let deleteId = null;

function deleteData(id){
    deleteId = id;
    document.getElementById("deleteConfirm").style.display = "flex";
}

function closeDelete(){
    document.getElementById("deleteConfirm").style.display = "none";
}

document.addEventListener("DOMContentLoaded", function(){
    document.getElementById("confirmDeleteBtn").onclick = function(){
        if(!deleteId) return;
        window.location.href = "party_master.php?delete_id=" + deleteId;
    };
});

/* STATUS */
function updateStatus(el, id){
    fetch('update_party_status.php',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`id=${id}&status=${el.value}`
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

// Update status dropdown color on change
document.querySelectorAll('.status-dropdown').forEach(el=>{
    el.addEventListener('change', function(){
        this.classList.remove('active','inactive');
        this.classList.add(this.value.toLowerCase());
    });
});

function closeModal(){
    document.getElementById('modal').style.display='none';
}
</script>
</body>
</html>