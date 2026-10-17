<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if (!isset($_SESSION['user_id'])) {
    header('Location:/index.php');
    exit;
}
$filter =
isset($_GET['status'])
? $_GET['status']
: 'All';

$countQuery = $conn->query("
SELECT
COUNT(*) AS total,
SUM(
CASE
WHEN status='Active'
THEN 1 ELSE 0
END
) AS active_count,
SUM(
CASE
WHEN status='Blocked'
THEN 1 ELSE 0
END
) AS blocked_count,
SUM(
CASE
WHEN status='Damaged'
THEN 1 ELSE 0
END
) AS damaged_count,
SUM(
CASE
WHEN exp_date IS NOT NULL
AND exp_date < CURDATE()
THEN 1 ELSE 0
END
) AS expired_count
FROM inventory_batches
WHERE qty_available > 0
AND mrn_status='Inspection Approved'
");
$countData =
$countQuery->fetch_assoc();
$whereFilter = "";

if($filter == 'Active'){

    $whereFilter =
    " AND ib.status='Active'
      AND (
        ib.exp_date IS NULL
        OR ib.exp_date >= CURDATE()
      )";

}

else if($filter == 'Blocked'){

    $whereFilter =
    " AND ib.status='Blocked'";

}

else if($filter == 'Damaged'){

    $whereFilter =
    " AND ib.status='Damaged'";

}

else if($filter == 'Expired'){

    $whereFilter =
    " AND ib.exp_date IS NOT NULL
      AND ib.exp_date < CURDATE()";

}
$data = $conn->query("
SELECT
ib.*,
CASE
WHEN ib.exp_date IS NOT NULL
AND ib.exp_date < CURDATE()
THEN 'Expired'
ELSE ib.status
END AS final_status,
im.item_name,
pm.party_name AS supplier_name,
s.store_name,
hm.hospital_name,
bm.branch_name
FROM inventory_batches ib
LEFT JOIN item_master im
ON im.id = ib.item_id
LEFT JOIN party_master pm
ON pm.id = ib.supplier_id
LEFT JOIN stores s
ON s.id = ib.store_id
LEFT JOIN hospital_master hm
ON hm.id = ib.hospital_id
LEFT JOIN branch_master bm
ON bm.id = ib.branch_id
WHERE ib.qty_available > 0
AND ib.mrn_status='Inspection Approved'
$whereFilter
ORDER BY
im.item_name ASC,
ib.exp_date ASC
");
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Live Stock</title>
<meta name="viewport"
content="width=device-width, initial-scale=1">
<link rel="stylesheet"
href="/assets/css/style.css">
<link rel="icon"
type="image/png"
href="/assets/img/hms_logo.png">
<style>
.page-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
}
.stock-good{
    background:green;
    color:#fff;
    padding:4px 8px;
    border-radius:5px;
    font-size:12px;
}
.stock-low{
    background:#ff9800;
    color:#fff;
    padding:4px 8px;
    border-radius:5px;
    font-size:12px;
}
.stock-expiry{
    background:red;
    color:#fff;
    padding:4px 8px;
    border-radius:5px;
    font-size:12px;
}
</style>
</head>
<body>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>
<div class="main-content"
id="mainContent"
style="width:auto;">
<div class="page-container">
<div class="page-header">
<h2>📦 Live Stock</h2>
<div style="
display:flex;
gap:10px;
flex-wrap:wrap;
margin-top:15px;
">

<a href="?status=All"
class="btn">

All
(
<?= $countData['total'] ?>
)

</a>

<a href="?status=Active"
class="btn"
style="background:green;">

Active
(
<?= $countData['active_count'] ?>
)

</a>

<a href="?status=Expired"
class="btn"
style="background:red;">

Expired
(
<?= $countData['expired_count'] ?>
)

</a>

<a href="?status=Blocked"
class="btn"
style="background:#ff9800;">

Blocked
(
<?= $countData['blocked_count'] ?>
)

</a>

<a href="?status=Damaged"
class="btn"
style="background:#6f42c1;">

Damaged
(
<?= $countData['damaged_count'] ?>
)

</a>

</div>
</div>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>ID</th>
<th>Item</th>
<th>Supplier</th>
<th>Hospital</th>
<th>Branch</th>
<th>Store</th>
<th>Batch No</th>
<th>Barcode</th>
<th>Qty Available</th>
<th>MRP</th>
<th>Trade Rate</th>
<th>GST %</th>
<th>Mfg Date</th>
<th>Expiry Date</th>
<th>Manufacturer</th>
<th>Status</th>
<th>MRN Status</th>
</tr>
</thead>
<tbody>
<?php while($row = $data->fetch_assoc()) {
$today = date('Y-m-d');
$days = 9999;
if(!empty($row['exp_date'])){
    $days =
    (
        strtotime($row['exp_date'])
        -
        strtotime($today)
    )
    /
    (60*60*24);
}
?>
<tr style="
<?php
if($row['final_status']=='Active'){
    echo 'background:#edfced;';
}
else if($row['final_status']=='Expired'){
    echo 'background:#ffe5e5;';
}
else if($row['final_status']=='Blocked'){
    echo 'background:#fff4e5;';
}
else if($row['final_status']=='Damaged'){
    echo 'background:#f3e5ff;';
}
?>
">
<td><?= $row['id'] ?></td>
<td>
<?= htmlspecialchars($row['item_name']) ?>
</td>
<td>
<?= htmlspecialchars($row['supplier_name']) ?>
</td>
<td>
<?= htmlspecialchars($row['hospital_name']) ?>
</td>
<td>
<?= htmlspecialchars($row['branch_name']) ?>
</td>
<td>
<?= htmlspecialchars($row['store_name']) ?>
</td>
<td>
<?= htmlspecialchars($row['batch_no']) ?>
</td>
<td>
<?= htmlspecialchars($row['barcode_no']) ?>
</td>
<td>
<?php if($row['qty_available'] <= 10){ ?>
<span class="stock-low">
<?= $row['qty_available'] ?>
</span>
<?php } else { ?>
<span class="stock-good">
<?= $row['qty_available'] ?>
</span>
<?php } ?>
</td>
<td>
<?= $row['mrp'] ?>
</td>
<td>
<?= $row['trade_rate'] ?>
</td>
<td>
<?= $row['gst_percent'] ?>%
</td>
<td>
<?= $row['mfg_date'] ?>
</td>
<td>
<?php if($days <= 30){ ?>
<span class="stock-expiry">
<?= $row['exp_date'] ?>
</span>
<?php } else { ?>
<?= $row['exp_date'] ?>
<?php } ?>
</td>
<td>
<?= htmlspecialchars($row['manufacturer_name']) ?>
</td>
<td>
<?php if($row['final_status']=='Expired'){ ?>
<span class="stock-expiry">
Expired
</span>
<?php } else { ?>
<form
method="POST"
action="update_stock_status.php">
<input
type="hidden"
name="id"
value="<?= $row['id'] ?>">
<select
name="status"
onchange="this.form.submit()"
style="
padding:5px;
border-radius:5px;
">
<option value="Active"
<?= $row['final_status']=='Active' ? 'selected' : '' ?>>
Active
</option>
<option value="Blocked"
<?= $row['final_status']=='Blocked' ? 'selected' : '' ?>>
Blocked
</option>
<option value="Damaged"
<?= $row['final_status']=='Damaged' ? 'selected' : '' ?>>
Damaged
</option>
</select>
</form>
<?php } ?>
</td>
<td>
<?php
$statusColor='#6c757d';
if($row['mrn_status']=='MRN Submitted'){
$statusColor='#17a2b8';
}
else if($row['mrn_status']=='Inspection Pending'){
$statusColor='#ffc107';
}
else if($row['mrn_status']=='Inspection Done'){
$statusColor='#fd7e14';
}
else if($row['mrn_status']=='Inspection Approved'){
$statusColor='#28a745';
}
?>
<span style="
background:<?= $statusColor ?>;
color:#fff;
padding:5px 10px;
border-radius:20px;
font-size:12px;
font-weight:bold;
">
<?= $row['mrn_status'] ?>
</span>
</td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
</div>
</div>
</body>
</html>