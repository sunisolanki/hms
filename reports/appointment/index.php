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
$from = $_GET['from'] ?? date('Y-m-d');
$to = $_GET['to'] ?? date('Y-m-d');
$type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : '';
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
   KPI SUMMARY
=============================== */
$kpi_sql = "
SELECT
    COUNT(*) AS total,

    /* Confirmed */
    SUM(CASE WHEN a.status = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed_count,
    COALESCE(SUM(CASE WHEN a.status = 'Confirmed' THEN a.paid_amount ELSE 0 END),0) AS confirmed_amount,

    /* Registered */
    SUM(CASE WHEN a.status = 'Registered' THEN 1 ELSE 0 END) AS registered_count,
    COALESCE(SUM(CASE WHEN a.status = 'Registered' THEN a.paid_amount ELSE 0 END),0) AS registered_amount,

    /* All Appointments = Confirmed + Registered */
    SUM(
        CASE WHEN a.status IN (
    'Confirmed',
    'Registered',
    'Refund',
    'Done',
    'Prescription'
)
THEN 1 ELSE 0 END
    ) AS all_appointments_count,
    COALESCE(
        SUM(
            CASE WHEN a.status IN (
    'Confirmed',
    'Registered',
    'Refund',
    'Done',
    'Prescription'
)
            THEN a.paid_amount ELSE 0 END
        ),0
    ) AS all_appointments_amount,

    /* Others */
    SUM(CASE WHEN a.status = 'Payment Pending' THEN 1 ELSE 0 END) AS pending,
    /* Refund */
SUM(CASE WHEN a.status = 'Refund' THEN 1 ELSE 0 END) AS refund,
COALESCE(
    SUM(CASE WHEN a.status = 'Refund' THEN a.refund_amount ELSE 0 END), 0
) AS refund_amount,

/* Cancel */
SUM(CASE WHEN a.status = 'Cancel' THEN 1 ELSE 0 END) AS cancel_count,
COALESCE(
    SUM(CASE WHEN a.status = 'Cancel' THEN a.refund_amount ELSE 0 END),0
) AS cancel_amount,

/* Done */
SUM(CASE WHEN a.status = 'Done' THEN 1 ELSE 0 END) AS done_count,
COALESCE(
    SUM(CASE WHEN a.status = 'Done' THEN a.paid_amount ELSE 0 END),0
) AS done_amount,

/* Prescription */
SUM(CASE WHEN a.status = 'Prescription' THEN 1 ELSE 0 END) AS prescription_count,
COALESCE(
    SUM(CASE WHEN a.status = 'Prescription' THEN a.paid_amount ELSE 0 END),0
) AS prescription_amount,

    SUM(a.consultation_fee) AS total_fee,
    SUM(a.paid_amount) AS total_paid,
    SUM(a.balance_amount) AS total_balance

FROM appointments a
LEFT JOIN service_master sm ON sm.id = a.entity_id
$where
";
$kpi_res = mysqli_query($conn, $kpi_sql);
$kpi = mysqli_fetch_assoc($kpi_res);
$service_sql = "
SELECT
    sm.service_name,
    COUNT(a.id) AS total,
    SUM(a.paid_amount) AS paid
FROM appointments a
INNER JOIN service_master sm
    ON sm.id = a.entity_id
WHERE a.type = 'service'
AND a.appointment_date BETWEEN '$from' AND '$to'
";
if (!empty($status)) {
    $service_sql .= " AND a.status = '$status'";
}
if (!empty($entity) && $type === 'service') {
    $service_sql .= " AND a.entity_id = '$entity'";
}
if (!empty($category) && $type === 'service') {
    $service_sql .= " AND sm.category_type = '$category'";
}
$service_sql .= "
GROUP BY sm.service_name
ORDER BY total DESC
";
$service_res = mysqli_query($conn, $service_sql);
/* ===============================
   CATEGORY WISE
=============================== */
$category_sql = "
SELECT
    CASE
        WHEN a.type = 'service' THEN sm.category_type
        WHEN a.type = 'doctor' THEN dm.department_name
        ELSE 'Other'
    END AS category_name,
    COUNT(a.id) AS total,
    SUM(a.paid_amount) AS paid
FROM appointments a
LEFT JOIN service_master sm
    ON sm.id = a.entity_id AND a.type = 'service'
LEFT JOIN department_master dm
    ON dm.id = a.department_id AND a.type = 'doctor'
$where
GROUP BY category_name
ORDER BY total DESC
";
$category_res = mysqli_query($conn, $category_sql);
/* ===============================
   DOCTOR WISE
=============================== */
$doctor_sql = "
SELECT
    u.full_name AS doctor_name,
    COUNT(a.id) AS total,
    SUM(a.paid_amount) AS paid
FROM appointments a
INNER JOIN users u
    ON u.id = a.entity_id
WHERE a.type = 'doctor'
AND a.appointment_date BETWEEN '$from' AND '$to'
";
if (!empty($status)) {
    $doctor_sql .= " AND a.status = '$status'";
}
if (!empty($entity) && $type === 'doctor') {
    $doctor_sql .= " AND a.entity_id = '$entity'";
}
if (!empty($category)) {
    $doctor_sql .= " AND a.department_id = '$category'";
}
$doctor_sql .= "
GROUP BY u.full_name
ORDER BY total DESC
";
$doctor_res = mysqli_query($conn, $doctor_sql);
if (!$doctor_res) {
    die("Doctor Query Error: " . mysqli_error($conn));
}
/* ===============================
   ENTITY WISE
=============================== */
$entity_sql = "
SELECT
    IF(
        a.type = 'doctor',
        doc.full_name,
        sm.service_name
    ) AS entity_name,
    a.type,
    COUNT(a.id) AS total,
    SUM(a.paid_amount) AS paid
FROM appointments a
LEFT JOIN users doc
    ON doc.id = a.entity_id AND a.type = 'doctor'
LEFT JOIN service_master sm
    ON sm.id = a.entity_id AND a.type = 'service'
$where
GROUP BY entity_name, a.type
ORDER BY total DESC
";
$entity_res = mysqli_query($conn, $entity_sql);
/* ===============================
   DETAILED LIST
===============================
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
    ON doc.id = a.entity_id
   AND a.type = 'doctor'
LEFT JOIN service_master sm
    ON sm.id = a.entity_id
   AND a.type = 'service'
$where
ORDER BY a.created_at DESC
";
$detail_res = mysqli_query($conn, $detail_sql);
*/
?>

<!DOCTYPE html>
<html>
<head>
<title>Appointment Enterprise Report</title>
<link rel="stylesheet" href="/assets/css/style.css">
<!-- FAVICON -->
    <link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
    <link rel="apple-touch-icon" href="/assets/img/hms_logo.png">

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<!-- ChartDataLabels plugin for showing % inside pie slices -->
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>

<style>
.report-container { padding:10px; }
.kpi-cards {
    display:flex;
    gap:15px;
    flex-wrap:wrap;
}
.card {
    flex:1;
    min-width:165px;
    background:#fff;
    padding:15px;
    border-radius:8px;
    box-shadow:0 5px 15px rgba(0,0,0,.08);
    text-align:center;
}
.card h3 { margin:0; font-size:20px; }
.card p { margin:5px 0 0; color:#555; }
table {
    width:100%;
    border-collapse:collapse;
    font-size:13px;
}
th, td {
    border:1px solid #ddd;
    padding:8px;
}
th {
    background:#1976d2;
    color:#fff;
}
.table-wrap {
    overflow:auto;
    margin-bottom:30px;
}
input:not([type="checkbox"]), select {
        width: 10%;
   
}

/* ─────────────── Charts ─────────────── */
.charts-container {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    margin: 25px 0 35px 0;
    justify-content: center;
}
.chart-card {
    background: white;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    padding: 16px;
    width: 100%;
    max-width: 290px;
    height: 360px;
}
.chart-card h3 {
    margin: 0 0 12px 0;
    font-size: 16px;
    color: #333;
    text-align: center;
}
.chart-wrapper {
    position: relative;
    height: 300px;
    width: 100%;
}

/* KPI Soft Colors */
.kpi-total      { background: #e3f2fd; }   /* light blue */
.kpi-confirmed  { background: #e8f5e9; }   /* light green */
.kpi-pending    { background: #fff8e1; }   /* light yellow */
.kpi-refund     { background: #fdecea; }   /* light red */
.kpi-fee        { background: #f3e8ff; }   /* light violet */
.kpi-paid       { background: #e0f7fa; }   /* light cyan */
.kpi-balance    { background: #fce4ec; }   /* light pink */


@media (max-width: 1100px) {
    .charts-container {
        flex-direction: column;
        align-items: center;
    }
    .chart-card {
        max-width: 520px;
    }
}
</style>
</head>
<body>

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>

<div class="main-content" style="width:auto;">
<div class="report-container">
<h2>📊 Appointment Report</h2>

<form method="GET" style="margin-bottom:20px;">
From: <input type="date" name="from" value="<?= $from ?>">
To: <input type="date" name="to" value="<?= $to ?>">
Type:
<select name="type">
    <option value="">All</option>
    <option value="doctor" <?= ($type === 'doctor')?'selected':'' ?>>Doctor</option>
    <option value="service" <?= ($type === 'service')?'selected':'' ?>>Service</option>
</select>
Category:
<select name="category">
    <option value="">All</option>
<?php
if ($type === 'doctor') {
    $cats = mysqli_query($conn, "SELECT id, department_name FROM department_master ORDER BY department_name");
    while ($c = mysqli_fetch_assoc($cats)) {
        $sel = ($category == $c['id']) ? 'selected' : '';
        echo "<option value='{$c['id']}' $sel>{$c['department_name']}</option>";
    }
} elseif ($type === 'service') {
    $cats = mysqli_query($conn, "SELECT DISTINCT category_type FROM service_master ORDER BY category_type");
    while ($c = mysqli_fetch_assoc($cats)) {
        $sel = ($category == $c['category_type']) ? 'selected' : '';
        echo "<option value='{$c['category_type']}' $sel>{$c['category_type']}</option>";
    }
}
?>
</select>
Entity:
<select name="entity_id">
    <option value="">All</option>
<?php
if ($type == 'doctor') {
    $entities = mysqli_query($conn, "SELECT id, full_name FROM users WHERE employee_type_id='2'");
    while ($e = mysqli_fetch_assoc($entities)) {
        $sel = ($entity == $e['id']) ? 'selected' : '';
        echo "<option value='{$e['id']}' $sel>{$e['full_name']}</option>";
    }
}
elseif ($type == 'service') {
    $entities = mysqli_query($conn, "SELECT id, service_name FROM service_master");
    while ($e = mysqli_fetch_assoc($entities)) {
        $sel = ($entity == $e['id']) ? 'selected' : '';
        echo "<option value='{$e['id']}' $sel>{$e['service_name']}</option>";
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
<a href="export_excel.php?from=<?= $from ?>&to=<?= $to ?>&type=<?= $type ?>&entity_id=<?= $entity ?>&status=<?= $status ?>"
   style="padding:6px 12px;background:#1976d2;color:#fff;border-radius:4px;text-decoration:none;">
   Excel
</a>
<a href="export_pdf.php?from=<?= $from ?>&to=<?= $to ?>&type=<?= $type ?>&entity_id=<?= $entity ?>&status=<?= $status ?>"
   target="_blank"
   style="padding:6px 12px;background:#16a34a;color:#fff;border-radius:4px;text-decoration:none;">
   PDF
</a>
</form>

<!-- KPI -->
<div class="kpi-cards">

    <div class="card kpi-total">
    <p>Total</p>
    <h3><?= $kpi['total'] ?? 0 ?></h3>
    <hr>
    <strong>₹ <?= number_format($kpi['total_fee'] ?? 0, 2) ?></strong>
</div>



   <div class="card kpi-pending">
    <p>Payment Pending</p>
    <h3><?= $kpi['pending'] ?? 0 ?></h3>
    <hr>
    <strong>₹ <?= number_format($kpi['total_balance'] ?? 0, 2) ?></strong>
</div>

    
    <!-- CONFIRMED KPI -->
<div class="card kpi-confirmed">
    <p>Confirmed</p>
    <h3><?= $kpi['confirmed_count'] ?? 0 ?></h3>
    <hr>
    <strong>₹ <?= number_format($kpi['confirmed_amount'] ?? 0,2) ?></strong>
</div>

    <!-- Refund KPI -->
    <div class="card kpi-refund">
    <p>Refund</p>
    <h3><?= $kpi['refund'] ?? 0 ?></h3>
    <hr>
    <strong>₹ <?= number_format($kpi['refund_amount'] ?? 0, 2) ?></strong>
</div>

    <!-- Cancel KPI -->
<div class="card kpi-refund">
    <p>Cancel</p>
    <h3><?= $kpi['cancel_count'] ?? 0 ?></h3>
    <hr>
    <strong>₹ <?= number_format($kpi['cancel_amount'] ?? 0,2) ?></strong>
</div>
<!-- Done KPI -->
<div class="card kpi-confirmed">
    <p>Done</p>
    <h3><?= $kpi['done_count'] ?? 0 ?></h3>
    <hr>
    <strong>₹ <?= number_format($kpi['done_amount'] ?? 0,2) ?></strong>
</div>

<!-- Prescription KPI -->
<div class="card kpi-paid">
    <p>Prescription</p>
    <h3><?= $kpi['prescription_count'] ?? 0 ?></h3>
    <hr>
    <strong>₹ <?= number_format($kpi['prescription_amount'] ?? 0,2) ?></strong>
</div>
<!-- REGISTERED KPI -->
<div class="card kpi-paid">
    <p>Registered</p>
    <h3><?= $kpi['registered_count'] ?? 0 ?></h3>
    <hr>
    <strong>₹ <?= number_format($kpi['registered_amount'] ?? 0, 2) ?></strong>
</div>


<!-- ALL APPOINTMENTS KPI -->
<div class="card kpi-total">
    <p>All</p>
    <h3><?= $kpi['all_appointments_count'] ?? 0 ?></h3>
    <hr>
    <strong>₹ <?= number_format($kpi['all_appointments_amount'] ?? 0,2) ?></strong>
</div>


    <!-- NEW CARD -->
    <div class="card kpi-paid">
    <h3>₹ <?= number_format($kpi['total_paid'] ?? 0,2) ?></h3>
    <p>Total Revenue</p>
</div>
</div>

<!-- ─────────────── CHARTS ─────────────── -->
<div class="charts-container">

    <!-- Chart 1: Appointments by Category -->
    <div class="chart-card">
        <h3>Appointments by Category</h3>
        <div class="chart-wrapper">
            <canvas id="chartCategory"></canvas>
        </div>
    </div>

    <!-- Chart 2: Status Distribution -->
    <div class="chart-card">
        <h3>Status Distribution</h3>
        <div class="chart-wrapper">
            <canvas id="chartStatus"></canvas>
        </div>
    </div>

    <!-- Chart 3: Top Services -->
    <?php if ($type === '' || $type === 'service'): ?>
    <div class="chart-card">
        <h3>Top Services</h3>
        <div class="chart-wrapper">
            <canvas id="chartServices"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <!-- Chart 4: Top Doctors -->
    <?php if ($type === '' || $type === 'doctor'): ?>
    <div class="chart-card">
        <h3>Top Doctors</h3>
        <div class="chart-wrapper">
            <canvas id="chartDoctors"></canvas>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- JavaScript for Charts -->
<script>
// Prepare data
const catData = {
    labels: [<?php 
        mysqli_data_seek($category_res, 0);
        $first = true;
        while($row = mysqli_fetch_assoc($category_res)) {
            if (!$first) echo ",";
            echo json_encode($row['category_name'] ?: 'Other');
            $first = false;
        }
    ?>],
    values: [<?php 
        mysqli_data_seek($category_res, 0);
        $first = true;
        while($row = mysqli_fetch_assoc($category_res)) {
            if (!$first) echo ",";
            echo (int)$row['total'];
            $first = false;
        }
    ?>]
};
<?php mysqli_data_seek($category_res, 0); ?>   <!-- ← added here -->

const statusData = {
    labels: [<?php 
        $status_query = "SELECT status, COUNT(*) as cnt FROM appointments a $where GROUP BY status";
        $status_res = mysqli_query($conn, $status_query);
        $first = true;
        while($s = mysqli_fetch_assoc($status_res)) {
            if (!$first) echo ",";
            echo json_encode($s['status'] ?: 'Unknown');
            $first = false;
        }
    ?>],
    values: [<?php 
        mysqli_data_seek($status_res, 0);
        $first = true;
        while($s = mysqli_fetch_assoc($status_res)) {
            if (!$first) echo ",";
            echo (int)$s['cnt'];
            $first = false;
        }
    ?>]
};

const serviceData = {
    labels: [<?php 
        mysqli_data_seek($service_res, 0);
        $first = true;
        while($row = mysqli_fetch_assoc($service_res)) {
            if (!$first) echo ",";
            echo json_encode($row['service_name']);
            $first = false;
        }
    ?>],
    values: [<?php 
        mysqli_data_seek($service_res, 0);
        $first = true;
        while($row = mysqli_fetch_assoc($service_res)) {
            if (!$first) echo ",";
            echo (int)$row['total'];
            $first = false;
        }
    ?>]
};
<?php mysqli_data_seek($service_res, 0); ?>   <!-- ← added here -->

const doctorData = {
    labels: [<?php 
        mysqli_data_seek($doctor_res, 0);
        $first = true;
        while($row = mysqli_fetch_assoc($doctor_res)) {
            if (!$first) echo ",";
            echo json_encode($row['doctor_name']);
            $first = false;
        }
    ?>],
    values: [<?php 
        mysqli_data_seek($doctor_res, 0);
        $first = true;
        while($row = mysqli_fetch_assoc($doctor_res)) {
            if (!$first) echo ",";
            echo (int)$row['total'];
            $first = false;
        }
    ?>]
};
<?php mysqli_data_seek($doctor_res, 0); ?>   <!-- ← added here -->


// Chart 1 - Categories (Improved Doughnut)
if (document.getElementById('chartCategory')) {
    new Chart(document.getElementById('chartCategory'), {
        type: 'doughnut',
        data: {
            labels: catData.labels,
            datasets: [{
                data: catData.values,
                backgroundColor: [
                    '#4f46e5', // indigo-600
                    '#7c3aed', // violet-600
                    '#c026d3', // fuchsia-600
                    '#e11d48', // rose-600
                    '#059669', // emerald-600
                    '#d97706', // amber-600
                    '#2563eb', // blue-600
                    '#475569', // slate-600
                    '#0891b2', // cyan-600
                    '#be185d'  // pink-700
                ],
                borderColor: '#f8fafc',
                borderWidth: 4,
                hoverOffset: 24
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '52%',                    // nicer donut size
            animation: { duration: 1600, easing: 'easeOutCubic' },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        font: { size: 13, weight: '500' },
                        padding: 18,
                        boxWidth: 12,
                        usePointStyle: true
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.94)',
                    titleFont: { size: 14 },
                    bodyFont: { size: 13 },
                    padding: 12,
                    callbacks: {
                        label: (ctx) => {
                            let val = ctx.raw;
                            let total = ctx.dataset.data.reduce((a,b)=>a+b,0);
                            let pct = ((val / total) * 100).toFixed(1);
                            return ` ${ctx.label}: ${val} (${pct}%)`;
                        }
                    }
                },
                datalabels: {
                    color: '#ffffff',
                    font: { weight: 'bold', size: 14 },
                    formatter: (val, ctx) => {
                        let sum = ctx.dataset.data.reduce((a,b)=>a+b,0);
                        let pct = (val / sum) * 100;
                        return pct > 4 ? Math.round(pct) + '%' : '';
                    }
                }
            }
        },
        plugins: [ChartDataLabels]
    });
}
// Chart 2 - Status (Doughnut)
if (document.getElementById('chartStatus')) {
    new Chart(document.getElementById('chartStatus'), {
        type: 'doughnut',
        data: {
            labels: statusData.labels,
            datasets: [{
                data: statusData.values,
                backgroundColor: [
                    '#4CAF50', '#2196F3', '#FF9800', '#F44336',
                    '#9C27B0', '#607D8B', '#FFEB3B'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } }
        }
    });
}

// Chart 3 - Top Services (Modern Horizontal Bar)
if (document.getElementById('chartServices')) {
    new Chart(document.getElementById('chartServices'), {
        type: 'bar',
        data: {
            labels: serviceData.labels,
            datasets: [{
                label: 'Appointments',
                data: serviceData.values,
                backgroundColor: 'rgba(16, 185, 129, 0.82)',   // emerald/teal tone
                borderColor: 'rgba(16, 185, 129, 1)',
                borderWidth: 1,
                borderRadius: 6,
                hoverBackgroundColor: 'rgba(16, 185, 129, 1)'
            }]
        },
        options: {
            indexAxis: 'y',                        // horizontal
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 1400, easing: 'easeOutQuart' },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(30, 41, 59, 0.95)',
                    titleFont: { size: 14 },
                    bodyFont: { size: 13 },
                    padding: 10,
                    callbacks: {
                        label: (ctx) => `Appointments: ${ctx.raw}`
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    grid: { color: 'rgba(203, 213, 225, 0.3)' },
                    ticks: { font: { size: 12 } }
                },
                y: {
                    grid: { display: false },
                    ticks: {
                        font: { size: 13, weight: '500' },
                        padding: 12,
                        autoSkip: false,
                        maxRotation: 0,
                        minRotation: 0
                    }
                }
            },
            layout: { padding: { right: 30 } }     // space for value labels
        }
    });
}

// Chart 4 - Top Doctors (Modern Horizontal Bar)
if (document.getElementById('chartDoctors')) {
    new Chart(document.getElementById('chartDoctors'), {
        type: 'bar',
        data: {
            labels: doctorData.labels,
            datasets: [{
                label: 'Appointments',
                data: doctorData.values,
                backgroundColor: 'rgba(139, 92, 246, 0.82)',   // violet tone
                borderColor: 'rgba(139, 92, 246, 1)',
                borderWidth: 1,
                borderRadius: 6,
                hoverBackgroundColor: 'rgba(139, 92, 246, 1)'
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 1400, easing: 'easeOutQuart' },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(30, 41, 59, 0.95)',
                    titleFont: { size: 14 },
                    bodyFont: { size: 13 },
                    padding: 10,
                    callbacks: {
                        label: (ctx) => `Appointments: ${ctx.raw}`
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    grid: { color: 'rgba(203, 213, 225, 0.3)' },
                    ticks: { font: { size: 12 } }
                },
                y: {
                    grid: { display: false },
                    ticks: {
                        font: { size: 13, weight: '500' },
                        padding: 12,
                        autoSkip: false,
                        maxRotation: 0,
                        minRotation: 0
                    }
                }
            },
            layout: { padding: { right: 30 } }
        }
    });
}
// Chart 4 - Doctors (Horizontal Bar)
if (document.getElementById('chartDoctors')) {
    new Chart(document.getElementById('chartDoctors'), {
        type: 'bar',
        data: {
            labels: doctorData.labels,
            datasets: [{
                label: 'Appointments',
                data: doctorData.values,
                backgroundColor: 'rgba(153, 102, 255, 0.7)',
                borderColor: 'rgba(153, 102, 255, 1)',
                borderWidth: 1
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            scales: { x: { beginAtZero: true } }
        }
    });
}
</script>

<h3>Category Wise</h3>
<div class="table-wrap">
<table>
<tr>
<th>Category</th>
<th>Total</th>
<th>Total Paid</th>
</tr>
<?php while($r = mysqli_fetch_assoc($category_res)): ?>
<tr>
<td><?= $r['category_name'] ?? '-' ?></td>
<td><?= $r['total'] ?></td>
<td>₹ <?= number_format($r['paid'],2) ?></td>
</tr>
<?php endwhile; ?>
</table>
</div>

<!-- SERVICE WISE -->
<?php if ($type === '' || $type === 'service'): ?>
<h3>Service Wise</h3>
<div class="table-wrap">
<table>
<tr>
<th>Service</th>
<th>Total</th>
<th>Total Paid</th>
</tr>
<?php while($r = mysqli_fetch_assoc($service_res)): ?>
<tr>
<td><?= $r['service_name'] ?? '-' ?></td>
<td><?= $r['total'] ?></td>
<td>₹ <?= number_format($r['paid'],2) ?></td>
</tr>
<?php endwhile; ?>
</table>
</div>
<?php endif; ?>

<!-- DOCTOR WISE -->
<?php if ($type === '' || $type === 'doctor'): ?>
<h3>Doctor Wise</h3>
<div class="table-wrap">
<table>
<tr>
<th>Doctor</th>
<th>Total</th>
<th>Total Paid</th>
</tr>
<?php while($r = mysqli_fetch_assoc($doctor_res)): ?>
<tr>
<td><?= $r['doctor_name'] ?? '-' ?></td>
<td><?= $r['total'] ?></td>
<td>₹ <?= number_format($r['paid'],2) ?></td>
</tr>
<?php endwhile; ?>
</table>
</div>
<?php endif; ?>

<h3>Entity Wise</h3>
<div class="table-wrap">
<table>
<tr>
<th>Type</th>
<th>Entity</th>
<th>Total</th>
<th>Total Paid</th>
</tr>
<?php while($r = mysqli_fetch_assoc($entity_res)): ?>
<tr>
<td><?= ucfirst($r['type']) ?></td>
<td><?= $r['entity_name'] ?? '-' ?></td>
<td><?= $r['total'] ?></td>
<td>₹ <?= number_format($r['paid'],2) ?></td>
</tr>
<?php endwhile; ?>
</table>
</div>

</div>
</div>

</body>
</html>