<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
/* LOGIN */
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php'); exit;
}
/* ADD PERMISSION */
if (!checkPermission('empanelment_master','add')) {
    header('Location: /access_denied.php'); exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Create Empanelment</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/style.css">
<!-- FAVICON -->
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<style>
.container{max-width:95%;margin:auto;padding:15px}
.header-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:15px}
.card{background:#fff;border:1px solid #ddd;border-radius:6px;padding:15px}
label{font-weight:600;font-size:13px;margin-top:10px;display:block}
input,select{width:100%;padding:7px;border:1px solid #ccc;border-radius:4px}
table{width:100%;border-collapse:collapse;margin-top:10px}
th,td{border:1px solid #ddd;padding:6px;font-size:13px}
th{background:#f1f1f1}
.btn{padding:6px 12px;font-size:13px;border-radius:4px;text-decoration:none;cursor:pointer}
.btn-success{background:#28a745;color:#fff}
.btn-primary{background:#007bff;color:#fff}
.btn-danger{background:#dc3545;color:#fff}
.btn-sm{padding:4px 8px;font-size:12px}
.actions{margin-top:15px;text-align:right}
</style>
</head>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<body>
<div class="container">
<div class="header-bar">
    <h2>Create Empanelment</h2>
    <a href="index.php" class="btn btn-danger">Back</a>
</div>
<div class="card">
<form method="post" action="save.php" onsubmit="return validateForm();">
<label>Empanelment Name <span style="color:red">*</span></label>
<input type="text" name="empanelment_name" required>
<label>Start Date <span style="color:red">*</span></label>
<input type="date" name="start_date" required>
<label>End Date <span style="color:red">*</span></label>
<input type="date" name="end_date" required>
<label>Payment Mode <span style="color:red">*</span></label>
<select name="payment_mode" required>
    <option value="">Select</option>
    <option value="Cash">Cash</option>
    <option value="Credit">Credit</option>
</select>
<label>Consultation Discount Type <span style="color:red">*</span></label>
<select name="consultation_discount_type" required>
    <option value="Amount">Amount</option>
    <option value="Percentage">Percentage</option>
</select>
<label>Consultation Discount Value <span style="color:red">*</span></label>
<input type="number" step="0.01" name="consultation_discount_value" required>
<hr>
<label>Status <span style="color:red">*</span></label>
<select name="status" required>
    <option value="1" selected>Active</option>
    <option value="0">Inactive</option>
</select>
<button type="button" class="btn btn-primary" onclick="openServicePopup()">
    + Select Services
</button>
<table id="serviceTable">
<tr>
    <th>Service Code</th>
    <th>Service Name</th>
    <th>Service Amount</th>
    <th>Emp Amount</th>
    <th>Remove</th>
</tr>
</table>
<div class="actions">
    <button type="submit" class="btn btn-success">Save Empanelment</button>
</div>
</form>
</div>
</div>
<script src="assets/emph.js"></script>
</body>
</html>