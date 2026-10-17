<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
date_default_timezone_set('Asia/Kolkata');

if (!isset($_SESSION['user_id'])) {
    header("Location: /login/login.php");
    exit;
}

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

/* ===============================
   FILTER
=============================== */
$from   = $_GET['from'] ?? date('Y-m-d');
$to     = $_GET['to'] ?? date('Y-m-d');
$type   = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : '';
$category = $_GET['category'] ?? '';
$entity = $_GET['entity_id'] ?? '';
$status = $_GET['status'] ?? '';

$where = "WHERE a.appointment_date BETWEEN '$from' AND '$to'";

if (!empty($type)) {
    $where .= " AND LOWER(a.type) = '$type'";
}
if (!empty($entity)) {
    $where .= " AND a.entity_id = '$entity'";
}
if (!empty($status)) {
    $where .= " AND a.status = '$status'";
}
if (!empty($category)) {
    if ($type === 'doctor') {
        $where .= " AND a.department_id = '$category'";
    }
    if ($type === 'service') {
        $where .= " AND sm.category_type = '$category'";
    }
}

/* ===============================
   DETAILED QUERY
=============================== */
$detail_sql = "
SELECT
    a.*,
    dm.department_name,
    sm.category_type,
    IF(
        a.type = 'doctor',
        doc.full_name,
        sm.service_name
    ) AS entity_name
FROM appointments a
LEFT JOIN department_master dm 
    ON dm.id = a.department_id
LEFT JOIN users doc 
    ON doc.id = a.entity_id AND a.type = 'doctor'
LEFT JOIN service_master sm 
    ON sm.id = a.entity_id AND a.type = 'service'
$where
ORDER BY a.created_at DESC
";

$detail_res = mysqli_query($conn, $detail_sql);
?>
<!DOCTYPE html>
<html>
<head>
<title>Detailed Appointment Report</title>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.table-wrap { overflow:auto; }
table { width:100%; border-collapse:collapse; font-size:13px; }
th, td { border:1px solid #ddd; padding:8px; }
th { background:#1976d2; color:#fff; }
input:not([type="checkbox"]), select { width: 10%; }
</style>
</head>
<body>

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>

<div class="main-content" style="width:auto;">
<div class="report-container">

<h2>📄 Detailed Appointment Report</h2>

<form method="GET" style="margin-bottom:20px;">
From: <input type="date" name="from" value="<?= $from ?>">
To: <input type="date" name="to" value="<?= $to ?>">

Type:
<select name="type">
    <option value="">All</option>
    <option value="doctor" <?= $type==='doctor'?'selected':'' ?>>Doctor</option>
    <option value="service" <?= $type==='service'?'selected':'' ?>>Service</option>
</select>

Category:
<select name="category">
<option value="">All</option>
<?php
if ($type === 'doctor') {
    $cats = mysqli_query($conn,"SELECT id, department_name FROM department_master");
    while ($c = mysqli_fetch_assoc($cats)) {
        echo "<option value='{$c['id']}' ".($category==$c['id']?'selected':'').">{$c['department_name']}</option>";
    }
}
elseif ($type === 'service') {
    $cats = mysqli_query($conn,"SELECT DISTINCT category_type FROM service_master");
    while ($c = mysqli_fetch_assoc($cats)) {
        echo "<option value='{$c['category_type']}' ".($category==$c['category_type']?'selected':'').">{$c['category_type']}</option>";
    }
}
?>
</select>

Entity:
<select name="entity_id">
<option value="">All</option>
<?php
if ($type === 'doctor') {
    $ents = mysqli_query($conn,"SELECT id, full_name FROM users WHERE employee_type_id='2'");
    while ($e = mysqli_fetch_assoc($ents)) {
        echo "<option value='{$e['id']}' ".($entity==$e['id']?'selected':'').">{$e['full_name']}</option>";
    }
}
elseif ($type === 'service') {
    $ents = mysqli_query($conn,"SELECT id, service_name FROM service_master");
    while ($e = mysqli_fetch_assoc($ents)) {
        echo "<option value='{$e['id']}' ".($entity==$e['id']?'selected':'').">{$e['service_name']}</option>";
    }
}
?>
</select>

Status:
<select name="status">
<option value="">All</option>
<?php foreach([
    'Payment Pending',
    'Confirmed',
    'Registered',
    'Prescription',
    'Done',
    'Refund',
    'Cancel'
] as $s): ?>
<option <?= $status==$s?'selected':'' ?>><?= $s ?></option>
<?php endforeach; ?>
</select>

<button type="submit">Filter</button>
</form>

<div class="table-wrap">
<table>
<tr>
<th>ID</th>
<th>Code</th>
<th>Type</th>
<th>Department</th>
<th>Doctor / Service</th>
<th>Date</th>
<th>Time</th>
<th>Status</th>
<th>Fee</th>
<th>Paid</th>
<th>Balance</th>
</tr>

<?php while($r = mysqli_fetch_assoc($detail_res)): ?>
<tr>
<td><?= $r['id'] ?></td>
<td><?= $r['appointment_code'] ?></td>
<td><?= ucfirst($r['type']) ?></td>
<td><?= $r['department_name'] ?></td>
<td><?= $r['entity_name'] ?></td>
<td><?= $r['appointment_date'] ?></td>
<td><?= $r['appointment_time'] ?></td>
<td><?= $r['status'] ?></td>
<td>₹ <?= number_format($r['consultation_fee'],2) ?></td>
<td>₹ <?= number_format($r['paid_amount'],2) ?></td>
<td>₹ <?= number_format($r['balance_amount'],2) ?></td>
</tr>
<?php endwhile; ?>

</table>
</div>

</div>
</div>
</body>
</html>
