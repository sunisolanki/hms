
<?php
session_start();
date_default_timezone_set('Asia/Kolkata');
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit;
}
$current_user_id = (int)$_SESSION['user_id'];
$selected_date = date('Y-m-d');
if (!empty($_GET['date'])) {
    $inputDate = trim($_GET['date']);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $inputDate)) {
        $selected_date = $inputDate;
    } elseif (preg_match('/^\d{2}-\d{2}-\d{4}$/', $inputDate)) {
        $parts = explode('-', $inputDate);
        $selected_date = $parts[2].'-'.$parts[1].'-'.$parts[0];
    }
}
$dateObject = DateTime::createFromFormat('!Y-m-d', $selected_date);
if (!$dateObject || $dateObject->format('Y-m-d') !== $selected_date) {
    $selected_date = date('Y-m-d');
}
$prev_date = date('Y-m-d', strtotime($selected_date.' -1 day'));
$next_date = date('Y-m-d', strtotime($selected_date.' +1 day'));
$search = trim($_GET['search'] ?? '');
$searchSql = mysqli_real_escape_string($conn, $search);
$dateSql = mysqli_real_escape_string($conn, $selected_date);
$showAll = isset($_GET['all']);
/*
|--------------------------------------------------------------------------
| PATHOLOGY ENTRIES
|--------------------------------------------------------------------------
| OPD: opd_bills + opd_bill_details
| IPD: ipd_bills + ipd_bill_items
| Direct Registration: appointments where type = service
| Only service_master.category_type = Laboratory
*/
$query = "
SELECT *
FROM (
    /* OPD LABORATORY BILLING */
    SELECT
        CONVERT('OPD' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source_type,
        obd.id AS entry_id,
        CONVERT(COALESCE(NULLIF(CONVERT(b.bill_no USING utf8mb4), ''), CONCAT('OPD-BILL-', b.id)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference_no,
        b.bill_date AS entry_date,
        CONVERT(COALESCE(CONVERT(p.uhid USING utf8mb4), '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS uhid,
        CONVERT(TRIM(CONCAT(
            COALESCE(CONVERT(p.first_name USING utf8mb4), CONVERT(a.first_name USING utf8mb4), ''),
            ' ',
            COALESCE(CONVERT(p.last_name USING utf8mb4), CONVERT(a.last_name USING utf8mb4), '')
        )) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS patient_name,
        CONVERT(COALESCE(CONVERT(p.mobile USING utf8mb4), CONVERT(a.mobile USING utf8mb4), '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS mobile,
        CONVERT(sm.service_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS service_name,
        obd.qty AS quantity,
        obd.charge AS rate,
        obd.net_amount AS amount,
        b.paid_amount AS paid_amount,
        b.balance_amount AS balance_amount,
        CONVERT(b.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_status
    FROM opd_bill_details obd
    INNER JOIN opd_bills b ON b.id = obd.bill_id
    INNER JOIN service_master sm ON sm.id = obd.service_id
    LEFT JOIN appointments a ON a.id = b.opd_id
    LEFT JOIN patient_master p ON p.id = b.patient_id
    WHERE sm.category_type = 'Laboratory'
      AND b.status <> 'Cancelled'
      AND COALESCE(obd.status, 'Active') <> 'Cancelled'

    UNION ALL

    /* IPD LABORATORY BILLING */
    SELECT
        CONVERT('IPD' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source_type,
        ibi.id AS entry_id,
        CONVERT(COALESCE(NULLIF(CONVERT(ib.bill_no USING utf8mb4), ''), CONCAT('IPD-BILL-', ib.id)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference_no,
        COALESCE(ibi.service_date, ib.bill_date) AS entry_date,
        CONVERT(COALESCE(CONVERT(p.uhid USING utf8mb4), '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS uhid,
        CONVERT(TRIM(CONCAT(
            COALESCE(CONVERT(p.first_name USING utf8mb4), ''),
            ' ',
            COALESCE(CONVERT(p.last_name USING utf8mb4), '')
        )) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS patient_name,
        CONVERT(COALESCE(CONVERT(p.mobile USING utf8mb4), '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS mobile,
        CONVERT(sm.service_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS service_name,
        ibi.quantity AS quantity,
        ibi.rate AS rate,
        ibi.net_amount AS amount,
        ib.paid_amount AS paid_amount,
        ib.balance_amount AS balance_amount,
        CONVERT(ib.payment_status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_status
    FROM ipd_bill_items ibi
    INNER JOIN ipd_bills ib ON ib.id = ibi.ipd_bill_id
    INNER JOIN service_master sm ON sm.id = ibi.item_id
    LEFT JOIN patient_master p ON p.id = ib.patient_id
    WHERE ibi.item_type = 'Service'
      AND sm.category_type = 'Laboratory'
      AND ib.payment_status <> 'Cancelled'

    UNION ALL

    /* DIRECT SERVICE REGISTRATION */
    SELECT
        CONVERT('Direct Registration' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source_type,
        a.id AS entry_id,
        CONVERT(COALESCE(NULLIF(CONVERT(a.appointment_code USING utf8mb4), ''), CONCAT('REG-', a.id)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference_no,
        a.appointment_date AS entry_date,
        CONVERT(COALESCE(CONVERT(p.uhid USING utf8mb4), '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS uhid,
        CONVERT(TRIM(CONCAT(
            COALESCE(CONVERT(p.first_name USING utf8mb4), CONVERT(a.first_name USING utf8mb4), ''),
            ' ',
            COALESCE(CONVERT(p.last_name USING utf8mb4), CONVERT(a.last_name USING utf8mb4), '')
        )) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS patient_name,
        CONVERT(COALESCE(CONVERT(p.mobile USING utf8mb4), CONVERT(a.mobile USING utf8mb4), '') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS mobile,
        CONVERT(sm.service_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS service_name,
        1 AS quantity,
        a.consultation_fee AS rate,
        a.consultation_fee AS amount,
        a.paid_amount AS paid_amount,
        a.balance_amount AS balance_amount,
        CONVERT(a.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_status
    FROM appointments a
    INNER JOIN service_master sm ON sm.id = a.entity_id
    LEFT JOIN patient_master p ON p.id = a.patient_id
    WHERE a.type = 'service'
      AND sm.category_type = 'Laboratory'
      AND UPPER(CONVERT(COALESCE(a.status, '') USING utf8mb4)) <> 'CANCELLED'
) AS pathology_entries
WHERE 1 = 1
";
if (!$showAll) {
    $query .= " AND entry_date = '$dateSql' ";
}
if ($search !== '') {
    $query .= "
    AND (
        reference_no LIKE '%$searchSql%'
        OR uhid LIKE '%$searchSql%'
        OR patient_name LIKE '%$searchSql%'
        OR mobile LIKE '%$searchSql%'
        OR service_name LIKE '%$searchSql%'
        OR source_type LIKE '%$searchSql%'
    ) ";
}
$query .= " ORDER BY entry_date DESC, entry_id DESC ";
$result = mysqli_query($conn, $query);
$pathology_rows = [];
while ($row = mysqli_fetch_assoc($result)) {
    $pathology_rows[] = $row;
}
$total_entries = count($pathology_rows);
$total_amount = 0;
foreach ($pathology_rows as $row) {
    $total_amount += (float)$row['amount'];
}
function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
function money($value) {
    return number_format((float)$value, 2);
}
$queryParams = [];
if ($search !== '') {
    $queryParams['search'] = $search;
}
if ($showAll) {
    $queryParams['all'] = '1';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Pathology - Laboratory Entries</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" href="/assets/img/hms_logo.png">
<style>
/* Pathology page layout */
.main-content#mainContent{min-width:0;box-sizing:border-box}
.summary-cards{display:grid;grid-template-columns:repeat(2,minmax(175px,220px));gap:14px;margin:0 0 20px}
.summary-card{background:#fff;border:1px solid #edf0f4;border-radius:11px;padding:15px 18px;box-shadow:0 3px 12px rgba(15,23,42,.06);min-width:0}
.summary-label{font-size:12px;color:#64748b;line-height:1.4}
.summary-value{font-size:23px;font-weight:750;color:#111827;margin-top:6px;line-height:1.2;overflow-wrap:anywhere}
.pathology-toolbar{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin:0 0 16px;padding:14px;background:#fff;border:1px solid #e8edf3;border-radius:10px}
.filter-group{display:flex;align-items:center;gap:8px;flex-wrap:wrap;min-width:0}
.filter-group form{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.pathology-toolbar input[type=date],.search-box{height:39px;box-sizing:border-box;padding:8px 11px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#111827;font:inherit}
.search-box{width:290px;max-width:100%}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;box-sizing:border-box;padding:8px 12px;border:1px solid transparent;border-radius:6px;cursor:pointer;text-decoration:none;font-size:13px;font-weight:600;white-space:nowrap;transition:background .15s ease,transform .15s ease}
.btn:hover{filter:brightness(.97)}
.btn-blue{background:#1976d2;color:#fff}
.btn-gray{background:#eef1f5;color:#273244;border-color:#e1e6ed}
.btn-green{background:#16834a;color:#fff}
.table-wrap{background:#fff;border:1px solid #e5eaf0;border-radius:10px;box-shadow:0 4px 16px rgba(15,23,42,.05);overflow:auto;max-width:100%;max-height:calc(100vh - 355px);min-height:160px}
table{width:100%;min-width:1250px;border-collapse:separate;border-spacing:0;font-size:13px;white-space:nowrap}
th,td{border-right:1px solid #e5eaf0;border-bottom:1px solid #e5eaf0;padding:10px 11px;text-align:left}
th{background:#1976d2;color:#fff;position:sticky;top:0;z-index:2;font-weight:700}
th:first-child,td:first-child{border-left:0}
tbody tr:last-child td{border-bottom:0}
tbody tr:hover{background:#f5f9ff}
.source-badge{display:inline-block;padding:4px 8px;border-radius:5px;font-size:11px;font-weight:700}
.source-opd{background:#dbeafe;color:#1d4ed8}
.source-ipd{background:#dcfce7;color:#166534}
.source-direct{background:#f3e8ff;color:#7e22ce}
.status-badge{display:inline-block;padding:4px 8px;border-radius:5px;background:#f1f5f9;color:#334155;font-weight:600}
.empty-row{text-align:center;padding:24px 16px!important;color:#64748b;white-space:normal;height:70px}
@media(max-width:1100px){.pathology-toolbar{align-items:flex-start}.pathology-toolbar>.filter-group,.pathology-toolbar>form{width:100%}.search-box{flex:1;width:auto;min-width:180px}.table-wrap{max-height:calc(100vh - 390px)}}
@media(max-width:700px){.page-container{padding:14px 10px}.pathology-page-title{font-size:21px;margin-bottom:15px}.summary-cards{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.summary-card{padding:12px}.summary-value{font-size:19px}.pathology-toolbar{padding:10px}.filter-group{width:100%}.pathology-toolbar input[type=date]{flex:1;min-width:145px}.search-box{width:100%;min-width:0}.table-wrap{max-height:60vh}}
</style>
</head>
<body>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>
<div class="main-content" id="mainContent" style="width:auto;">
<div class="page-container">
    <h2 class="pathology-page-title">🧪 Pathology - Laboratory Entries</h2>
    <div class="summary-cards">
        <div class="summary-card">
            <div class="summary-label">Total Entries</div>
            <div class="summary-value"><?= $total_entries ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Total Laboratory Amount</div>
            <div class="summary-value">₹<?= money($total_amount) ?></div>
        </div>
    </div>
    <div class="pathology-toolbar">
        <div class="filter-group">
            <a class="btn btn-gray" href="?<?= h(http_build_query(array_merge($queryParams, ['date'=>$prev_date]))) ?>">❮ </a>
            <form method="GET" style="display:flex;gap:7px;align-items:center">
                <input type="date" name="date" value="<?= h($selected_date) ?>" style="padding:8px;border:1px solid #ccc;border-radius:5px">
                <?php if ($search !== ''): ?>
                    <input type="hidden" name="search" value="<?= h($search) ?>">
                <?php endif; ?>
                <button type="submit" class="btn btn-blue">Show Date</button>
            </form>
            <a class="btn btn-gray" href="?<?= h(http_build_query(array_merge($queryParams, ['date'=>$next_date]))) ?>"> ❯</a>
            <a class="btn <?= !$showAll ? 'btn-green' : 'btn-gray' ?>" href="?<?= h(http_build_query(array_merge($queryParams, ['date'=>$selected_date]))) ?>">Selected Date</a>
            <a class="btn <?= $showAll ? 'btn-green' : 'btn-gray' ?>" href="?<?= h(http_build_query(array_merge($queryParams, ['all'=>'1']))) ?>">All Entries</a>
        </div>
        <form method="GET" class="filter-group">
            <input type="text" name="search" class="search-box" placeholder="Search patient, UHID, bill, service..." value="<?= h($search) ?>">
            <?php if ($showAll): ?>
                <input type="hidden" name="all" value="1">
            <?php else: ?>
                <input type="hidden" name="date" value="<?= h($selected_date) ?>">
            <?php endif; ?>
            <button type="submit" class="btn btn-blue">Search</button>
            <a class="btn btn-gray" href="sample_collection.php">Reset</a>
        </form>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Source</th>
                    <th>Date</th>
                    <th>Bill / Reference No.</th>
                    <th>UHID</th>
                    <th>Patient Name</th>
                    <th>Mobile</th>
                    <th>Laboratory Service</th>
                    <th>Qty</th>
                    <th>Rate (₹)</th>
                    <th>Service Amount (₹)</th>
                    <th>Paid (₹)</th>
                    <th>Balance (₹)</th>
                    <th>Billing Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($pathology_rows)): ?>
                <tr><td colspan="14" class="empty-row">No laboratory billing entries found for the selected filters.</td></tr>
            <?php else: ?>
                <?php foreach ($pathology_rows as $index => $row): ?>
                    <?php
                    $sourceClass = 'source-direct';
                    if ($row['source_type'] === 'OPD') {
                        $sourceClass = 'source-opd';
                    } elseif ($row['source_type'] === 'IPD') {
                        $sourceClass = 'source-ipd';
                    }
                    ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><span class="source-badge <?= $sourceClass ?>"><?= h($row['source_type']) ?></span></td>
                        <td><?= h(date('d-m-Y', strtotime($row['entry_date']))) ?></td>
                        <td><?= h($row['reference_no']) ?></td>
                        <td><?= h($row['uhid']) ?></td>
                        <td><?= h(trim($row['patient_name'])) ?></td>
                        <td><?= h($row['mobile']) ?></td>
                        <td><?= h($row['service_name']) ?></td>
                        <td><?= h($row['quantity']) ?></td>
                        <td><?= money($row['rate']) ?></td>
                        <td><strong><?= money($row['amount']) ?></strong></td>
                        <td><?= money($row['paid_amount']) ?></td>
                        <td><?= money($row['balance_amount']) ?></td>
                        <td><span class="status-badge"><?= h($row['payment_status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>
</body>
</html>
