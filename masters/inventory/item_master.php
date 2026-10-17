<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if (!isset($_SESSION['user_id'])) {
    header('Location:/index.php');
    exit;
}
$data = $conn->query("
SELECT 
i.*,
c.category_name AS category_name,
sc.category_name AS sub_category_name,
h.hsn_code,
m.party_name AS manufacturer_name,
v.party_name AS vendor_name
FROM item_master i
LEFT JOIN category_master c
ON c.id = i.category_id
LEFT JOIN category_master sc
ON sc.id = i.sub_category_id
LEFT JOIN hsn_master h
ON h.id = i.hsn_id
LEFT JOIN party_master m
ON m.id = i.manufacturer_id
LEFT JOIN party_master v
ON v.id = i.vendor_id
ORDER BY i.id DESC
");
?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<!DOCTYPE html>
<html>
<head>
<title>Item Master</title>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
.table-responsive{
    overflow:auto;
}
.table td{
    white-space:nowrap;
}
.status-active{
    background:#d4edda;
    color:#155724;
    border:1px solid #c3e6cb;
    font-weight:600;
}
.status-inactive{
    background:#f8d7da;
    color:#721c24;
    border:1px solid #f5c6cb;
    font-weight:600;
}
</style>
</head>
<body>
<div class="top-bar">
    <div class="breadcrumb">
        <a href="/dashboard/index.php">Home</a>
        <span>></span>
        <a href="/masters/masters.php">Masters</a>
        <span>&gt;</span>
        <a href="/masters/inventory/inventory_master.php">Inventory</a>
        <span>&gt;</span>
        <a>Item Master</a>
    </div>
    <h2>Item Master</h2>
    <div class="top-actions">
        <a href="import/index.php" class="btn btn-primary">
            Import Item
        </a>
        <a href="item_form.php" class="btn btn-primary">
            <i class="fa-solid fa-plus"></i>
            Add Item
        </a>
    </div>
</div>
<div class="table-responsive">
<table class="table">
<thead>
<tr>
<th>ID</th>
<th>Item Name</th>
<th>Category</th>
<th>Sub Category</th>
<th>Item Group</th>
<th>Item Type</th>
<th>Packing</th>
<th>Max</th>
<th>Min</th>
<th>Reorder</th>
<th>Manufacturer</th>
<th>Vendor</th>
<th>HSN</th>
<th>Risk</th>
<th>High Risk</th>
<th>Discount %</th>
<th>Discount WEF</th>
<th>Status</th>
<th>Stores</th>
<th>Salts</th>
<th>Action</th>
</tr>
</thead>
<tbody>
<?php while($row = $data->fetch_assoc()) { ?>
<tr>
<td><?= $row['id'] ?></td>
<td><?= htmlspecialchars($row['item_name']) ?></td>
<td><?= htmlspecialchars($row['category_name'] ?? '-') ?></td>
<td><?= htmlspecialchars($row['sub_category_name'] ?? '-') ?></td>
<td><?= htmlspecialchars($row['item_group']) ?></td>
<td><?= htmlspecialchars($row['item_type']) ?></td>
<td><?= htmlspecialchars($row['packing']) ?></td>
<td><?= htmlspecialchars($row['max_level']) ?></td>
<td><?= htmlspecialchars($row['min_level']) ?></td>
<td><?= htmlspecialchars($row['reorder_level']) ?></td>
<td><?= htmlspecialchars($row['manufacturer_name'] ?? '-') ?></td>
<td><?= htmlspecialchars($row['vendor_name'] ?? '-') ?></td>
<td><?= htmlspecialchars($row['hsn_code'] ?? '-') ?></td>
<td><?= htmlspecialchars($row['risk_type'] ?? '-') ?></td>
<td><?= htmlspecialchars($row['high_risk']) ?></td>
<td><?= htmlspecialchars($row['discount_percent']) ?>%</td>
<td>
<?= !empty($row['discount_wef_date']) && $row['discount_wef_date']!='0000-00-00'
? date('d-M-Y',strtotime($row['discount_wef_date']))
: '-' ?>
</td>
<td>
<select 
class="<?= $row['status']=='Active' ? 'status-active' : 'status-inactive' ?>"
onchange="updateStatus(this,<?= $row['id'] ?>)">
<option value="Active"
<?= $row['status']=='Active'?'selected':'' ?>>
Active
</option>
<option value="Inactive"
<?= $row['status']=='Inactive'?'selected':'' ?>>
Inactive
</option>
</select>
</td>
<td>
<?php
$stores = $conn->query("
SELECT s.store_name
FROM item_store_mapping ism
LEFT JOIN stores s
ON s.id = ism.store_id
WHERE ism.item_id=".$row['id']
);
$arr=[];
while($s = $stores->fetch_assoc()){
    $arr[] = $s['store_name'];
}
echo !empty($arr) ? implode(', ', $arr) : '-';
?>
</td>
<td>
<?php
$salts = $conn->query("
SELECT sm.salt_name
FROM item_salt_mapping ism
LEFT JOIN salts_master sm
ON sm.id = ism.salt_id
WHERE ism.item_id=".$row['id']
);
$arr=[];
while($s = $salts->fetch_assoc()){
    $arr[] = $s['salt_name'];
}
echo !empty($arr) ? implode(', ', $arr) : '-';
?>
</td>
<td class="action-buttons">
<a href="item_form.php?id=<?= $row['id'] ?>"
class="btn-icon btn-edit"
title="Edit">
<i class="fa-solid fa-pen-to-square"></i>
</a>
<button type="button"
class="btn-icon btn-delete"
title="Delete"
onclick="deleteItem(<?= $row['id'] ?>)">
<i class="fa-solid fa-trash"></i>
</button>
</td>
</tr>
<?php } ?>
</tbody>
</table>
<!-- DELETE CONFIRM -->
<div id="deleteConfirm" class="confirm-modal">
    <div class="confirm-box">
        <p>Are you sure you want to delete this Item?</p>
        <small>This action cannot be undone.</small>
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
</div>
<script>
function updateStatus(el,id){
    fetch('update_item_status.php',{
        method:'POST',
        headers:{
            'Content-Type':'application/x-www-form-urlencoded'
        },
        body:'id='+id+'&status='+el.value
    })
    .then(res => res.text())
    .then(data => {
        if(el.value=='Active'){
            el.classList.remove('status-inactive');
            el.classList.add('status-active');
        }else{
            el.classList.remove('status-active');
            el.classList.add('status-inactive');
        }
    });
}
/* DELETE */
let deleteId = null;
function deleteItem(id){
    deleteId = id;
    document.getElementById('deleteConfirm').style.display='flex';
}
function closeDelete(){
    deleteId = null;
    document.getElementById('deleteConfirm').style.display='none';
}
document.getElementById('confirmDeleteBtn').onclick = function(){
    if(deleteId){
        window.location.href =
        'delete_item.php?id='+deleteId;
    }
};
/* ESC CLOSE */
document.addEventListener('keydown',function(e){
    if(e.key === 'Escape'){
        closeDelete();
    }
});
/* OUTSIDE CLICK */
window.onclick = function(event){
    let modal = document.getElementById('deleteConfirm');
    if(event.target == modal){
        closeDelete();
    }
};
</script>
</body>
</html>