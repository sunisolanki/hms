<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if(!isset($_SESSION['user_id'])){
    header('Location:/index.php');
    exit;
}
if(!checkPermission('ipd','view')){
    header('Location:/access_denied.php');
    exit;
}
/* ===============================
   FILTERS
   =============================== */
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$admission_type = trim($_GET['admission_type'] ?? '');
$payment_type = trim($_GET['payment_type'] ?? '');
$from_date = trim($_GET['from_date'] ?? '');
$to_date = trim($_GET['to_date'] ?? '');
/* ===============================
   MAIN QUERY
   =============================== */
$sql = "
SELECT
    ia.id,
    ia.patient_id,
    ia.ipd_no,
    ia.file_no,
    ia.hospital_id,
    ia.branch_id,
    ia.doctor_id,
    ia.department_id,
    ia.admission_date,
    ia.admission_time,
    ia.admission_type,
    ia.status,
    ia.credit_limit,
    ia.initial_deposit,
    ia.payment_type,
    ia.is_emergency,
    ia.is_vip,
    ia.is_mlc,
    ia.mlc_type,
    ia.fir_no,
    pm.uhid,
    pm.title,
    pm.first_name,
    pm.last_name,
    pm.sex,
    pm.guardian_name,
    pm.mobile,
    pm.phone,
    pm.dob,
    pm.age_y,
    pm.age_m,
    pm.age_d,
    pm.address,
h.hospital_name,
br.branch_name,
bl.building_name,
fl.floor_name,
rm.room_number,
rm.room_name,
bd.bed_number,
ira.allocated_date AS bed_allocated_date
FROM ipd_admissions ia
INNER JOIN patient_master pm ON pm.id = ia.patient_id
LEFT JOIN ipd_room_allocation ira
    ON ira.admission_id = ia.id
    AND ira.status = 'Occupied'
LEFT JOIN building_master bl
    ON bl.id = ira.building_id
LEFT JOIN floor_master fl
    ON fl.id = ira.floor_id
LEFT JOIN room_master rm
    ON rm.id = ira.room_id
LEFT JOIN bed_master bd
    ON bd.id = ira.bed_id
LEFT JOIN hospital_master h
    ON h.id = ia.hospital_id
LEFT JOIN branch_master br
    ON br.id = ia.branch_id
WHERE 1=1
";
$params = [];
$types = "";
if($search !== ''){
    $sql .= " AND (
        ia.ipd_no LIKE ?
        OR ia.file_no LIKE ?
        OR CAST(pm.uhid AS CHAR) LIKE ?
        OR pm.first_name LIKE ?
        OR pm.last_name LIKE ?
        OR CONCAT_WS(' ',pm.first_name,pm.last_name) LIKE ?
        OR pm.mobile LIKE ?
        OR pm.phone LIKE ?
        OR pm.guardian_name LIKE ?
    )";
    $like = "%".$search."%";
    for($i=0;$i<9;$i++){
        $params[] = $like;
        $types .= "s";
    }
}
if($status !== ''){
    $sql .= " AND ia.status = ?";
    $params[] = $status;
    $types .= "s";
}
if($admission_type !== ''){
    $sql .= " AND ia.admission_type = ?";
    $params[] = $admission_type;
    $types .= "s";
}
if($payment_type !== ''){
    $sql .= " AND ia.payment_type = ?";
    $params[] = $payment_type;
    $types .= "s";
}
if($from_date !== ''){
    $sql .= " AND ia.admission_date >= ?";
    $params[] = $from_date;
    $types .= "s";
}
if($to_date !== ''){
    $sql .= " AND ia.admission_date <= ?";
    $params[] = $to_date;
    $types .= "s";
}
$sql .= " ORDER BY
CASE ia.status
    WHEN 'Admitted' THEN 1
    WHEN 'Transferred' THEN 2
    WHEN 'Discharged' THEN 3
    WHEN 'Cancelled' THEN 4
    ELSE 5
END,
ia.admission_date DESC,
ia.admission_time DESC,
ia.id DESC";
$stmt = $conn->prepare($sql);
if(!$stmt){
    die("Query Prepare Error: ".$conn->error);
}
if(!empty($params)){
    $stmt->bind_param($types,...$params);
}
$stmt->execute();
$result = $stmt->get_result();
/* ===============================
   COUNTS
   =============================== */
$countSql = "
SELECT
COUNT(*) AS total,
SUM(status='Admitted') AS admitted,
SUM(status='Transferred') AS transferred,
SUM(status='Discharged') AS discharged,
SUM(status='Cancelled') AS cancelled
FROM ipd_admissions
";
$countResult = $conn->query($countSql);
$counts = $countResult->fetch_assoc();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Patient Finder</title>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<style>
.patient-finder-page{
    padding:18px;
}
.page-top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
    margin-bottom:15px;
}
.page-top h2{
    margin:0;
}
.breadcrumb{
    display:flex;
    gap:7px;
    align-items:center;
}
.breadcrumb a{
    text-decoration:none;
}
.summary-grid{
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:12px;
    margin-bottom:15px;
}
.summary-card{
    padding:13px 15px;
    border:1px solid #ddd;
    border-radius:8px;
}
.summary-card .label{
    font-size:12px;
    margin-bottom:5px;
}
.summary-card .number{
    font-size:22px;
    font-weight:700;
}
.summary-total{
    background:#f5f7fa;
}
.summary-admitted{
    background:#e8f5e9;
}
.summary-transferred{
    background:#fff8e1;
}
.summary-discharged{
    background:#e3f2fd;
}
.summary-cancelled{
    background:#fdecea;
}
.filter-box{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    align-items:center;
    margin-bottom:15px;
}
.filter-box input,
.filter-box select{
    padding:7px 9px;
    border:1px solid #ccc;
    border-radius:6px;
    background:#fff;
    width: auto;
}
.main-content
{
        margin-left: 240px;
    padding: 1px;
    width: auto;
    transition: margin-left .3s;
}
.search-input{
    min-width:240px;
}
.btn-filter{
    border:0;
    border-radius:6px;
    padding:8px 14px;
    background:#2c7be5;
    color:#fff;
    cursor:pointer;
}
.btn-reset{
    border:0;
    border-radius:6px;
    padding:8px 14px;
    background:#6c757d;
    color:#fff;
    cursor:pointer;
    text-decoration:none;
}
.table-wrap{
    overflow-x:auto;
}
.patient-table{
    width:100%;
    border-collapse:collapse;
    background:#fff;
}
.patient-table th,
.patient-table td{
    padding:9px 8px;
    border:1px solid #ddd;
    white-space:nowrap;
    vertical-align:middle;
}
.patient-table th{
    background:#f3f5f8;
    font-weight:600;
}
.patient-row-admitted{
    background:#effaf1;
}
.patient-row-transferred{
    background:#fff9e8;
}
.patient-row-discharged{
    background:#edf7ff;
}
.patient-row-cancelled{
    background:#fff0f0;
}
.patient-table tbody tr:hover{
    filter:brightness(.98);
}
.patient-name{
    font-weight:600;
}
.ipd-no{
    font-weight:700;
}
.location-box{
    line-height:1.5;
    min-width:180px;
}
.location-box strong{
    font-weight:700;
}
.location-pending{
    display:inline-block;
    padding:4px 8px;
    border-radius:12px;
    background:#f1f3f5;
    color:#6c757d;
    font-size:11px;
    font-weight:600;
}
.status-badge,
.flag-badge{
    display:inline-block;
    padding:4px 8px;
    border-radius:12px;
    font-size:11px;
    font-weight:600;
}
.status-admitted{
    background:#d4edda;
    color:#155724;
}
.status-transferred{
    background:#fff3cd;
    color:#856404;
}
.status-discharged{
    background:#d1ecf1;
    color:#0c5460;
}
.status-cancelled{
    background:#f8d7da;
    color:#721c24;
}
.flag-emergency{
    background:#ffe0e0;
    color:#b71c1c;
}
.flag-vip{
    background:#ede7f6;
    color:#512da8;
}
.flag-mlc{
    background:#fff3e0;
    color:#e65100;
}
.payment-cash{
    color:#2e7d32;
    font-weight:600;
}
.payment-credit{
    color:#c62828;
    font-weight:600;
}
.no-data{
    padding:30px !important;
    text-align:center;
    color:#777;
}
@media(max-width:1000px){
    .summary-grid{
        grid-template-columns:repeat(2,1fr);
    }
}
</style>
</head>
<body>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>
<div class="main-content">
<div class="patient-finder-page">
<div class="page-top">
<div class="breadcrumb">
<a href="/dashboard/index.php">Home</a>
<span>&gt;</span>
<span>IPD</span>
<span>&gt;</span>
<span>Patient Finder</span>
</div>
<h2>Patient Finder</h2>
<div></div>
</div>
<div class="summary-grid">
<div class="summary-card summary-total">
<div class="label">Total IPD</div>
<div class="number"><?= (int)($counts['total'] ?? 0) ?></div>
</div>
<div class="summary-card summary-admitted">
<div class="label">Admitted</div>
<div class="number"><?= (int)($counts['admitted'] ?? 0) ?></div>
</div>
<div class="summary-card summary-transferred">
<div class="label">Transferred</div>
<div class="number"><?= (int)($counts['transferred'] ?? 0) ?></div>
</div>
<div class="summary-card summary-discharged">
<div class="label">Discharged</div>
<div class="number"><?= (int)($counts['discharged'] ?? 0) ?></div>
</div>
<div class="summary-card summary-cancelled">
<div class="label">Cancelled</div>
<div class="number"><?= (int)($counts['cancelled'] ?? 0) ?></div>
</div>
</div>
<form method="get" class="filter-box">
<input type="text" name="search" class="search-input" value="<?= htmlspecialchars($search) ?>" placeholder="IPD No / UHID / Name / Mobile / File No">
<select name="status">
<option value="">All Status</option>
<option value="Admitted" <?= $status==='Admitted'?'selected':'' ?>>Admitted</option>
<option value="Transferred" <?= $status==='Transferred'?'selected':'' ?>>Transferred</option>
<option value="Discharged" <?= $status==='Discharged'?'selected':'' ?>>Discharged</option>
<option value="Cancelled" <?= $status==='Cancelled'?'selected':'' ?>>Cancelled</option>
</select>
<select name="admission_type">
<option value="">Admission Type</option>
<option value="Emergency" <?= $admission_type==='Emergency'?'selected':'' ?>>Emergency</option>
<option value="Routine" <?= $admission_type==='Routine'?'selected':'' ?>>Routine</option>
<option value="Referral" <?= $admission_type==='Referral'?'selected':'' ?>>Referral</option>
<option value="Transfer" <?= $admission_type==='Transfer'?'selected':'' ?>>Transfer</option>
</select>
<select name="payment_type">
<option value="">Payment Type</option>
<option value="Cash" <?= $payment_type==='Cash'?'selected':'' ?>>Cash</option>
<option value="Credit" <?= $payment_type==='Credit'?'selected':'' ?>>Credit</option>
</select>
<input type="date" name="from_date" value="<?= htmlspecialchars($from_date) ?>" title="From Date">
<input type="date" name="to_date" value="<?= htmlspecialchars($to_date) ?>" title="To Date">
<button type="submit" class="btn-filter">Filter</button>
<a href="/modules/ipd/patient_finder.php" class="btn-reset">Reset</a>
</form>
<div class="table-wrap">
<table class="patient-table">
<thead>
<tr>
<th>#</th>
<th>IPD No.</th>
<th>UHID</th>
<th>Patient</th>
<th>Age / Sex</th>
<th>Mobile</th>
<th>Guardian</th>
<th>Admission</th>
<th>Location</th>
<th>Type</th>
<th>Payment</th>
<th>Deposit</th>
<th>Flags</th>
<th>Status</th>
</tr>
</thead>
<tbody>
<?php
$sr = 1;
if($result->num_rows > 0):
while($row = $result->fetch_assoc()):
$rowClass = '';
if($row['status']==='Admitted'){
    $rowClass='patient-row-admitted';
}elseif($row['status']==='Transferred'){
    $rowClass='patient-row-transferred';
}elseif($row['status']==='Discharged'){
    $rowClass='patient-row-discharged';
}elseif($row['status']==='Cancelled'){
    $rowClass='patient-row-cancelled';
}
$fullName = trim(
    ($row['title'] ?? '').' '.
    ($row['first_name'] ?? '').' '.
    ($row['last_name'] ?? '')
);
$ageParts = [];
if((int)$row['age_y'] > 0){
    $ageParts[] = (int)$row['age_y'].'Y';
}
if((int)$row['age_m'] > 0){
    $ageParts[] = (int)$row['age_m'].'M';
}
if((int)$row['age_d'] > 0){
    $ageParts[] = (int)$row['age_d'].'D';
}
$age = !empty($ageParts) ? implode(' ', $ageParts) : '-';
?>
<tr class="<?= $rowClass ?>">
<td><?= $sr++ ?></td>
<td class="ipd-no"><?= htmlspecialchars($row['ipd_no']) ?></td>
<td><?= htmlspecialchars((string)$row['uhid']) ?></td>
<td class="patient-name"><?= htmlspecialchars($fullName ?: '-') ?></td>
<td><?= htmlspecialchars($age) ?> / <?= htmlspecialchars($row['sex'] ?: '-') ?></td>
<td><?= htmlspecialchars($row['mobile'] ?: '-') ?></td>
<td><?= htmlspecialchars($row['guardian_name'] ?: '-') ?></td>
<td>
<?= date('d-M-Y',strtotime($row['admission_date'])) ?>
<br>
<small><?= date('h:i A',strtotime($row['admission_time'])) ?></small>
</td>
<td>
<?php if(!empty($row['bed_number'])): ?>
<div class="location-box">
    <strong><?= htmlspecialchars($row['hospital_name'] ?? '-') ?></strong>
    <div><?= htmlspecialchars($row['branch_name'] ?? '-') ?></div>
    <div>
        <?= htmlspecialchars($row['building_name'] ?? '-') ?>
        →
        <?= htmlspecialchars($row['floor_name'] ?? '-') ?>
    </div>
    <div>
        Room: <strong><?= htmlspecialchars($row['room_number'] ?? '-') ?></strong>
        <?php if(!empty($row['room_name'])): ?>
            (<?= htmlspecialchars($row['room_name']) ?>)
        <?php endif; ?>
    </div>
    <div>
        Bed: <strong><?= htmlspecialchars($row['bed_number']) ?></strong>
    </div>
</div>
<?php else: ?>
<span class="location-pending">Not Allocated</span>
<?php endif; ?>
</td>
<td><?= htmlspecialchars($row['admission_type'] ?: '-') ?></td>
<td class="<?= $row['payment_type']==='Credit'?'payment-credit':'payment-cash' ?>">
<?= htmlspecialchars($row['payment_type']) ?>
</td>
<td>₹<?= number_format((float)$row['initial_deposit'],2) ?></td>
<td>
<?php if((int)$row['is_emergency']===1): ?>
<span class="flag-badge flag-emergency">Emergency</span>
<?php endif; ?>
<?php if((int)$row['is_vip']===1): ?>
<span class="flag-badge flag-vip">VIP</span>
<?php endif; ?>
<?php if((int)$row['is_mlc']===1): ?>
<span class="flag-badge flag-mlc">MLC</span>
<?php endif; ?>
<?php if(!(int)$row['is_emergency'] && !(int)$row['is_vip'] && !(int)$row['is_mlc']): ?>
-
<?php endif; ?>
</td>
<td>
<span class="status-badge status-<?= strtolower($row['status']) ?>">
<?= htmlspecialchars($row['status']) ?>
</span>
</td>
</tr>
<?php endwhile; ?>
<?php else: ?>
<tr>
<td colspan="14" class="no-data">No IPD patients found.</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</div>
</body>
</html>