<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if (!isset($_SESSION['user_id'])) {
    header('Location:/index.php');
    exit;
}
?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<!DOCTYPE html>
<html>
<head>
<title>Import Item Master</title>
<link rel="stylesheet"
href="/assets/css/style.css">
<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
.top-bar{
    position:relative;
    display:flex;
    justify-content:center;
    align-items:center;
    margin-bottom:25px;
}
.breadcrumb{
    position:absolute;
    left:0;
}
.import-box{
    border:2px dashed #dcdcdc;
    border-radius:12px;
    margin:40px;
    text-align:center;
    background:#fafafa;
}
.instructions{
    margin-top:25px;
    background:#fff8e1;
    border-left:4px solid #ff9800;
    padding:15px;
    border-radius:8px;
}
.instructions ul{
    margin:0;
    padding-left:20px;
}
.instructions li{
    margin-bottom:8px;
}
.action-buttons{
    display:flex;
    gap:10px;
    justify-content:center;
    flex-wrap:wrap;
}
</style>
</head>
<body>
<div class="import-wrapper">
<div class="top-bar">
<div class="breadcrumb">
<a href="/dashboard/index.php">Home</a>
<span>&gt;</span>
<a href="/masters/masters.php">Masters</a>
<span>&gt;</span>
<a href="/masters/inventory/inventory_master.php">Inventory</a>
<span>&gt;</span>
<a href="/masters/inventory/item_master.php">Item Master</a>
<span>&gt;</span>
<a>Import Items</a>
</div>
<h2>Import Item Master</h2>
</div>
<div class="import-card">
<form method="POST"
action="preview.php"
enctype="multipart/form-data">
<div class="import-box">
<i class="fa-solid fa-file-excel"></i>
<h3>Import Item Master Excel</h3>
<p>
Upload Excel file for bulk item import.
</p>
<div class="action-buttons">
<a href="sample_item_import.php"
class="btn btn-success">
<i class="fa-solid fa-download"></i>
Download Sample Excel
</a>
</div>
<br>
<div class="file-input">
<input type="file"
name="excel_file"
accept=".xls,.xlsx"
required>
</div>
<button type="submit"
class="btn btn-primary">
<i class="fa-solid fa-upload"></i>
Upload & Preview
</button>
<a href="/masters/inventory/item_master.php"
class="btn">
Cancel
</a>
</div>
<div class="instructions">
<h4>
<i class="fa-solid fa-circle-info"></i>
Import Instructions
</h4>
<ul>
<li>Do not change column names in sample file.</li>
<li>Category/Subcategory names must exactly match master values.</li>
<li>Store names must match Store Master.</li>
<li>Salt names must match Salts Master.</li>
<li>HSN code must already exist in HSN Master.</li>
<li>Invalid rows will show red highlight in preview.</li>
<li>You can remove incorrect rows before final import.</li>
<li>Only valid rows will be saved.</li>
</ul>
</div>
</form>
</div>
</div>
</body>
</html>