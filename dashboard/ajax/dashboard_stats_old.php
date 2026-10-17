<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';

/* SECURITY */
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

header('Content-Type: application/json');

/* ===============================
   INPUTS
================================ */
$source = strtolower(trim($_POST['source'] ?? 'all'));
$dateFilter = strtolower($_POST['date_filter'] ?? 'current_month');
$createdDateFilter = strtolower($_POST['created_date_filter'] ?? 'all');
$doctor      = $_POST['doctor'] ?? '';
$department  = $_POST['department'] ?? '';
$branch      = $_POST['branch'] ?? '';
$hospital    = $_POST['hospital'] ?? '';
$fromDate    = $_POST['from_date'] ?? '';
$toDate      = $_POST['to_date'] ?? '';

/* ===============================
   DATE RANGE LOGIC
================================ */
$applyDateFilter = true;
$today = date('Y-m-d');

if ($applyDateFilter) {

    switch ($dateFilter) {
        case 'all':
    $from = '2000-01-01';
    $to   = '9999-12-31';
    break;

        case 'today':
            $from = $to = $today;
            break;

        case 'yesterday':
            $from = $to = date('Y-m-d', strtotime('-1 day'));
            break;

        case 'tomorrow':
            $from = $to = date('Y-m-d', strtotime('+1 day'));
            break;

        case 'current_week':
            $from = date('Y-m-d', strtotime('monday this week'));
            $to   = date('Y-m-d', strtotime('sunday this week'));
            break;

        case 'current_month':
            $from = date('Y-m-01');
            $to   = date('Y-m-t');
            break;

        case 'last_month':
            $from = date('Y-m-01', strtotime('first day of last month'));
            $to   = date('Y-m-t', strtotime('last month'));
            break;

        case 'last_3_months':
            $from = date('Y-m-d', strtotime('-3 months'));
            $to   = $today;
            break;

        case 'last_6_months':
            $from = date('Y-m-d', strtotime('-6 months'));
            $to   = $today;
            break;

        case 'current_year':
            $from = date('Y-01-01');
            $to   = date('Y-12-31');
            break;

        case 'last_year':
            $from = date('Y-01-01', strtotime('-1 year'));
            $to   = date('Y-12-31', strtotime('-1 year'));
            break;
            
            default:
    $from = '2000-01-01';
    $to   = '9999-12-31';
    break;

        
    }
}

/* ===============================
   CREATED DATE RANGE LOGIC
================================ */

$applyCreatedDateFilter = true;

/* If user selected "All Created Dates", do NOT filter */
if ($createdDateFilter === 'all') {
    $applyCreatedDateFilter = false;
}

if ($applyCreatedDateFilter) {

    switch ($createdDateFilter) {

        case 'today':
            $c_from = $today;
            $c_to   = $today;
            break;

        case 'yesterday':
            $c_from = date('Y-m-d', strtotime('-1 day'));
            $c_to   = $c_from;
            break;

        case 'current_week':
            $c_from = date('Y-m-d', strtotime('monday this week'));
            $c_to   = date('Y-m-d', strtotime('sunday this week'));
            break;

        case 'current_month':
            $c_from = date('Y-m-01');
            $c_to   = date('Y-m-t');
            break;

        case 'last_month':
            $c_from = date('Y-m-01', strtotime('first day of last month'));
            $c_to   = date('Y-m-t', strtotime('last month'));
            break;

        case 'last_3_months':
            $c_from = date('Y-m-d', strtotime('-3 months'));
            $c_to   = $today;
            break;

        case 'last_6_months':
            $c_from = date('Y-m-d', strtotime('-6 months'));
            $c_to   = $today;
            break;

        case 'current_year':
            $c_from = date('Y-01-01');
            $c_to   = date('Y-12-31');
            break;

        case 'last_year':
            $c_from = date('Y-01-01', strtotime('-1 year'));
            $c_to   = date('Y-12-31', strtotime('-1 year'));
            break;
    }
}

/* ===============================
   BASE QUERY
================================ */
$sql = "
SELECT
    COUNT(a.id) AS total_appointments,

    COALESCE(SUM(a.consultation_fee), 0) AS total_fee,

    /* Pending */
    SUM(CASE WHEN a.status = 'Payment Pending' THEN 1 ELSE 0 END) AS pending_payment_count,
    COALESCE(SUM(CASE WHEN a.status = 'Payment Pending' THEN a.balance_amount ELSE 0 END),0) AS pending_payment_amount,

    /* Confirmed */
    SUM(CASE WHEN a.status = 'Confirmed' THEN 1 ELSE 0 END) AS appointments,
    COALESCE(SUM(CASE WHEN a.status = 'Confirmed' THEN a.paid_amount ELSE 0 END),0) AS revenue,

    /* Registered */
    SUM(CASE WHEN a.status = 'Registered' THEN 1 ELSE 0 END) AS registered_appointments,
    COALESCE(SUM(CASE WHEN a.status = 'Registered' THEN a.paid_amount ELSE 0 END), 0) AS registered_amount,
    
/* Admit Advice */
SUM(CASE WHEN a.status = 'Admit Advice' THEN 1 ELSE 0 END) AS admit_advice_count,
COALESCE(SUM(CASE WHEN a.status = 'Admit Advice' THEN a.consultation_fee ELSE 0 END), 0) AS admit_advice_amount,

/* IPD Admission Source */
SUM(CASE WHEN a.status = 'Admitted' AND TRIM(COALESCE(a.opd_no, '')) <> '' THEN 1 ELSE 0 END) AS opd_to_ipd_count,
COALESCE(SUM(CASE WHEN a.status = 'Admitted' AND TRIM(COALESCE(a.opd_no, '')) <> '' THEN a.paid_amount ELSE 0 END), 0) AS opd_to_ipd_amount,

SUM(CASE WHEN a.status = 'Admitted' AND TRIM(COALESCE(a.opd_no, '')) = '' THEN 1 ELSE 0 END) AS direct_ipd_count,
COALESCE(SUM(CASE WHEN a.status = 'Admitted' AND TRIM(COALESCE(a.opd_no, '')) = '' THEN a.paid_amount ELSE 0 END), 0) AS direct_ipd_amount,

  /* All Appointments */
COUNT(a.id) AS all_appointments,

COALESCE(
    SUM(
        CASE 
            WHEN a.status IN (
                'Confirmed',
                'Registered',
                'Refund',
                'Done',
                'Prescription',
'Admit Advice',
'Admitted'
            )
            THEN a.paid_amount
            ELSE 0
        END
    ), 0
) AS all_appointments_amount,

    /* Refund */
SUM(CASE WHEN a.status = 'Refund' THEN 1 ELSE 0 END) AS refund_count,
COALESCE(SUM(CASE WHEN a.status = 'Refund' THEN a.refund_amount ELSE 0 END),0) AS refund,

/* Cancel */
SUM(CASE WHEN a.status = 'Cancel' THEN 1 ELSE 0 END) AS cancel_count,
COALESCE(SUM(CASE WHEN a.status = 'Cancel' THEN a.refund_amount ELSE 0 END),0) AS cancel_amount,

/* Done */
SUM(CASE WHEN a.status = 'Done' THEN 1 ELSE 0 END) AS done_count,
COALESCE(SUM(CASE WHEN a.status = 'Done' THEN a.paid_amount ELSE 0 END),0) AS done_amount,

/* Prescription */
SUM(CASE WHEN a.status = 'Prescription' THEN 1 ELSE 0 END) AS prescription_count,
COALESCE(SUM(CASE WHEN a.status = 'Prescription' THEN a.paid_amount ELSE 0 END),0) AS prescription_amount

FROM appointments a
WHERE 1=1
";

$params = [];
$types  = "";

/* APPLY DATE FILTER */
if ($applyDateFilter) {
    $sql .= " AND a.appointment_date BETWEEN ? AND ? ";
    $params[] = $from;
    $params[] = $to;
    $types   .= "ss";
}

/* APPLY CREATED DATE FILTER */
if ($applyCreatedDateFilter) {
    $sql .= " AND DATE(a.created_at) BETWEEN ? AND ? ";
    $params[] = $c_from;
    $params[] = $c_to;
    $types   .= "ss";
}

/* ===============================
   DYNAMIC FILTERS
================================ */
/* ===============================
   SOURCE FILTER
================================ */
if ($source === 'website') {

    $sql .= " AND a.source = ? ";
    $params[] = 'WEBSITE';
    $types .= "s";

} elseif ($source === 'direct') {

    $sql .= " AND a.source = ? ";
    $params[] = 'INTERNAL';
    $types .= "s";
}

/* DOCTOR FILTER */
if (!empty($doctor)) {
    $sql .= " AND a.type = 'doctor' AND a.entity_id = ? ";
    $params[] = $doctor;
    $types   .= "i";
}

/* DEPARTMENT FILTER */
if (!empty($department)) {
    $sql .= "
        AND EXISTS (
            SELECT 1
            FROM user_departments ud
            WHERE ud.user_id = a.entity_id
            AND ud.department_id = ?
        )
    ";
    $params[] = $department;
    $types   .= "i";
}

/* BRANCH FILTER */
if (!empty($branch)) {
    $sql .= "
        AND EXISTS (
            SELECT 1
            FROM user_branches ub
            WHERE ub.user_id = a.entity_id
            AND ub.branch_id = ?
        )
    ";
    $params[] = $branch;
    $types   .= "i";
}

/* HOSPITAL FILTER */
if (!empty($hospital)) {
    $sql .= "
        AND EXISTS (
            SELECT 1
            FROM user_hospitals uh
            WHERE uh.user_id = a.entity_id
            AND uh.hospital_id = ?
        )
    ";
    $params[] = $hospital;
    $types   .= "i";
}



/* ===============================
   EXECUTE
================================ */
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

$netRevenue = $result['revenue']; 

/* ===============================
   RESPONSE
================================ */
echo json_encode([
    
    'total_appointments'      => (int)$result['total_appointments'],
    'total_fee'               => number_format($result['total_fee'], 2),

    'pending_payment_count'   => (int)$result['pending_payment_count'],
    'pending_payment_amount'  => number_format($result['pending_payment_amount'], 2),
    
    'appointments'  => (int)$result['appointments'],
    'registered_appointments' => (int)$result['registered_appointments'],
    'registered_amount'       => number_format($result['registered_amount'], 2),
    
    'admit_advice_count'  => (int)$result['admit_advice_count'],
    'admit_advice_amount' => number_format($result['admit_advice_amount'], 2),

    'revenue'       => number_format($result['revenue'], 2),
    'refund'        => number_format($result['refund'], 2),
    'refund_count'  => (int)$result['refund_count'],
    'net_revenue'   => number_format($netRevenue, 2), 
    
    'all_appointments'        => (int)$result['all_appointments'],
    'all_appointments_amount' => number_format($result['all_appointments_amount'], 2),

    'cancel_count'  => (int)$result['cancel_count'],
    'cancel_amount' => number_format($result['cancel_amount'], 2),
    
    'done_count'         => (int)$result['done_count'],
    'done_amount'        => number_format($result['done_amount'], 2),
    
    'opd_to_ipd_count' => (int)$result['opd_to_ipd_count'],
'opd_to_ipd_amount' => number_format($result['opd_to_ipd_amount'], 2),

'direct_ipd_count' => (int)$result['direct_ipd_count'],
'direct_ipd_amount' => number_format($result['direct_ipd_amount'], 2),

    'prescription_count' => (int)$result['prescription_count'],
    'prescription_amount'=> number_format($result['prescription_amount'], 2)
]);

