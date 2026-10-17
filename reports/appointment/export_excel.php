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
// FILTERS (same as your PDF)
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

    /* All Appointments */
SUM(
    CASE WHEN a.status IN (
        'Confirmed',
        'Registered',
        'Refund',
        'Done',
        'Prescription'
    ) THEN 1 ELSE 0 END
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

    /* Pending */
    SUM(CASE WHEN a.status = 'Payment Pending' THEN 1 ELSE 0 END) AS pending,

    /* Refund */
    SUM(CASE WHEN a.status = 'Refund' THEN 1 ELSE 0 END) AS refund,
    COALESCE(SUM(CASE WHEN a.status = 'Refund' THEN a.refund_amount ELSE 0 END),0) AS refund_amount,
    
    SUM(CASE WHEN a.status = 'Cancel' THEN 1 ELSE 0 END) AS cancel_count,
COALESCE(
    SUM(CASE WHEN a.status = 'Cancel' THEN a.refund_amount ELSE 0 END),0
) AS cancel_amount,

SUM(CASE WHEN a.status = 'Done' THEN 1 ELSE 0 END) AS done_count,
COALESCE(
    SUM(CASE WHEN a.status = 'Done' THEN a.paid_amount ELSE 0 END),0
) AS done_amount,

SUM(CASE WHEN a.status = 'Prescription' THEN 1 ELSE 0 END) AS prescription_count,
COALESCE(
    SUM(CASE WHEN a.status = 'Prescription' THEN a.paid_amount ELSE 0 END),0
) AS prescription_amount,

    
    /* TOTAL REVENUE = Confirmed + Registered + Refund + Done + Prescription */
COALESCE(
    SUM(
        CASE 
            WHEN a.status IN (
    'Confirmed',
    'Registered',
    'Refund',
    'Done',
    'Prescription'
)
            THEN a.paid_amount 
            ELSE 0 
        END
    ), 0
) AS total_revenue,


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
// CATEGORY WISE
// ────────────────────────────────────────────────
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
    LEFT JOIN service_master sm  ON sm.id = a.entity_id AND a.type = 'service'
    LEFT JOIN department_master dm ON dm.id = a.department_id AND a.type = 'doctor'
    $where
    GROUP BY category_name
    ORDER BY total DESC
";
$category_res = mysqli_query($conn, $category_sql);

// ────────────────────────────────────────────────
// SERVICE WISE
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
$service_sql .= " GROUP BY sm.service_name ORDER BY total DESC";
$service_res = mysqli_query($conn, $service_sql);

// ────────────────────────────────────────────────
// DOCTOR WISE
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
$doctor_sql .= " GROUP BY u.full_name ORDER BY total DESC";
$doctor_res = mysqli_query($conn, $doctor_sql);

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

// ────────────────────────────────────────────────
// EXCEL HEADERS
// ────────────────────────────────────────────────
$filename = "Appointment_Report_{$from}_to_{$to}.xls";
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// ────────────────────────────────────────────────
// OUTPUT (same as PDF – no ₹ symbol)
// ────────────────────────────────────────────────
echo "<!DOCTYPE html><html><head><meta charset='utf-8'></head><body>";

echo "<h2>Appointment Report</h2>";
echo "<p>Date Range: $from to $to</p>";

// KPI Summary
echo "<table border='1' cellspacing='0' cellpadding='5'>";
echo "
<tr style='background:#1d4ed8;color:white;'>
    <th>Total</th>
    <th>Pending</th>
    <th>Confirmed</th>
    <th>Refund</th>
    <th>Cancel</th>
    <th>Done</th>
    <th>Prescription</th>
    <th>Registered</th>
    <th>All Appointments</th>
    <th>Total Revenue</th>
    
</tr>";


echo "<tr align='center'>";

echo "<td>
        " . number_format($kpi['total'] ?? 0) . "<br>
        " . number_format($kpi['total_fee'] ?? 0, 2) . "
      </td>";

echo "<td>
        " . number_format($kpi['pending'] ?? 0) . "<br>
        " . number_format($kpi['total_balance'] ?? 0, 2) . "
      </td>";
      
echo "<td>
        " . number_format($kpi['confirmed_count'] ?? 0) . "<br>
        " . number_format($kpi['confirmed_amount'] ?? 0, 2) . "
      </td>";


echo "<td>
        " . number_format($kpi['refund'] ?? 0) . "<br>
        " . number_format($kpi['refund_amount'] ?? 0, 2) . "
      </td>";
    
    echo "<td>
        " . number_format($kpi['cancel_count'] ?? 0) . "<br>
        " . number_format($kpi['cancel_amount'] ?? 0, 2) . "
      </td>";

echo "<td>
        " . number_format($kpi['done_count'] ?? 0) . "<br>
        " . number_format($kpi['done_amount'] ?? 0, 2) . "
      </td>";

echo "<td>
        " . number_format($kpi['prescription_count'] ?? 0) . "<br>
        " . number_format($kpi['prescription_amount'] ?? 0, 2) . "
      </td>";  
echo "<td>
        " . number_format($kpi['registered_count'] ?? 0) . "<br>
        " . number_format($kpi['registered_amount'] ?? 0, 2) . "
      </td>";

echo "<td>
        " . number_format($kpi['all_appointments_count'] ?? 0) . "<br>
        " . number_format($kpi['all_appointments_amount'] ?? 0, 2) . "
      </td>";

echo "<td>
        " . number_format($kpi['total_revenue'] ?? 0, 2) . "
      </td>";


echo "</tr>";

echo "</table><br><br>";

// Category Wise
echo "<h3>Category Wise</h3>";
echo "<table border='1' cellspacing='0' cellpadding='5'>";
echo "<tr style='background:#1d4ed8;color:white;'>
        <th>Category</th>
        <th>Total</th>
        <th>Paid</th>
      </tr>";


if (mysqli_num_rows($category_res) == 0) {
    echo "<tr><td colspan='3' align='center'>No Data</td></tr>";
} else {
    while ($r = mysqli_fetch_assoc($category_res)) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($r['category_name'] ?? '—') . "</td>";
        echo "<td align='center'>" . number_format($r['total'] ?? 0) . "</td>";
        echo "<td align='right'>" . number_format($r['paid'] ?? 0, 2) . "</td>";
        echo "</tr>";
    }
}
echo "</table><br>";

// Service Wise
echo "<h3>Service Wise</h3>";
echo "<table border='1' cellspacing='0' cellpadding='5'>";
echo "<tr style='background:#1d4ed8;color:white;'><th>Service</th><th>Total</th><th>Paid</th></tr>";
if (mysqli_num_rows($service_res) == 0) {
    echo "<tr><td colspan='3' align='center'>No Data</td></tr>";
} else {
    while ($r = mysqli_fetch_assoc($service_res)) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($r['service_name'] ?? '—') . "</td>";
        echo "<td align='center'>" . number_format($r['total'] ?? 0) . "</td>";
        echo "<td align='right'>" . number_format($r['paid'] ?? 0, 2) . "</td>";
        echo "</tr>";
    }
}
echo "</table><br>";

// Doctor Wise
echo "<h3>Doctor Wise</h3>";
echo "<table border='1' cellspacing='0' cellpadding='5'>";
echo "<tr style='background:#1d4ed8;color:white;'><th>Doctor</th><th>Total</th><th>Paid</th></tr>";
if (mysqli_num_rows($doctor_res) == 0) {
    echo "<tr><td colspan='3' align='center'>No Data</td></tr>";
} else {
    while ($r = mysqli_fetch_assoc($doctor_res)) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($r['doctor_name'] ?? '—') . "</td>";
        echo "<td align='center'>" . number_format($r['total'] ?? 0) . "</td>";
        echo "<td align='right'>" . number_format($r['paid'] ?? 0, 2) . "</td>";
        echo "</tr>";
    }
}
echo "</table><br>";

// Entity Wise
echo "<h3>Entity Wise</h3>";
echo "<table border='1' cellspacing='0' cellpadding='5'>";
echo "<tr style='background:#1d4ed8;color:white;'><th>Type</th><th>Entity</th><th>Total</th><th>Paid</th></tr>";
if (mysqli_num_rows($entity_res) == 0) {
    echo "<tr><td colspan='4' align='center'>No Data</td></tr>";
} else {
    while ($r = mysqli_fetch_assoc($entity_res)) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars(ucfirst($r['type'] ?? '—')) . "</td>";
        echo "<td>" . htmlspecialchars($r['entity_name'] ?? '—') . "</td>";
        echo "<td align='center'>" . number_format($r['total'] ?? 0) . "</td>";
        echo "<td align='right'>" . number_format($r['paid'] ?? 0, 2) . "</td>";
        echo "</tr>";
    }
}
echo "</table>";

echo "</body></html>";
exit;