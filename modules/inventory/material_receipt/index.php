<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if (!isset($_SESSION['user_id'])) {
    header('Location:/index.php');
    exit;
}
$data = $conn->query("
SELECT
ib.*,
pm.party_name,
im.item_name,
s.store_name,
hm.hospital_name,
bm.branch_name,
u.full_name AS created_by_name,
iss.inspection_required
FROM inventory_batches ib
LEFT JOIN inventory_store_setup iss
ON iss.hospital_id = ib.hospital_id
AND iss.branch_id = ib.branch_id
AND iss.store_id = ib.store_id
LEFT JOIN party_master pm
ON pm.id = ib.supplier_id
LEFT JOIN item_master im
ON im.id = ib.item_id
LEFT JOIN stores s
ON s.id = ib.store_id
LEFT JOIN hospital_master hm
ON hm.id = ib.hospital_id
LEFT JOIN branch_master bm
ON bm.id = ib.branch_id
LEFT JOIN users u
ON u.id = ib.created_by
ORDER BY ib.id DESC
");
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Material Receipt list</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<link rel="stylesheet" href="/assets/css/style.css">
    <style>
.table{
    width:max-content;
    min-width:100%;
    border-collapse:collapse;
}
.table thead th{
    position:sticky;
    top:0;
    background:#1976d2;
    color:#fff;
    z-index:100;
    white-space:nowrap;
}
.table-wrap{
    background:#fff;
    border-radius:10px;
    padding:1px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
    overflow:auto;
    
}
.page-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:5px;
}
.modal{

    display:none;

    position:fixed;

    z-index:99999;

    left:0;
    top:0;

    width:100%;
    height:100%;

    background:rgba(0,0,0,.5);

}
.modal-content{
    background:#fff;
    width:95%;
    max-width:1400px;
    height:90vh;
    margin:20px auto;
    border-radius:10px;
    overflow:hidden;
    display:flex;
    flex-direction:column;
    box-shadow:0 10px 30px rgba(0,0,0,.3);
}
.modal-header{
    background:#1976d2;
    color:#fff;
    padding:15px 20px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    flex-shrink:0;
}
.modal-body{
    flex:1;
    overflow:auto;
    padding:15px;
}
.closeModal{
    font-size:28px;
    cursor:pointer;
    font-weight:bold;
}
.viewBtn{
    background:#1976d2;
    color:#fff;
    border:none;
    padding:6px 12px;
    border-radius:5px;
    cursor:pointer;
    margin-right:5px;
    font-size:13px;
}
.printBtn{
    background:#28a745;
    color:#fff;
    text-decoration:none;
    padding:6px 12px;
    border-radius:5px;
    font-size:13px;
    display:inline-block;
}
</style>
</head>
<body>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>
<div class="main-content" id="mainContent" style="width:auto;">
<div class="page-container">
<div class="card">
<div class="page-header">
<h2>📦 Material Receipt List</h2>
<?php if(checkPermission('material_receipt','add')){ ?>
<a href="material_receipt_form.php" class="btn">
+ Add Material Receipt
</a>
<?php } ?>
</div>
<div class="table-wrap">
<table class="table">
<thead>
<tr>
<th>ID</th>
<th>MRN No</th>
<th>MRN Type</th>
<th>Supplier</th>
<th>MRN Against</th>
<th>Bill No</th>
<th>Bill Date</th>
<th>Mode of Entry</th>
<th>Hospital</th>
<th>Branch</th>
<th>GST Type</th>
<th>Exempted</th>
<th>Remarks</th>
<th>Item</th>
<th>Batch No</th>
<th>Barcode</th>
<th>Qty</th>
<th>Free Qty</th>
<th>Available Qty</th>
<th>MRP</th>
<th>Trade Rate</th>
<th>Bill Rate</th>
<th>GST %</th>
<th>Net Amount</th>
<th>Manufacture Date</th>
<th>Expiry Date</th>
<th>Store</th>
<th>MRN Status</th>
<th>Created By</th>
<th>Created At</th>
<th>Action</th>
</tr>
</thead>
<tbody>
<?php while($row = $data->fetch_assoc()) { ?>
<tr>
<td><?= $row['id'] ?></td>
<td><?= htmlspecialchars($row['mrn_no']) ?></td>
<td><?= htmlspecialchars($row['mrn_type']) ?></td>
<td><?= htmlspecialchars($row['party_name']) ?></td>
<td><?= htmlspecialchars($row['mrn_against']) ?></td>
<td><?= htmlspecialchars($row['bill_no']) ?></td>
<td><?= $row['bill_date'] ?></td>
<td><?= htmlspecialchars($row['mode_of_entry']) ?></td>
<td><?= htmlspecialchars($row['hospital_name']) ?></td>
<td><?= htmlspecialchars($row['branch_name']) ?></td>
<td><?= htmlspecialchars($row['gst_type']) ?></td>
<td><?php if($row['exempted'] == 1) { ?>Yes <?php } else { ?> No <?php } ?> </td>
<td><?= htmlspecialchars($row['remarks']) ?></td>
<td><?= htmlspecialchars($row['item_name']) ?></td>
<td><?= htmlspecialchars($row['batch_no']) ?></td>
<td><?= htmlspecialchars($row['barcode_no']) ?></td>
<td><?= $row['qty_received'] ?></td>
<td><?= $row['free_qty'] ?></td>
<td><?= $row['qty_available'] ?></td>
<td><?= $row['mrp'] ?></td>
<td><?= $row['trade_rate'] ?></td>
<td><?= $row['bill_rate'] ?></td>
<td><?= $row['gst_percent'] ?>%</td>
<td><?= $row['net_amount'] ?></td>
<td><?= $row['mfg_date'] ?></td>
<td><?= $row['exp_date'] ?></td>
<td><?= htmlspecialchars($row['store_name']) ?></td>
<td>
<?php
$status=$row['mrn_status'];
$bg='#6c757d';
if($status=='MRN Submitted'){
$bg='#17a2b8';
}
else if($status=='Inspection Pending'){
$bg='#ffc107';
}
else if($status=='Inspection Done'){
$bg='#fd7e14';
}
else if($status=='Inspection Approved'){
$bg='#28a745';
}
?>
<form
method="POST"
action="update_mrn_status.php">
<input
type="hidden"
name="mrn_no"
value="<?= $row['mrn_no'] ?>">
<?php
$inspectionEnabled=0;
$setupCheck=$conn->query("
SELECT inspection_required
FROM inventory_store_setup
WHERE hospital_id='".$row['hospital_id']."'
AND branch_id='".$row['branch_id']."'
AND store_id='".$row['store_id']."'
LIMIT 1
");
if($setupCheck->num_rows>0){
$setupData=$setupCheck->fetch_assoc();
$inspectionEnabled=
$setupData['inspection_required'];
}
?>
<select
name="mrn_status"
<?php
if(
$inspectionEnabled==1
){
}
else{
echo 'disabled';
}
?>
onchange="this.form.submit()"
style="
background:<?= $bg ?>;
color:#fff;
padding:6px 10px;
border:none;
border-radius:20px;
font-size:12px;
font-weight:bold;
cursor:pointer;
<?php
if(
$inspectionEnabled==1
){

}
else{

echo '
opacity:.6;
cursor:not-allowed;
pointer-events:none;
background:#999;
';

}
?>
">
<option value="MRN Submitted"
<?= $status=='MRN Submitted'
?'selected':'' ?>>
MRN Submitted
</option>
<option value="Inspection Pending"
<?= $status=='Inspection Pending'
?'selected':'' ?>>
Inspection Pending
</option>
<option value="Inspection Done"
<?= $status=='Inspection Done'
?'selected':'' ?>>
Inspection Done
</option>
<option value="Inspection Approved"
<?= $status=='Inspection Approved'
?'selected':'' ?>>
Inspection Approved
</option>
</select>
</form>
</td>
<td><?= htmlspecialchars($row['created_by_name']) ?></td>
<td><?= $row['created_at'] ?></td>
<td style="white-space:nowrap;">
<button
type="button"
class="viewBtn"
data-mrn="<?= $row['mrn_no'] ?>">
👁 View
</button>
<a
href="print_mrn.php?mrn_no=<?= urlencode($row['mrn_no']) ?>"
target="_blank"
class="printBtn">
🖨 Print
</a>
</td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
</div>
</div>
</div>
<!-- VIEW MODAL -->
<div id="viewModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                📦 Material Receipt View
            </h3>
            <span class="closeModal">
                &times;
            </span>
        </div>
        <div
        class="modal-body"
        id="modalBody">
            Loading...
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function(){
    const modal =
    document.getElementById('viewModal');
    const modalBody =
    document.getElementById('modalBody');
    document.querySelectorAll('.viewBtn')
    .forEach(button => {
        button.addEventListener('click', function(){
            let mrn =
            this.getAttribute('data-mrn');
            modal.style.display = 'block';
            modalBody.innerHTML =
            'Loading...';
            fetch(
                '/modules/inventory/material_receipt/view_mrn_popup.php?mrn_no='
                + encodeURIComponent(mrn)
            )
            .then(response => response.text())
            .then(data => {
                modalBody.innerHTML = data;
            })
            .catch(error => {
                console.log(error);
                modalBody.innerHTML =
                'Error Loading Data';
            });
        });
    });
    document.querySelector('.closeModal')
    .addEventListener('click', function(){
        modal.style.display = 'none';
    });
    window.addEventListener('click', function(e){
        if(e.target == modal){
            modal.style.display = 'none';
        }
    });
});
</script>
<script>
document.addEventListener(
    'keydown',
    function(e){
        if(e.key === 'Escape'){
            const modal =
            document.getElementById(
                'viewModal'
            );
            if(modal){
                modal.style.display =
                'none';
            }
        }
    }
);
</script>
</body>
</html>