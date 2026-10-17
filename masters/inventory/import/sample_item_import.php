<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if (!isset($_SESSION['user_id'])) {
    exit;
}
/* =========================
HEADERS
========================= */
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=item_import_sample.xls");
/* =========================
MASTER VALUES
========================= */
$categories = [];
$res = $conn->query("
SELECT category_name
FROM category_master
WHERE parent_id=0
ORDER BY category_name ASC
");
while($r = $res->fetch_assoc()){
    $categories[] = $r['category_name'];
}
/* SUB CATEGORY */
$subcategories = [];
$res = $conn->query("
SELECT category_name
FROM category_master
WHERE parent_id!=0
ORDER BY category_name ASC
");
while($r = $res->fetch_assoc()){
    $subcategories[] = $r['category_name'];
}
/* STORES */
$stores = [];
$res = $conn->query("
SELECT store_name
FROM stores
ORDER BY store_name ASC
");
while($r = $res->fetch_assoc()){
    $stores[] = $r['store_name'];
}
/* SALTS */
$salts = [];
$res = $conn->query("
SELECT salt_name
FROM salts_master
ORDER BY salt_name ASC
");
while($r = $res->fetch_assoc()){
    $salts[] = $r['salt_name'];
}
/* PARTIES */
$parties = [];
$res = $conn->query("
SELECT party_name
FROM party_master
ORDER BY party_name ASC
");
while($r = $res->fetch_assoc()){
    $parties[] = $r['party_name'];
}
/* HSN */
$hsn = [];
$res = $conn->query("
SELECT hsn_code
FROM hsn_master
ORDER BY hsn_code ASC
");
while($r = $res->fetch_assoc()){
    $hsn[] = $r['hsn_code'];
}
?>
<html>
<head>
<meta charset="UTF-8">
<style>
table{
    border-collapse:collapse;
}
th,td{
    border:1px solid #000;
    padding:8px;
}
th{
    background:#d9edf7;
    font-weight:bold;
}
.note{
    background:#fff3cd;
}
</style>
</head>
<body>
<h2>Item Master Import Sample</h2>
<table>
<tr>
<th>Item Name *</th>
<th>Category</th>
<th>Sub Category</th>
<th>Item Group</th>
<th>Item Type</th>
<th>Stores</th>
<th>Packing</th>
<th>Max Level</th>
<th>Min Level</th>
<th>Reorder Level</th>
<th>Salts</th>
<th>Manufacturer</th>
<th>Vendor</th>
<th>HSN Code *</th>
<th>Risk Type</th>
<th>High Risk</th>
<th>Discount %</th>
<th>Discount Wef Date</th>
<th>Status</th>
</tr>
<tr>
<td>Paracetamol 500</td>
<td><?= $categories[0] ?? '' ?></td>
<td><?= $subcategories[0] ?? '' ?></td>
<td>Medicine</td>
<td>Non Capex</td>
<td><?= $stores[0] ?? '' ?></td>
<td>10</td>
<td>100</td>
<td>20</td>
<td>30</td>
<td><?= $salts[0] ?? '' ?></td>
<td><?= $parties[0] ?? '' ?></td>
<td><?= $parties[0] ?? '' ?></td>
<td><?= $hsn[0] ?? '' ?></td>
<td>H1</td>
<td>Yes</td>
<td>5</td>
<td>2026-05-20</td>
<td>Active</td>
</tr>
</table>
<br><br>
<table>
<tr>
<th colspan="2">Allowed Values Reference</th>
</tr>
<tr>
<td><b>Item Group</b></td>
<td>Medicine, Equipment, Other</td>
</tr>
<tr>
<td><b>Item Type</b></td>
<td>Capex, Non Capex</td>
</tr>
<tr>
<td><b>Risk Type</b></td>
<td>H1, H, X1</td>
</tr>
<tr>
<td><b>High Risk</b></td>
<td>Yes, No</td>
</tr>
<tr>
<td><b>Status</b></td>
<td>Active, Inactive</td>
</tr>
</table>
<br><br>
<table>
<tr>
<th>Available Categories</th>
</tr>
<?php foreach($categories as $v){ ?>
<tr>
<td><?= $v ?></td>
</tr>
<?php } ?>
</table>
<br><br>
<table>
<tr>
<th>Available Sub Categories</th>
</tr>
<?php foreach($subcategories as $v){ ?>
<tr>
<td><?= $v ?></td>
</tr>
<?php } ?>
</table>
<br><br>
<table>
<tr>
<th>Available Stores</th>
</tr>
<?php foreach($stores as $v){ ?>
<tr>
<td><?= $v ?></td>
</tr>
<?php } ?>
</table>
<br><br>
<table>
<tr>
<th>Available Salts</th>
</tr>
<?php foreach($salts as $v){ ?>
<tr>
<td><?= $v ?></td>
</tr>
<?php } ?>
</table>
<br><br>
<table>
<tr>
<th>Available Parties</th>
</tr>
<?php foreach($parties as $v){ ?>
<tr>
<td><?= $v ?></td>
</tr>
<?php } ?>
</table>
<br><br>
<table>
<tr>
<th>Available HSN Codes</th>
</tr>
<?php foreach($hsn as $v){ ?>
<tr>
<td><?= $v ?></td>
</tr>
<?php } ?>
</table>
</body>
</html>