<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
date_default_timezone_set('Asia/Kolkata');

if (!isset($_SESSION['user_id'])) {
    exit("Unauthorized");
}

require $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';

// ────────────────────────────────────────────────
// FILTERS
// ────────────────────────────────────────────────
$from   = $_GET['from']   ?? date('Y-m-d');
$to     = $_GET['to']     ?? date('Y-m-d');
$type   = strtolower(trim($_GET['type'] ?? ''));
$category = $_GET['category'] ?? '';
$entity = $_GET['entity_id'] ?? '';
$status = $_GET['status'] ?? '';

$where = "WHERE a.appointment_date BETWEEN '$from' AND '$to'";
if ($type)     $where .= " AND LOWER(a.type) = '$type'";
if ($entity)   $where .= " AND a.entity_id = '$entity'";
if ($status)   $where .= " AND a.status = '$status'";
if ($category) {
    if ($type === 'doctor')  $where .= " AND a.department_id = '$category'";
    if ($type === 'service') $where .= " AND sm.category_type = '$category'";
}

// ────────────────────────────────────────────────
// KPI SUMMARY
// ────────────────────────────────────────────────
$kpi_sql = "
SELECT
    COUNT(*) AS total,

    /* Confirmed */
    SUM(CASE WHEN a.status = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed_count,
    COALESCE(SUM(CASE WHEN a.status = 'Confirmed' THEN a.paid_amount ELSE 0 END),0) AS confirmed_amount,

    /* Registered */
    SUM(CASE WHEN a.status = 'Registered' THEN 1 ELSE 0 END) AS registered_count,
    COALESCE(SUM(CASE WHEN a.status = 'Registered' THEN a.paid_amount ELSE 0 END),0) AS registered_amount,

SUM(
    CASE WHEN a.status IN (
        'Confirmed',
        'Registered',
        'Refund',
        'Done',
        'Prescription'
    )
    THEN 1
    ELSE 0
    END
) AS all_appointments_count,

    /* All Appointments */
COALESCE(
    SUM(
        CASE WHEN a.status IN (
            'Confirmed',
            'Registered',
            'Refund',
            'Done',
            'Prescription'
        )
        THEN a.paid_amount
        ELSE 0
        END
    ),0
) AS all_appointments_amount,

    /* Pending */
    SUM(CASE WHEN a.status = 'Payment Pending' THEN 1 ELSE 0 END) AS pending,

    /* Refund */
    SUM(CASE WHEN a.status = 'Refund' THEN 1 ELSE 0 END) AS refund,
    COALESCE(SUM(CASE WHEN a.status = 'Refund' THEN a.refund_amount ELSE 0 END),0) AS refund_amount,

SUM(CASE WHEN a.status = 'Cancel' THEN 1 ELSE 0 END) AS cancel_count,
COALESCE(SUM(CASE WHEN a.status = 'Cancel' THEN a.refund_amount ELSE 0 END),0) AS cancel_amount,

SUM(CASE WHEN a.status = 'Done' THEN 1 ELSE 0 END) AS done_count,
COALESCE(SUM(CASE WHEN a.status = 'Done' THEN a.paid_amount ELSE 0 END),0) AS done_amount,

SUM(CASE WHEN a.status = 'Prescription' THEN 1 ELSE 0 END) AS prescription_count,
COALESCE(SUM(CASE WHEN a.status = 'Prescription' THEN a.paid_amount ELSE 0 END),0) AS prescription_amount,


    /* Totals */
    SUM(a.consultation_fee) AS total_fee,
    SUM(a.paid_amount) AS total_paid,
    SUM(a.balance_amount) AS total_balance
FROM appointments a
LEFT JOIN service_master sm ON sm.id = a.entity_id
$where
";

$kpi_res = mysqli_query($conn, $kpi_sql);
$kpi = mysqli_fetch_assoc($kpi_res) ?? [];

// ────────────────────────────────────────────────
// CATEGORY DATA (for chart + table)
// ────────────────────────────────────────────────
$category_sql = "
    SELECT
        CASE
            WHEN a.type = 'service' THEN sm.category_type
            WHEN a.type = 'doctor'  THEN dm.department_name
            ELSE 'Other'
        END AS category_name,
        COUNT(a.id) AS total,
        SUM(a.paid_amount) AS paid
    FROM appointments a
    LEFT JOIN service_master sm  ON sm.id = a.entity_id  AND a.type = 'service'
    LEFT JOIN department_master dm ON dm.id = a.department_id AND a.type = 'doctor'
    $where
    GROUP BY category_name
    ORDER BY total DESC
";
$category_res = mysqli_query($conn, $category_sql);

$cat_labels = [];
$cat_values = [];
while ($r = mysqli_fetch_assoc($category_res)) {
    $cat_labels[] = $r['category_name'] ?: 'Other';
    $cat_values[] = (int)$r['total'];
}
mysqli_data_seek($category_res, 0);

$cat_labels_json = json_encode($cat_labels);
$cat_values_json = json_encode($cat_values);

// ────────────────────────────────────────────────
// SERVICE DATA
// ────────────────────────────────────────────────
$service_sql = "
    SELECT
        sm.service_name,
        COUNT(a.id) AS total,
        SUM(a.paid_amount) AS paid
    FROM appointments a
    INNER JOIN service_master sm ON sm.id = a.entity_id
    WHERE a.type = 'service'
      AND a.appointment_date BETWEEN '$from' AND '$to'
";
if ($status)   $service_sql .= " AND a.status = '$status'";
if ($entity && $type === 'service') $service_sql .= " AND a.entity_id = '$entity'";
if ($category && $type === 'service') $service_sql .= " AND sm.category_type = '$category'";
$service_sql .= " GROUP BY sm.service_name ORDER BY total DESC LIMIT 8";
$service_res = mysqli_query($conn, $service_sql);

$svc_labels = [];
$svc_values = [];
while ($r = mysqli_fetch_assoc($service_res)) {
    $svc_labels[] = $r['service_name'] ?: '-';
    $svc_values[] = (int)$r['total'];
}
mysqli_data_seek($service_res, 0);

$svc_labels_json = json_encode($svc_labels);
$svc_values_json = json_encode($svc_values);

// ────────────────────────────────────────────────
// DOCTOR DATA
// ────────────────────────────────────────────────
$doctor_sql = "
    SELECT
        u.full_name AS doctor_name,
        COUNT(a.id) AS total,
        SUM(a.paid_amount) AS paid
    FROM appointments a
    INNER JOIN users u ON u.id = a.entity_id
    WHERE a.type = 'doctor'
      AND a.appointment_date BETWEEN '$from' AND '$to'
";
if ($status)   $doctor_sql .= " AND a.status = '$status'";
if ($entity && $type === 'doctor') $doctor_sql .= " AND a.entity_id = '$entity'";
if ($category) $doctor_sql .= " AND a.department_id = '$category'";
$doctor_sql .= " GROUP BY u.full_name ORDER BY total DESC LIMIT 8";
$doctor_res = mysqli_query($conn, $doctor_sql);

$doc_labels = [];
$doc_values = [];
while ($r = mysqli_fetch_assoc($doctor_res)) {
    $doc_labels[] = $r['doctor_name'] ?: '-';
    $doc_values[] = (int)$r['total'];
}
mysqli_data_seek($doctor_res, 0);

$doc_labels_json = json_encode($doc_labels);
$doc_values_json = json_encode($doc_values);

// ────────────────────────────────────────────────
// STATUS DISTRIBUTION
// ────────────────────────────────────────────────
$status_sql = "
    SELECT a.status, COUNT(*) AS cnt
    FROM appointments a
    $where
    GROUP BY a.status
    ORDER BY cnt DESC
";
$status_res = mysqli_query($conn, $status_sql);
$status_labels = [];
$status_values = [];
while ($s = mysqli_fetch_assoc($status_res)) {
    $status_labels[] = $s['status'] ?: 'Unknown';
    $status_values[] = (int)$s['cnt'];
}
$status_labels_json = json_encode($status_labels);
$status_values_json = json_encode($status_values);

// ────────────────────────────────────────────────
// ENTITY WISE
// ────────────────────────────────────────────────
$entity_sql = "
    SELECT
        IF(a.type = 'doctor', doc.full_name, sm.service_name) AS entity_name,
        a.type,
        COUNT(a.id) AS total,
        SUM(a.paid_amount) AS paid
    FROM appointments a
    LEFT JOIN users doc ON doc.id = a.entity_id AND a.type = 'doctor'
    LEFT JOIN service_master sm ON sm.id = a.entity_id AND a.type = 'service'
    $where
    GROUP BY entity_name, a.type
    ORDER BY total DESC
";
$entity_res = mysqli_query($conn, $entity_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointment Report <?= htmlspecialchars($from . ' to ' . $to) ?></title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10.5pt;
            margin: 12mm 10mm;
            color: #111;
            line-height: 1.4;
        }
        .no-print {
            text-align: center;
            margin-bottom: 20px;
        }
        h1 {
            text-align: center;
            color: #1d4ed8;
            margin: 0 0 6px 0;
            font-size: 18pt;
        }
        .period {
            text-align: center;
            color: #4b5563;
            font-size: 10pt;
            margin-bottom: 20px;
        }
        h2 {
            color: #1d4ed8;
            margin: 30px 0 10px 0;
            font-size: 13pt;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 5px;
        }
        .summary {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 15px 0 25px 0;
            justify-content: center;
        }
        .card {
            flex: 1 1 140px;
            max-width: 160px;
            padding: 10px 6px;
            border: 1px solid #cbd5e1;
            border-radius: 5px;
            background: #f8fafc;
            text-align: center;
        }
        .card strong {
            display: block;
            font-size: 10pt;
            margin-bottom: 3px;
            color: #1d4ed8;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
            font-size: 9.5pt;
        }
        th, td {
            border: 1px solid #94a3b8;
            padding: 6px 7px;
            text-align: left;
        }
        th {
            background: #1d4ed8;
            color: white;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background: #f1f5f9;
        }
        .center { text-align: center; }
        .right  { text-align: right; }
        .no-data {
            text-align: center;
            padding: 20px;
            color: #64748b;
            font-style: italic;
        }
        .charts-row {
            display: flex;
            flex-wrap: nowrap;
            overflow-x: auto;
            gap: 15px;
            margin: 20px 0 30px 0;
            padding-bottom: 10px;
        }
        .chart-box {
            flex: 0 0 420px;
            width: 420px;
            text-align: center;
            page-break-inside: avoid;
        }
        .chart-box h3 {
            margin: 0 0 8px 0;
            font-size: 11pt;
            color: #374151;
        }
        .chart-img {
            width: 100%;
            max-width: 420px;
            height: auto;
            display: block;
            margin: 0 auto;
            border: 1px solid #e5e7eb;
            border-radius: 5px;
        }
        @media print {
            .no-print { display: none !important; }
            body { margin: 8mm; }
            h1 { font-size: 16pt; }
            .charts-row {
                flex-wrap: wrap;
                overflow: visible;
            }
            .chart-box {
                flex: 1 1 45%;
                max-width: 48%;
            }
        }
    </style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()" style="
        padding: 10px 24px;
        font-size: 14px;
        background: #1d4ed8;
        color: white;
        border: none;
        border-radius: 5px;
        cursor: pointer;
    ">
        🖨 Print / Save as PDF
    </button>
</div>

<h1>Appointment Report</h1>
<div class="period">Period: <?= htmlspecialchars($from) ?> – <?= htmlspecialchars($to) ?></div>

<div class="summary">

    <div class="card">
        <strong>Total</strong>
        <?= number_format($kpi['total'] ?? 0) ?><br>
        ₹ <?= number_format($kpi['total_fee'] ?? 0, 2) ?>
    </div>

    <div class="card">
        <strong>Payment Pending</strong>
        <?= number_format($kpi['pending'] ?? 0) ?><br>
        ₹ <?= number_format($kpi['total_balance'] ?? 0, 2) ?>
    </div>

    <div class="card">
        <strong>Confirmed</strong>
        <?= number_format($kpi['confirmed_count'] ?? 0) ?><br>
        ₹ <?= number_format($kpi['confirmed_amount'] ?? 0, 2) ?>
    </div>
    
    <div class="card">
        <strong>Refund</strong>
        <?= number_format($kpi['refund'] ?? 0) ?><br>
        ₹ <?= number_format($kpi['refund_amount'] ?? 0, 2) ?>
    </div>
    
    <div class="card">
    <strong>Cancel</strong>
    <?= number_format($kpi['cancel_count'] ?? 0) ?><br>
    ₹ <?= number_format($kpi['cancel_amount'] ?? 0, 2) ?>
</div>

<div class="card">
    <strong>Done</strong>
    <?= number_format($kpi['done_count'] ?? 0) ?><br>
    ₹ <?= number_format($kpi['done_amount'] ?? 0, 2) ?>
</div>

<div class="card">
    <strong>Prescription</strong>
    <?= number_format($kpi['prescription_count'] ?? 0) ?><br>
    ₹ <?= number_format($kpi['prescription_amount'] ?? 0, 2) ?>
</div>

    <div class="card">
        <strong>Registered</strong>
        <?= number_format($kpi['registered_count'] ?? 0) ?><br>
        ₹ <?= number_format($kpi['registered_amount'] ?? 0, 2) ?>
    </div>

    <div class="card">
    <strong>All Appointments</strong>
    <?= number_format($kpi['all_appointments_count'] ?? 0) ?><br>
    ₹ <?= number_format($kpi['all_appointments_amount'] ?? 0, 2) ?>
</div>

    <div class="card">
        <strong>Total Revenue</strong>
₹ <?= number_format($kpi['total_paid'] ?? 0, 2) ?>
    </div>

</div>


<!-- All 4 charts in one horizontal row -->
<div class="charts-row">
    <!-- Chart 1: Category -->
    <div class="chart-box">
        <h3>By Category</h3>
        <?php if (!empty($cat_labels)): ?>
            <img class="chart-img" src="https://quickchart.io/chart?c=<?php
                echo urlencode(json_encode([
                    'type' => 'doughnut',
                    'data' => [
                        'labels' => $cat_labels,
                        'datasets' => [[
                            'data' => $cat_values,
                            'backgroundColor' => ['#6366f1','#8b5cf6','#ec4899','#f43f5e','#10b981','#f59e0b','#3b82f6','#64748b']
                        ]]
                    ],
                    'options' => [
                        'plugins' => ['legend' => ['position' => 'bottom']]
                    ]
                ]));
            ?>&w=420&h=280&bkg=white" alt="Category">
        <?php else: ?>
            <p class="no-data">No data</p>
        <?php endif; ?>
    </div>

    <!-- Chart 2: Status -->
    <div class="chart-box">
        <h3>Status Distribution</h3>
        <?php if (!empty($status_labels)): ?>
            <img class="chart-img" src="https://quickchart.io/chart?c=<?php
                echo urlencode(json_encode([
                    'type' => 'doughnut',
                    'data' => [
                        'labels' => $status_labels,
                        'datasets' => [[
                            'data' => $status_values,
                            'backgroundColor' => ['#4caf50','#2196f3','#ff9800','#f44336','#9e9e9e','#607d8b']
                        ]]
                    ],
                    'options' => [
                        'plugins' => ['legend' => ['position' => 'bottom']]
                    ]
                ]));
            ?>&w=420&h=280&bkg=white" alt="Status">
        <?php else: ?>
            <p class="no-data">No data</p>
        <?php endif; ?>
    </div>

    <!-- Chart 3: Services -->
    <div class="chart-box">
        <h3>Top Services</h3>
        <?php if (!empty($svc_labels)): ?>
            <img class="chart-img" src="https://quickchart.io/chart?c=<?php
                echo urlencode(json_encode([
                    'type' => 'bar',
                    'data' => [
                        'labels' => $svc_labels,
                        'datasets' => [[
                            'label' => 'Appointments',
                            'data' => $svc_values,
                            'backgroundColor' => 'rgba(16,185,129,0.8)'
                        ]]
                    ],
                    'options' => [
                        'indexAxis' => 'y',
                        'plugins' => ['legend' => ['display' => false]],
                        'scales' => ['x' => ['beginAtZero' => true]]
                    ]
                ]));
            ?>&w=420&h=280&bkg=white" alt="Services">
        <?php else: ?>
            <p class="no-data">No data</p>
        <?php endif; ?>
    </div>

    <!-- Chart 4: Doctors -->
    <div class="chart-box">
        <h3>Top Doctors</h3>
        <?php if (!empty($doc_labels)): ?>
            <img class="chart-img" src="https://quickchart.io/chart?c=<?php
                echo urlencode(json_encode([
                    'type' => 'bar',
                    'data' => [
                        'labels' => $doc_labels,
                        'datasets' => [[
                            'label' => 'Appointments',
                            'data' => $doc_values,
                            'backgroundColor' => 'rgba(139,92,246,0.8)'
                        ]]
                    ],
                    'options' => [
                        'indexAxis' => 'y',
                        'plugins' => ['legend' => ['display' => false]],
                        'scales' => ['x' => ['beginAtZero' => true]]
                    ]
                ]));
            ?>&w=420&h=280&bkg=white" alt="Doctors">
        <?php else: ?>
            <p class="no-data">No data</p>
        <?php endif; ?>
    </div>
</div>

<!-- Summary Tables -->
<h2>Category Wise</h2>
<table>
    <tr><th>Category</th><th class="center">Total</th><th class="right">Paid</th></tr>
    <?php if (mysqli_num_rows($category_res) === 0): ?>
        <tr><td colspan="3" class="no-data">No data</td></tr>
    <?php else: ?>
        <?php while ($r = mysqli_fetch_assoc($category_res)): ?>
        <tr>
            <td><?= htmlspecialchars($r['category_name'] ?? '—') ?></td>
            <td class="center"><?= number_format($r['total'] ?? 0) ?></td>
            <td class="right">₹ <?= number_format($r['paid'] ?? 0, 2) ?></td>
        </tr>
        <?php endwhile; ?>
    <?php endif; ?>
</table>

<h2>Service Wise</h2>
<table>
    <tr><th>Service</th><th class="center">Total</th><th class="right">Paid</th></tr>
    <?php if (mysqli_num_rows($service_res) === 0): ?>
        <tr><td colspan="3" class="no-data">No data</td></tr>
    <?php else: ?>
        <?php while ($r = mysqli_fetch_assoc($service_res)): ?>
        <tr>
            <td><?= htmlspecialchars($r['service_name'] ?? '—') ?></td>
            <td class="center"><?= number_format($r['total'] ?? 0) ?></td>
            <td class="right">₹ <?= number_format($r['paid'] ?? 0, 2) ?></td>
        </tr>
        <?php endwhile; ?>
    <?php endif; ?>
</table>

<h2>Doctor Wise</h2>
<table>
    <tr><th>Doctor</th><th class="center">Total</th><th class="right">Paid</th></tr>
    <?php if (mysqli_num_rows($doctor_res) === 0): ?>
        <tr><td colspan="3" class="no-data">No data</td></tr>
    <?php else: ?>
        <?php while ($r = mysqli_fetch_assoc($doctor_res)): ?>
        <tr>
            <td><?= htmlspecialchars($r['doctor_name'] ?? '—') ?></td>
            <td class="center"><?= number_format($r['total'] ?? 0) ?></td>
            <td class="right">₹ <?= number_format($r['paid'] ?? 0, 2) ?></td>
        </tr>
        <?php endwhile; ?>
    <?php endif; ?>
</table>

<h2>Entity Wise</h2>
<table>
    <tr><th>Type</th><th>Entity</th><th class="center">Total</th><th class="right">Paid</th></tr>
    <?php if (mysqli_num_rows($entity_res) === 0): ?>
        <tr><td colspan="4" class="no-data">No data</td></tr>
    <?php else: ?>
        <?php while ($r = mysqli_fetch_assoc($entity_res)): ?>
        <tr>
            <td><?= htmlspecialchars(ucfirst($r['type'] ?? '—')) ?></td>
            <td><?= htmlspecialchars($r['entity_name'] ?? '—') ?></td>
            <td class="center"><?= number_format($r['total'] ?? 0) ?></td>
            <td class="right">₹ <?= number_format($r['paid'] ?? 0, 2) ?></td>
        </tr>
        <?php endwhile; ?>
    <?php endif; ?>
</table>

</body>
</html>