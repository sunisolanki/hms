<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '1');
ini_set('session.use_strict_mode', '1');
if (empty($_SERVER['HTTPS'])) {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit;
}
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();
if (empty($_SESSION['patient_id']) || empty($_SESSION['patient_tenant_id'])) {
    header('Location: /patient/login.php');
    exit;
}
$patientId = (int)$_SESSION['patient_id'];
$hospitalId = $_SESSION['patient_hospital_id'] ?? '';
$hospitalName = $_SESSION['patient_hospital_name'] ?? '';
$patientName = $_SESSION['patient_name'] ?? '';
$patientMobile = $_SESSION['patient_mobile'] ?? '';
$patientEmail = $_SESSION['patient_email'] ?? '';
$patientUsername = $_SESSION['patient_username'] ?? $patientMobile;
$patientInitial = strtoupper(substr(trim($patientName), 0, 1));
if ($patientInitial === '') {
    $patientInitial = 'P';
}
$page = strtolower(trim((string)($_GET['page'] ?? 'home')));
$allowedPages = ['home', 'appointments', 'opd', 'ipd', 'prescriptions', 'billing', 'invoice', 'telemedicine', 'profile'];
if (!in_array($page, $allowedPages, true)) {
    $page = 'home';
}
$profile = null;
if ($page === 'profile') {
    if (empty($_SESSION['tenant_id']) && !empty($_SESSION['patient_tenant_id'])) {
        $_SESSION['tenant_id'] = $_SESSION['patient_tenant_id'];
    }
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';
    if (!$conn || $conn->connect_errno) {
        die('Database connection failed.');
    }
    $stmt = $conn->prepare("
        SELECT
            pm.id,
            pm.uhid,
            pm.title,
            pm.first_name,
            pm.last_name,
            pm.sex,
            pm.marital_status,
            pm.guardian_name,
            pm.aadhaar,
            pm.mobile,
            pm.phone,
            pm.email,
            pm.dob,
            pm.age_y,
            pm.age_m,
            pm.age_d,
            pm.city_id,
            pm.pincode,
            pm.address,
            c.name AS city_name
        FROM patient_master pm
        LEFT JOIN cities c
            ON c.id = pm.city_id
        WHERE pm.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
/* =========================================================
   HOME PAGE DATA
========================================================= */
$homeUpcomingAppointment = null;
$homeRecentActivities = [];

if ($page === 'home') {
    if (empty($_SESSION['tenant_id']) && !empty($_SESSION['patient_tenant_id'])) {
        $_SESSION['tenant_id'] = $_SESSION['patient_tenant_id'];
    }

    require $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';

    if (!$conn || $conn->connect_errno) {
        die('Database connection failed.');
    }

    /* NEXT UPCOMING APPOINTMENT */
    $stmt = $conn->prepare("
        SELECT
            a.id,
            a.appointment_date,
            a.appointment_time,
            a.appointment_code,
            a.opd_no,
            a.consultation_mode,
            a.status,
            d.department_name,
            u.full_name AS doctor_name
        FROM appointments a
        LEFT JOIN department_master d
            ON d.id = a.department_id
        LEFT JOIN users u
            ON u.id = a.entity_id
            AND a.type = 'doctor'
        WHERE a.patient_id = ?
          AND a.type = 'doctor'
          AND a.appointment_date >= CURDATE()
          AND a.status NOT IN ('Cancelled', 'Canceled', 'Completed')
        ORDER BY
            a.appointment_date ASC,
            a.appointment_time ASC,
            a.id ASC
        LIMIT 1
    ");
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $homeUpcomingAppointment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

   /* RECENT ACTIVITY */
$recentActivityQueries = [
    [
        "SELECT
            a.appointment_date AS activity_date,
            'OPD Visit' AS activity_type,
            CONCAT(
                'OPD No. ',
                COALESCE(NULLIF(a.opd_no, ''), '-'),
                ' - ',
                COALESCE(NULLIF(u.full_name, ''), 'Doctor')
            ) AS activity_details
        FROM appointments a
        LEFT JOIN users u
            ON u.id = a.entity_id
            AND a.type = 'doctor'
        WHERE a.patient_id = ?
          AND a.type = 'doctor'
          AND a.opd_no IS NOT NULL
          AND a.opd_no <> ''
        ORDER BY a.appointment_date DESC, a.id DESC
        LIMIT 5",
        'i',
        [$patientId]
    ],
    [
        "SELECT
            p.created_at AS activity_date,
            'Prescription' AS activity_type,
            CONCAT(
                'Prescription for ',
                COALESCE(NULLIF(a.opd_no, ''), 'OPD Visit')
            ) AS activity_details
        FROM opd_prescriptions p
        INNER JOIN appointments a
            ON a.id = p.appointment_id
        WHERE a.patient_id = ?
        ORDER BY p.created_at DESC, p.id DESC
        LIMIT 5",
        'i',
        [$patientId]
    ],
    [
        "SELECT
            ob.bill_date AS activity_date,
            'Billing' AS activity_type,
            CONCAT(
                'OPD Bill ',
                COALESCE(NULLIF(ob.bill_no, ''), '-'),
                ' - ₹',
                FORMAT(COALESCE(ob.net_payable, 0), 2)
            ) AS activity_details
        FROM opd_bills ob
        WHERE ob.patient_id = ?
        ORDER BY ob.bill_date DESC, ob.id DESC
        LIMIT 5",
        'i',
        [$patientId]
    ],
    [
        "SELECT
            ib.bill_date AS activity_date,
            'Billing' AS activity_type,
            CONCAT(
                'IPD Bill ',
                COALESCE(NULLIF(ib.bill_no, ''), '-'),
                ' - ₹',
                FORMAT(COALESCE(ib.net_amount, 0), 2)
            ) AS activity_details
        FROM ipd_bills ib
        WHERE ib.patient_id = ?
        ORDER BY ib.bill_date DESC, ib.id DESC
        LIMIT 5",
        'i',
        [$patientId]
    ]
];

foreach ($recentActivityQueries as $activityQuery) {
    $stmt = $conn->prepare($activityQuery[0]);
    $stmt->bind_param($activityQuery[1], ...$activityQuery[2]);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $homeRecentActivities[] = $row;
    }

    $stmt->close();
}

usort($homeRecentActivities, function ($a, $b) {
    $dateA = strtotime((string)$a['activity_date']);
    $dateB = strtotime((string)$b['activity_date']);

    return $dateB <=> $dateA;
});

$homeRecentActivities = array_slice($homeRecentActivities, 0, 5);

    /* RECENT APPOINTMENTS FOR HOME DASHBOARD */
    $homeRecentAppointments = [];
    $stmt = $conn->prepare("
        SELECT
            a.id,
            a.appointment_date,
            a.appointment_time,
            a.status,
            a.consultation_mode,
            d.department_name,
            u.full_name AS doctor_name
        FROM appointments a
        LEFT JOIN department_master d
            ON d.id = a.department_id
        LEFT JOIN users u
            ON u.id = a.entity_id
            AND a.type = 'doctor'
        WHERE a.patient_id = ?
          AND a.type = 'doctor'
          AND (
              a.appointment_date < CURDATE()
              OR (
                  a.appointment_date = CURDATE()
                  AND a.appointment_time < CURTIME()
              )
          )
        ORDER BY a.appointment_date DESC, a.appointment_time DESC, a.id DESC
        LIMIT 4
    ");
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $homeRecentAppointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
/* =========================================================
   APPOINTMENTS
========================================================= */
if ($page === 'appointments') {
    if (empty($_SESSION['tenant_id']) && !empty($_SESSION['patient_tenant_id'])) {
        $_SESSION['tenant_id'] = $_SESSION['patient_tenant_id'];
    }
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';
    if (!$conn || $conn->connect_errno) {
        die('Database connection failed.');
    }

    $appointments = [];
    $stmt = $conn->prepare("
        SELECT
            a.id,
            a.patient_id,
            a.visit_type,
            a.is_emergency,
            a.appointment_code,
            a.barcode_no,
            a.token_no,
            a.appointment_seq,
            a.type,
            a.consultation_mode,
            a.department_id,
            a.entity_id,
            a.appointment_date,
            a.appointment_time,
            a.title,
            a.first_name,
            a.last_name,
            a.sex,
            a.marital_status,
            a.guardian_name,
            a.aadhaar,
            a.mobile,
            a.phone,
            a.email,
            a.dob,
            a.age_y,
            a.age_m,
            a.age_d,
            a.city_id,
            a.pincode,
            a.address,
            a.remarks,
            a.consultation_fee,
            a.payment_mode,
            a.paid_amount,
            a.balance_amount,
            a.payment_txnid,
            a.created_by,
            a.created_at,
            a.status,
            a.source,
            a.updated_at,
            a.refund_amount,
            a.refund_mode,
            a.refund_by,
            a.refund_datetime,
            a.referral_type,
            a.internal_referral_id,
            a.external_referral_id,
            a.empanelment_id,
            a.discount_type,
            a.discount_percent,
            a.discount_amount,
            a.camp,
            a.net_payable,
            a.opd_no,
            a.ipd_no,
            d.department_name,
            dm.id AS doctor_id,
            u.full_name AS doctor_name,
            u.employee_id AS doctor_employee_id
        FROM appointments a
        LEFT JOIN department_master d
            ON d.id = a.department_id
        LEFT JOIN users u
    ON u.id = a.entity_id
LEFT JOIN doctor_master dm
    ON dm.user_id = u.id
        WHERE a.patient_id = ?
        ORDER BY
            a.appointment_date DESC,
            a.id DESC
    ");
    $stmt->bind_param("i", $patientId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $appointments[] = $row;
    }
    $stmt->close();

    $totalAppointments = count($appointments);
    $upcomingCount = 0;
    $completedCount = 0;
    $cancelledCount = 0;

    $today = date('Y-m-d');

    foreach ($appointments as $appointment) {
        $status = strtolower(trim((string)$appointment['status']));
        $appointmentDate = $appointment['appointment_date'];

        if (
            $appointmentDate >= $today &&
            !in_array($status, ['cancelled', 'canceled', 'completed'], true)
        ) {
            $upcomingCount++;
        }

        if ($status === 'completed') {
            $completedCount++;
        }

        if (in_array($status, ['cancelled', 'canceled'], true)) {
            $cancelledCount++;
        }
    }
}

/* =========================================================
   OPD
========================================================= */
if ($page === 'opd') {
    if (empty($_SESSION['tenant_id']) && !empty($_SESSION['patient_tenant_id'])) {
        $_SESSION['tenant_id'] = $_SESSION['patient_tenant_id'];
    }
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';
    if (!$conn || $conn->connect_errno) {
        die('Database connection failed.');
    }
    $opdVisits = [];
    $stmt = $conn->prepare("
        SELECT
            a.id, a.opd_no, a.appointment_code, a.appointment_seq, a.token_no,
            a.appointment_date, a.appointment_time, a.visit_type, a.consultation_mode,
            a.status, a.is_emergency, a.remarks, a.consultation_fee, a.paid_amount,
            a.balance_amount, a.payment_mode, a.net_payable,
            d.department_name, u.full_name AS doctor_name,
            CASE WHEN tc.id IS NULL THEN 0 ELSE 1 END AS has_telemedicine
        FROM appointments a
        LEFT JOIN department_master d ON d.id = a.department_id
        LEFT JOIN users u ON u.id = a.entity_id AND a.type = 'doctor'
        LEFT JOIN telemedicine_consultations tc ON tc.appointment_id = a.id
        WHERE a.patient_id = ?
          AND a.type = 'doctor'
          AND a.opd_no IS NOT NULL
          AND a.opd_no <> ''
        ORDER BY a.appointment_date DESC, a.id DESC
    ");
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $opdVisits[] = $row;
    }
    $stmt->close();
}

/* =========================================================
   TELEMEDICINE
========================================================= */
if ($page === 'telemedicine') {
    if (empty($_SESSION['tenant_id']) && !empty($_SESSION['patient_tenant_id'])) {
        $_SESSION['tenant_id'] = $_SESSION['patient_tenant_id'];
    }
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';
    if (!$conn || $conn->connect_errno) {
        die('Database connection failed.');
    }

    $consultation = null;
    $telemedicineAppointmentId = (int)($_GET['appointment_id'] ?? 0);

    if ($telemedicineAppointmentId > 0) {
        $stmt = $conn->prepare("
            SELECT a.id, a.appointment_code, a.opd_no, a.appointment_date,
                   a.appointment_time, a.consultation_mode, a.status, a.patient_id,
                   u.full_name AS doctor_name, d.department_name,
                   tc.room_name, tc.room_status
            FROM appointments a
            LEFT JOIN users u ON u.id = a.entity_id AND a.type = 'doctor'
            LEFT JOIN department_master d ON d.id = a.department_id
            INNER JOIN telemedicine_consultations tc ON tc.appointment_id = a.id
            WHERE a.id = ?
              AND a.patient_id = ?
              AND a.type = 'doctor'
              AND a.consultation_mode = 'TELEMEDICINE'
              AND tc.room_status IN ('scheduled','waiting','active')
            LIMIT 1
        ");
        $stmt->bind_param('ii', $telemedicineAppointmentId, $patientId);
        $stmt->execute();
        $consultation = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

/* =========================================================
   IPD
========================================================= */
if ($page === 'ipd') {
    if (empty($_SESSION['tenant_id']) && !empty($_SESSION['patient_tenant_id'])) {
        $_SESSION['tenant_id'] = $_SESSION['patient_tenant_id'];
    }
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';
    if (!$conn || $conn->connect_errno) {
        die('Database connection failed.');
    }

    $ipdRecords = [];

    $stmt = $conn->prepare("
        SELECT
            ia.id,
            ia.appointment_id,
            ia.patient_id,
            ia.ipd_no,
            ia.file_no,
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
            ia.doctor_id,
            ia.department_id,
            u.full_name AS doctor_name,
            d.department_name,

            ira.id AS allocation_id,
            ira.building_id,
            ira.floor_id,
            ira.room_id,
            ira.bed_id,
            ira.admission_reason,
            ira.primary_diagnosis,
            ira.attendant_name,
            ira.attendant_mobile,
            ira.expected_stay,
            ira.initial_deposit AS allocation_deposit,
            ira.allocated_date,
            ira.released_date,
            ira.status AS allocation_status,

            rm.room_number,
            rm.room_name,
            rm.room_type,

            bm.bed_number,
            bm.bed_type,

            COALESCE(bill_totals.total_amount, 0) AS total_amount,
            COALESCE(bill_totals.discount_amount, 0) AS discount_amount,
            COALESCE(bill_totals.net_amount, 0) AS net_amount,
            COALESCE(bill_totals.paid_amount, 0) AS paid_amount,
            COALESCE(bill_totals.balance_amount, 0) AS balance_amount

        FROM ipd_admissions ia

        LEFT JOIN users u
    ON u.id = ia.doctor_id

LEFT JOIN doctor_master dm
    ON dm.user_id = u.id

        LEFT JOIN department_master d
            ON d.id = ia.department_id

        LEFT JOIN ipd_room_allocation ira
            ON ira.id = (
                SELECT ira2.id
                FROM ipd_room_allocation ira2
                WHERE ira2.admission_id = ia.id
                ORDER BY
                    CASE
                        WHEN ira2.status = 'Occupied' THEN 0
                        ELSE 1
                    END,
                    ira2.allocated_date DESC,
                    ira2.id DESC
                LIMIT 1
            )

        LEFT JOIN room_master rm
            ON rm.id = ira.room_id

        LEFT JOIN bed_master bm
            ON bm.id = ira.bed_id

        LEFT JOIN (
            SELECT
                ipd_admission_id,
                SUM(total_amount) AS total_amount,
                SUM(discount_amount) AS discount_amount,
                SUM(net_amount) AS net_amount,
                SUM(paid_amount) AS paid_amount,
                SUM(balance_amount) AS balance_amount
            FROM ipd_bills
            WHERE payment_status <> 'Cancelled'
            GROUP BY ipd_admission_id
        ) bill_totals
            ON bill_totals.ipd_admission_id = ia.id

        WHERE ia.patient_id = ?

        ORDER BY ia.admission_date DESC, ia.id DESC
    ");
    $stmt->bind_param('i', $patientId);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $ipdRecords[] = $row;
    }

    $stmt->close();
}

/* =========================================================
   PRESCRIPTIONS
========================================================= */
if ($page === 'prescriptions') {
    if (empty($_SESSION['tenant_id']) && !empty($_SESSION['patient_tenant_id'])) {
        $_SESSION['tenant_id'] = $_SESSION['patient_tenant_id'];
    }
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';
    if (!$conn || $conn->connect_errno) {
        die('Database connection failed.');
    }

    $prescriptions = [];
    $stmt = $conn->prepare("
        SELECT
            p.id,
            p.appointment_id,
            p.patient_type,
            p.chief_complaint,
            p.drug_history,
            p.past_history,
            p.present_illness,
            p.diagnosis,
            p.medicines,
            p.procedure_advice,
            p.advice,
            p.prognosis,
            p.remarks,
            p.selected_services,
            p.selected_items,
            p.diet_details,
            p.created_at,
            a.opd_no,
            a.ipd_no,
            CASE
                WHEN LOWER(p.patient_type) = 'ipd' THEN ipdDepartment.department_name
                ELSE opdDepartment.department_name
            END AS department_name,
            CASE
                WHEN LOWER(p.patient_type) = 'ipd' THEN ipdDoctor.full_name
                ELSE opdDoctor.full_name
            END AS doctor_name
        FROM opd_prescriptions p
        INNER JOIN appointments a
            ON a.id = p.appointment_id
        LEFT JOIN department_master opdDepartment
            ON opdDepartment.id = a.department_id
            AND LOWER(p.patient_type) <> 'ipd'
        LEFT JOIN users opdDoctor
            ON opdDoctor.id = a.entity_id
            AND a.type = 'doctor'
            AND LOWER(p.patient_type) <> 'ipd'
        LEFT JOIN ipd_admissions ia
            ON ia.appointment_id = p.appointment_id
            AND ia.patient_id = ?
            AND LOWER(p.patient_type) = 'ipd'
        LEFT JOIN department_master ipdDepartment
            ON ipdDepartment.id = ia.department_id
        LEFT JOIN users ipdDoctor
    ON ipdDoctor.id = ia.doctor_id
LEFT JOIN doctor_master ipdDm
    ON ipdDm.user_id = ipdDoctor.id
        WHERE
            (
                LOWER(p.patient_type) = 'ipd'
                AND ia.id IS NOT NULL
            )
            OR
            (
                LOWER(p.patient_type) <> 'ipd'
                AND a.patient_id = ?
            )
        ORDER BY p.created_at DESC, p.id DESC
    ");
    $stmt->bind_param('ii', $patientId, $patientId);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $prescriptions[] = $row;
    }

    $stmt->close();
}

/* =========================================================
   BILLING
========================================================= */
if ($page === 'billing') {
    if (empty($_SESSION['tenant_id']) && !empty($_SESSION['patient_tenant_id'])) {
        $_SESSION['tenant_id'] = $_SESSION['patient_tenant_id'];
    }

    require $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';

    if (!$conn || $conn->connect_errno) {
        die('Database connection failed.');
    }

    $billingRecords = [];

    $stmt = $conn->prepare("
        SELECT
            'OPD' COLLATE utf8mb4_unicode_ci AS bill_type,
            ob.id,
            ob.bill_no COLLATE utf8mb4_unicode_ci AS bill_no,
            ob.bill_date,
            ob.opd_id,
            NULL AS ipd_no,
            ob.grand_total AS gross_amount,
            ob.discount_amount,
            ob.net_payable AS net_amount,
            ob.paid_amount,
            ob.balance_amount,
            ob.status COLLATE utf8mb4_unicode_ci AS payment_status,
            ob.remarks COLLATE utf8mb4_unicode_ci AS remarks
        FROM opd_bills ob
        WHERE ob.patient_id = ?

        UNION ALL

        SELECT
            'IPD' COLLATE utf8mb4_unicode_ci AS bill_type,
            ib.id,
            ib.bill_no COLLATE utf8mb4_unicode_ci AS bill_no,
            ib.bill_date,
            NULL AS opd_id,
            ib.ipd_no COLLATE utf8mb4_unicode_ci AS ipd_no,
            ib.total_amount AS gross_amount,
            ib.discount_amount,
            ib.net_amount,
            ib.paid_amount,
            ib.balance_amount,
            ib.payment_status COLLATE utf8mb4_unicode_ci AS payment_status,
            ib.remarks COLLATE utf8mb4_unicode_ci AS remarks
        FROM ipd_bills ib
        WHERE ib.patient_id = ?

        ORDER BY bill_date DESC, id DESC
    ");

    $stmt->bind_param('ii', $patientId, $patientId);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $billingRecords[] = $row;
    }

    $stmt->close();
}

/* =========================================================
   INVOICE
========================================================= */
if ($page === 'invoice') {
    if (empty($_SESSION['tenant_id']) && !empty($_SESSION['patient_tenant_id'])) {
        $_SESSION['tenant_id'] = $_SESSION['patient_tenant_id'];
    }

    require $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';

    if (!$conn || $conn->connect_errno) {
        die('Database connection failed.');
    }

    $invoiceRecords = [];

    $stmt = $conn->prepare("
        SELECT
            'OPD' COLLATE utf8mb4_unicode_ci AS invoice_type,
            ob.id AS bill_id,
            ob.bill_no COLLATE utf8mb4_unicode_ci AS bill_no,
            ob.bill_date,
            ob.patient_id,
            ob.opd_id,
            NULL AS ipd_no,
            ob.grand_total AS gross_amount,
            ob.discount_amount,
            ob.net_payable AS net_amount,
            ob.paid_amount,
            ob.balance_amount,
            ob.status COLLATE utf8mb4_unicode_ci AS payment_status,
            ob.remarks COLLATE utf8mb4_unicode_ci AS remarks,
            ob.doctor_id,
            ob.department_id
        FROM opd_bills ob
        WHERE ob.patient_id = ?

        UNION ALL

        SELECT
            'IPD' COLLATE utf8mb4_unicode_ci AS invoice_type,
            ib.id AS bill_id,
            ib.bill_no COLLATE utf8mb4_unicode_ci AS bill_no,
            ib.bill_date,
            ib.patient_id,
            NULL AS opd_id,
            ib.ipd_no COLLATE utf8mb4_unicode_ci AS ipd_no,
            ib.total_amount AS gross_amount,
            ib.discount_amount,
            ib.net_amount,
            ib.paid_amount,
            ib.balance_amount,
            ib.payment_status COLLATE utf8mb4_unicode_ci AS payment_status,
            ib.remarks COLLATE utf8mb4_unicode_ci AS remarks,
            NULL AS doctor_id,
            NULL AS department_id
        FROM ipd_bills ib
        WHERE ib.patient_id = ?

        ORDER BY bill_date DESC, bill_id DESC
    ");

    $stmt->bind_param('ii', $patientId, $patientId);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $invoiceRecords[] = $row;
    }

    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Patient Portal | <?= htmlspecialchars($hospitalName ?: 'WebSplend', ENT_QUOTES, 'UTF-8') ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="/assets/img/hms_logo.png" type="image/png">
<link rel="stylesheet" href="/patient/style.css">
</head>
<body>
<aside class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">WS</div>
        <div class="sidebar-brand">
            <strong>WebSplend</strong>
            <span>Patient Portal</span>
        </div>
    </div>
    <nav class="sidebar-nav">
        <a href="/patient/dashboard.php?page=home" class="nav-item <?= $page === 'home' ? 'active' : '' ?>">
            <span class="nav-icon">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5v9a1.5 1.5 0 0 1-1.5 1.5H4.5A1.5 1.5 0 0 1 3 19.5z"></path><path d="M9 21v-7h6v7"></path></svg>
            </span>
            <span>Dashboard</span>
        </a>
        <div class="sidebar-section">Appointments</div>
        <a href="/patient/dashboard.php?page=appointments" class="nav-item <?= $page === 'appointments' ? 'active' : '' ?>">
            <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M7 3v4M17 3v4M3 10h18M8 14h2M14 14h2M8 18h2"></path></svg></span>
            <span>Appointment List</span>
            <span class="nav-arrow">›</span>
        </a>
        <div class="sidebar-section">My Health</div>
        <a href="/patient/dashboard.php?page=opd" class="nav-item <?= $page === 'opd' ? 'active' : '' ?>">
            <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 4v7a6 6 0 0 0 12 0V4"></path><path d="M6 4H4M18 4h2M12 17v3M9 20h6"></path><path d="M6 8h3"></path></svg></span>
            <span>OPD Visits</span>
            <span class="nav-arrow">›</span>
        </a>
        <a href="/patient/dashboard.php?page=ipd" class="nav-item <?= $page === 'ipd' ? 'active' : '' ?>">
            <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 18V7h16v11"></path><path d="M3 18h18M7 7V5h10v2M7 12h10M7 15h5"></path></svg></span>
            <span>IPD Records</span>
            <span class="nav-arrow">›</span>
        </a>
        <a href="?page=prescriptions" class="nav-item <?= $page === 'prescriptions' ? 'active' : '' ?>">
            <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 4 12 12-4 4L4 8z"></path><path d="m14 6 4 4M6 14l4 4"></path></svg></span>
            <span>Prescriptions</span>
            <span class="nav-arrow">›</span>
        </a>
        <div class="sidebar-section">Payments</div>
        <a href="?page=billing" class="nav-item <?= $page === 'billing' ? 'active' : '' ?>">
            <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="18" height="13" rx="2"></rect><path d="M3 10h18M7 15h4"></path></svg></span>
            <span>Billing</span>
            <span class="nav-arrow">›</span>
        </a>
        <a href="?page=invoice" class="nav-item <?= $page === 'invoice' ? 'active' : '' ?>">
            <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h9l4 4v14H6z"></path><path d="M14 3v5h5M9 12h6M9 16h6"></path></svg></span>
            <span>Invoice</span>
            <span class="nav-arrow">›</span>
        </a>
        <div class="sidebar-section">Telemedicine</div>
        <a href="/patient/dashboard.php?page=telemedicine" class="nav-item <?= $page === 'telemedicine' ? 'active' : '' ?>">
            <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="7" width="14" height="10" rx="2"></rect><path d="m17 10 4-2v8l-4-2z"></path></svg></span>
            <span>Telemedicine</span>
            <span class="nav-arrow">›</span>
        </a>
        <div class="sidebar-section">Account</div>
        <a href="/patient/dashboard.php?page=profile" class="nav-item <?= $page === 'profile' ? 'active' : '' ?>">
            <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path></svg></span>
            <span>My Profile</span>
            <span class="nav-arrow">›</span>
        </a>
    </nav>
   
</aside>
<main class="main">
    <header class="topbar">
    <div class="brand-mobile">
        <div class="mobile-logo">WS</div>
        <strong>WebSplend</strong>
    </div>
    <div class="topbar-search">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"></circle><path d="m16 16 5 5"></path></svg>
        <input type="text" placeholder="Search appointments, prescriptions, invoices..." aria-label="Search">
    </div>
    <div class="topbar-right">
        <button type="button" class="notification-btn" aria-label="Notifications">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
            <span class="notification-dot"></span>
        </button>
        <a href="/patient/dashboard.php?page=profile" class="top-user profile-link">
            <div class="avatar"><?= htmlspecialchars($patientInitial, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="top-user-info">
                <strong><?= htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8') ?></strong>
                <span><?= htmlspecialchars($patientMobile, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </a>
        <a href="/patient/logout.php" class="header-logout"><span>⎋</span><b>Logout</b></a>
    </div>
</header>
<?php if ($page === 'profile'): ?>
<div class="content">
    <div class="page-header">
        <div>
            <h1>My Profile</h1>
            <p>View your registered patient information.</p>
        </div>
    </div>

    <?php if (!$profile): ?>
        <div class="empty-state">
            <div class="empty-icon">♙</div>
            <h3>Profile Not Found</h3>
            <p>Your patient profile could not be loaded.</p>
        </div>
    <?php else: ?>
        <section class="right-card profile-card">
            <div class="right-card-header">
                <div>
                    <h3>Personal Information</h3>
                    <span>Your registered hospital information</span>
                </div>
            </div>

            <div class="details-grid">
                <div class="detail-box">
                    <div class="detail-label">Patient Name</div>
                    <div class="detail-value">
                        <?= htmlspecialchars(trim(($profile['title'] ?? '') . ' ' . ($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>

                <div class="detail-box">
                    <div class="detail-label">Hospital ID</div>
                    <div class="detail-value">
                        <?= htmlspecialchars((string)$hospitalId, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>

                <div class="detail-box">
                    <div class="detail-label">Patient ID</div>
                    <div class="detail-value">
                        <?= htmlspecialchars((string)$profile['uhid'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>

                <div class="detail-box">
                    <div class="detail-label">Mobile Number</div>
                    <div class="detail-value">
                        <?= htmlspecialchars((string)$profile['mobile'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>

                <div class="detail-box">
                    <div class="detail-label">Email</div>
                    <div class="detail-value">
                        <?= htmlspecialchars($profile['email'] ?: '-', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>

                <div class="detail-box">
                    <div class="detail-label">Gender</div>
                    <div class="detail-value">
                        <?= htmlspecialchars($profile['sex'] ?: '-', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>

                <div class="detail-box">
                    <div class="detail-label">Date of Birth</div>
                    <div class="detail-value">
                        <?php
                        $profileDobText = '-';
                        if (!empty($profile['dob'])) {
                            $profileDobTimestamp = strtotime((string)$profile['dob']);
                            if ($profileDobTimestamp !== false) {
                                $profileDobText = date('d M Y', $profileDobTimestamp);
                            } else {
                                $profileDobText = (string)$profile['dob'];
                            }
                        }
                        ?>
                        <?= htmlspecialchars($profileDobText, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>

                <div class="detail-box">
                    <div class="detail-label">Marital Status</div>
                    <div class="detail-value">
                        <?= htmlspecialchars($profile['marital_status'] ?: '-', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>

                <div class="detail-box">
                    <div class="detail-label">Guardian Name</div>
                    <div class="detail-value">
                        <?= htmlspecialchars($profile['guardian_name'] ?: '-', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>

                <div class="detail-box">
                    <div class="detail-label">Phone</div>
                    <div class="detail-value">
                        <?= htmlspecialchars($profile['phone'] ?: '-', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>

                <div class="detail-box">
                    <div class="detail-label">City</div>
                    <div class="detail-value">
                        <?= htmlspecialchars($profile['city_name'] ?: '-', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>

                <div class="detail-box">
                    <div class="detail-label">Pincode</div>
                    <div class="detail-value">
                        <?= htmlspecialchars($profile['pincode'] ?: '-', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>

                <div class="detail-box wide">
                    <div class="detail-label">Address</div>
                    <div class="detail-value">
                        <?= htmlspecialchars($profile['address'] ?: '-', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>
</div>
<?php elseif ($page === 'appointments'): ?>
<div class="content">
    <div class="page-header">
        <div>
            <h1>My Appointments</h1>
            <p>View your appointment details and print appointment.</p>
        </div>
        <a href="#" class="book-btn">＋ &nbsp; Add Appointment</a>
    </div>

    <?php if (empty($appointments)): ?>
        <div class="empty-state">
            <div class="empty-icon">▣</div>
            <h3>No Appointments Found</h3>
            <p>You do not have any appointments yet.</p>
            <a href="#" class="book-btn">＋ &nbsp; Book Appointment</a>
        </div>
    <?php else: ?>

        <section class="patient-appointment-table-card">
            <div class="patient-appointment-table-wrap">
                <table class="patient-appointment-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Type</th>
                            <th>Mode</th>
                            <th>Dept</th>
                            <th>Doctor</th>
                            <th>Token No.</th>
                            <th>Visit Type</th>
                            <th>Status</th>
                            <th>Fee</th>
                            <th>Payment Mode</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>

                    <?php foreach ($appointments as $appointment): ?>

                        <?php
                        $status = strtolower(trim((string)$appointment['status']));

                        if (in_array($status, ['cancelled', 'canceled'], true)) {
                            $statusClass = 'status-cancelled';
                        } elseif ($status === 'completed') {
                            $statusClass = 'status-completed';
                        } elseif (in_array($status, ['confirmed', 'booked', 'scheduled', 'pending'], true)) {
                            $statusClass = 'status-confirmed';
                        } else {
                            $statusClass = 'status-default';
                        }

                        $dateTimestamp = strtotime(
                            $appointment['appointment_date'] . ' ' .
                            $appointment['appointment_time']
                        );

                        $displayDate = $dateTimestamp
                            ? date('d M Y', $dateTimestamp)
                            : date('d M Y', strtotime($appointment['appointment_date']));

                        $displayTime = $appointment['appointment_time'] ?: '-';

                        $doctorName = trim((string)($appointment['doctor_name'] ?? ''));
                        if ($doctorName === '') {
                            $doctorName = 'Doctor not assigned';
                        }

                        $departmentName = trim((string)($appointment['department_name'] ?? ''));
                        if ($departmentName === '') {
                            $departmentName = 'Department not assigned';
                        }

                        $typeLabel = ucfirst(strtolower((string)$appointment['type']));

                        $mode = strtoupper(
                            (string)($appointment['consultation_mode'] ?? 'PHYSICAL')
                        );

                        $modeClass = $mode === 'TELEMEDICINE'
                            ? 'mode-tele'
                            : 'mode-physical';

                        $modeLabel = $mode === 'TELEMEDICINE'
                            ? 'Telemedicine'
                            : 'Physical';

                        $visitLabel = ucfirst(
                            strtolower((string)$appointment['visit_type'])
                        );

                        $isEmergency = (int)$appointment['is_emergency'] === 1;
                        ?>

                        <tr class="patient-appointment-row">
                            <td>
                                <strong>
                                    <?= htmlspecialchars(
                                        $appointment['appointment_code'] ?: '#' . $appointment['id'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </strong>
                            </td>

                            <td>
                                <?= htmlspecialchars($displayDate, ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($displayTime, ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <td>
                                <span class="patient-mode-badge <?= $modeClass ?>">
                                    <?= htmlspecialchars($modeLabel, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>

                            <td>
                                <?= htmlspecialchars($departmentName, ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($doctorName, ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    (string)($appointment['token_no'] ?? '-'),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($visitLabel, ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <td>
                                <span class="status-badge <?= $statusClass ?>">
                                    <?= htmlspecialchars(
                                        $appointment['status'] ?: 'Unknown',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>

                                <?php if ($isEmergency): ?>
                                    <span class="patient-emergency-badge">
                                        Emergency
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                ₹<?= number_format(
                                    (float)($appointment['consultation_fee'] ?? 0),
                                    2
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $appointment['payment_mode'] ?: '-',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>
                                ₹<?= number_format(
                                    (float)($appointment['paid_amount'] ?? 0),
                                    2
                                ) ?>
                            </td>

                            <td>
                                ₹<?= number_format(
                                    (float)($appointment['balance_amount'] ?? 0),
                                    2
                                ) ?>
                            </td>

                            <td>
                                <div class="patient-appointment-actions">
                                    <button
                                        type="button"
                                        class="patient-expand-btn"
                                        onclick="togglePatientAppointment(this)"
                                    >
                                        Expand
                                    </button>

                                    <a
                                        href="/modules/appointments/appointment_pdf.php?id=<?= (int)$appointment['id'] ?>&print=1"
                                        target="_blank"
                                        class="patient-print-btn"
                                    >
                                        🖨 Print
                                    </a>
                                </div>
                            </td>
                        </tr>

                        <tr class="patient-appointment-details-row">
                            <td colspan="15">
                                <div class="patient-appointment-details">

                                    <div class="patient-details-grid">

                                        <div class="patient-detail-item">
                                            <span>Appointment ID</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $appointment['appointment_code'] ?: '-',
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Mode</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $modeLabel,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Doctor</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $doctorName,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Department</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $departmentName,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Appointment Type</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $typeLabel,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Visit Type</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $visitLabel,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Status</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $appointment['status'] ?: '-',
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Source</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $appointment['source'] ?: '-',
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Token No.</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    (string)($appointment['token_no'] ?? '-'),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>OPD No.</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $appointment['opd_no'] ?: '-',
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>IPD No.</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $appointment['ipd_no'] ?: '-',
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Emergency</span>
                                            <strong>
                                                <?= $isEmergency ? 'Yes' : 'No' ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Created</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $appointment['created_at'] ?: '-',
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Consultation Fee</span>
                                            <strong>
                                                ₹<?= number_format(
                                                    (float)($appointment['consultation_fee'] ?? 0),
                                                    2
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Discount %</span>
                                            <strong>
                                                <?= number_format(
                                                    (float)($appointment['discount_percent'] ?? 0),
                                                    2
                                                ) ?>%
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Discount Amount</span>
                                            <strong>
                                                ₹<?= number_format(
                                                    (float)($appointment['discount_amount'] ?? 0),
                                                    2
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Net Payable</span>
                                            <strong>
                                                ₹<?= number_format(
                                                    (float)($appointment['net_payable'] ?? 0),
                                                    2
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Paid Amount</span>
                                            <strong>
                                                ₹<?= number_format(
                                                    (float)($appointment['paid_amount'] ?? 0),
                                                    2
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Balance Amount</span>
                                            <strong>
                                                ₹<?= number_format(
                                                    (float)($appointment['balance_amount'] ?? 0),
                                                    2
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Payment Mode</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $appointment['payment_mode'] ?: '-',
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Referral Type</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $appointment['referral_type'] ?: '-',
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Empanelment</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    (string)($appointment['empanelment_id'] ?? '-'),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Camp</span>
                                            <strong>
                                                <?= (int)$appointment['camp'] === 1 ? 'Yes' : 'No' ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item patient-detail-wide">
                                            <span>Remarks</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $appointment['remarks'] ?: '-',
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                    </div>

                                    <div class="patient-details-footer">
                                        <a
                                            href="/modules/appointments/appointment_pdf.php?id=<?= (int)$appointment['id'] ?>&print=1"
                                            target="_blank"
                                            class="patient-print-btn"
                                        >
                                            🖨 Print Appointment
                                        </a>
                                    </div>

                                </div>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                    </tbody>
                </table>
            </div>
        </section>

    <?php endif; ?>
</div>
    <?php elseif ($page === 'opd'): ?>
<div class="content">
    <div class="page-header">
        <div>
            <h1>My OPD Visits</h1>
            <p>View your OPD visits, consultation details and print documents.</p>
        </div>
    </div>

    <?php if (empty($opdVisits)): ?>
        <div class="empty-state">
            <div class="empty-icon">♧</div>
            <h3>No OPD Visits Found</h3>
            <p>Your registered OPD visits will appear here.</p>
        </div>
    <?php else: ?>

        <section class="patient-opd-table-card">
            <div class="patient-opd-table-wrap">
                <table class="patient-opd-table">
                    <thead>
                        <tr>
                            <th>OPD No.</th>
                            <th>App. ID</th>
                            <th>Token</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Mode</th>
                            <th>Doctor</th>
                            <th>Dept.</th>
                            <th>Visit Type</th>
                            <th>Status</th>
                            <th>Fee</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Payment Mode</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($opdVisits as $visit): ?>

                        <?php
                        $visitStatus = strtolower(trim((string)$visit['status']));

                        $visitMode = strtoupper(
                            (string)($visit['consultation_mode'] ?? 'PHYSICAL')
                        );

                        $visitDateTime = strtotime(
                            $visit['appointment_date'] . ' ' .
                            $visit['appointment_time']
                        );

                        $visitDate = $visitDateTime
                            ? date('d M Y', $visitDateTime)
                            : date(
                                'd M Y',
                                strtotime($visit['appointment_date'])
                            );

                        $visitTime = $visit['appointment_time'] ?: '-';

                        $visitDoctor = trim(
                            (string)($visit['doctor_name'] ?? '')
                        );

                        if ($visitDoctor === '') {
                            $visitDoctor = 'Doctor not assigned';
                        }

                        $visitDepartment = trim(
                            (string)($visit['department_name'] ?? '')
                        );

                        if ($visitDepartment === '') {
                            $visitDepartment = 'Department not assigned';
                        }

                        $visitType = ucfirst(
                            strtolower(
                                (string)($visit['visit_type'] ?? '-')
                            )
                        );

                        if (in_array($visitStatus, ['cancelled', 'canceled'], true)) {
                            $visitStatusClass = 'status-cancelled';
                        } elseif (
                            in_array(
                                $visitStatus,
                                ['completed', 'done'],
                                true
                            )
                        ) {
                            $visitStatusClass = 'status-completed';
                        } else {
                            $visitStatusClass = 'status-confirmed';
                        }

                        $canJoin =
                            $visitMode === 'TELEMEDICINE' &&
                            (int)($visit['has_telemedicine'] ?? 0) === 1 &&
                            !in_array(
                                $visitStatus,
                                ['cancelled', 'canceled', 'completed', 'done'],
                                true
                            );
                        ?>

                        <tr class="patient-opd-row">

                            <td>
                                <strong>
                                    <?= htmlspecialchars(
                                        $visit['opd_no'] ?: 'OPD Visit #' . $visit['id'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </strong>
                                
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $visit['appointment_code'] ?: '-',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    (string)($visit['token_no'] ?? '-'),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $visitDate,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $visitTime,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>
                                <span class="patient-opd-mode <?= $visitMode === 'TELEMEDICINE' ? 'mode-tele' : 'mode-physical' ?>">
                                    <?= $visitMode === 'TELEMEDICINE'
                                        ? '📹 Telemedicine'
                                        : '🏥 Physical' ?>
                                </span>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $visitDoctor,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $visitDepartment,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $visitType,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>
                                <span class="status-badge <?= $visitStatusClass ?>">
                                    <?= htmlspecialchars(
                                        $visit['status'] ?: 'Unknown',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>

                                <?php if ((int)$visit['is_emergency'] === 1): ?>
                                    <span class="patient-opd-emergency">
                                        Emergency
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                ₹<?= number_format(
                                    (float)($visit['consultation_fee'] ?? 0),
                                    2
                                ) ?>
                            </td>

                            <td>
                                ₹<?= number_format(
                                    (float)($visit['paid_amount'] ?? 0),
                                    2
                                ) ?>
                            </td>

                            <td>
                                ₹<?= number_format(
                                    (float)($visit['balance_amount'] ?? 0),
                                    2
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $visit['payment_mode'] ?: '-',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>
                                <div class="patient-opd-actions">

                                    <button
                                        type="button"
                                        class="patient-expand-btn"
                                        onclick="togglePatientOpd(this)"
                                    >
                                        Expand
                                    </button>

                                    <?php if ($canJoin): ?>
                                        <a
                                            href="/patient/dashboard.php?page=telemedicine&appointment_id=<?= (int)$visit['id'] ?>"
                                            class="patient-join-btn"
                                        >
                                            📹 Join
                                        </a>
                                    <?php endif; ?>

                                    <a
                                        href="/modules/opd/print_op_slip.php?id=<?= (int)$visit['id'] ?>"
                                        target="_blank"
                                        class="patient-print-btn"
                                    >
                                        🖨 Print
                                    </a>

                                </div>
                            </td>

                        </tr>

                        <tr class="patient-opd-details-row">

                            <td colspan="15">

                                <div class="patient-opd-details">

                                    <div class="patient-opd-details-grid">

                                        <div class="patient-detail-item">
                                            <span>OPD No.</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $visit['opd_no'] ?: '-',
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Appointment Code</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $visit['appointment_code'] ?: '-',
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Appointment Sequence</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    (string)($visit['appointment_seq'] ?? '-'),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Token No.</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    (string)($visit['token_no'] ?? '-'),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Date</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $visitDate,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Time</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $visitTime,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Consultation Mode</span>
                                            <strong>
                                                <?= $visitMode === 'TELEMEDICINE'
                                                    ? 'Telemedicine'
                                                    : 'Physical' ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Doctor</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $visitDoctor,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Department</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $visitDepartment,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="patient-detail-item">
                                            <span>Visit Type</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $visitType,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>
                                        <div class="patient-detail-item">
                                            <span>Status</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $visit['status'] ?: '-',
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>
                                        <div class="patient-detail-item">
                                            <span>Emergency</span>
                                            <strong>
                                                <?= (int)$visit['is_emergency'] === 1
                                                    ? 'Yes'
                                                    : 'No' ?>
                                            </strong>
                                        </div>
                                        <div class="patient-detail-item">
                                            <span>Consultation Fee</span>
                                            <strong>
                                                ₹<?= number_format(
                                                    (float)($visit['consultation_fee'] ?? 0),
                                                    2
                                                ) ?>
                                            </strong>
                                        </div>
                                        <div class="patient-detail-item">
                                            <span>Paid Amount</span>
                                            <strong>
                                                ₹<?= number_format(
                                                    (float)($visit['paid_amount'] ?? 0),
                                                    2
                                                ) ?>
                                            </strong>
                                        </div>
                                        <div class="patient-detail-item">
                                            <span>Balance Amount</span>
                                            <strong>
                                                ₹<?= number_format(
                                                    (float)($visit['balance_amount'] ?? 0),
                                                    2
                                                ) ?>
                                            </strong>
                                        </div>
                                        <div class="patient-detail-item">
                                            <span>Payment Mode</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $visit['payment_mode'] ?: '-',
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>
                                        <div class="patient-detail-item patient-detail-wide">
                                            <span>Remarks</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $visit['remarks'] ?: '-',
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </strong>
                                        </div>
                                    </div>
                                    <div class="patient-opd-details-footer">
                                        <a
                                            href="/modules/opd/print_op_slip.php?id=<?= (int)$visit['id'] ?>"
                                            target="_blank"
                                            class="patient-print-btn"
                                        >
                                            🖨 Print OP Receipt
                                        </a>
                                        <?php if (!empty($visit['prescription_id'])): ?>
                                            <a
                                                href="/modules/opd/prescription_pdf.php?appointment_id=<?= (int)$visit['id'] ?>"
                                                target="_blank"
                                                class="patient-prescription-btn"
                                            >
                                                💊 Print Prescription
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>
   <?php elseif ($page === 'ipd'): ?>
<div class="content">
    <div class="page-header">
        <div>
            <h1>My IPD Records</h1>
            <p>View your hospital admissions, room allocation and IPD billing details.</p>
        </div>
    </div>

    <?php if (empty($ipdRecords)): ?>
        <div class="empty-state">
            <div class="empty-icon">▣</div>
            <h3>No IPD Records Found</h3>
            <p>Your IPD admission records will appear here.</p>
        </div>
    <?php else: ?>
        <div class="patient-ipd-table-card">
            <div class="patient-ipd-table-wrap">
                <table class="patient-ipd-table">
                    <thead>
                        <tr>
                            <th>IPD No.</th>
                            <th>File No.</th>
                            <th>Admission Date</th>
                            <th>Time</th>
                            <th>Admission Type</th>
                            <th>Doctor</th>
                            <th>Department</th>
                            <th>Room</th>
                            <th>Bed</th>
                            <th>Status</th>
                            <th>Total Bill</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Payment Type</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ipdRecords as $ipd): ?>
                            <?php
                            $ipdStatus = strtolower(trim((string)($ipd['status'] ?? '')));
                            $ipdDateTime = strtotime(($ipd['admission_date'] ?? '') . ' ' . ($ipd['admission_time'] ?? ''));
                            $ipdDate = $ipdDateTime
                                ? date('d M Y', $ipdDateTime)
                                : '-';
                            $ipdTime = !empty($ipd['admission_time'])
                                ? $ipd['admission_time']
                                : '-';

                            $doctorName = trim((string)($ipd['doctor_name'] ?? ''));
                            $doctorName = $doctorName !== ''
                                ? $doctorName
                                : 'Doctor not assigned';

                            $departmentName = trim((string)($ipd['department_name'] ?? ''));
                            $departmentName = $departmentName !== ''
                                ? $departmentName
                                : 'Department not assigned';

                            $roomName = trim((string)($ipd['room_name'] ?? ''));
                            $roomNumber = trim((string)($ipd['room_number'] ?? ''));
                            $bedNumber = trim((string)($ipd['bed_number'] ?? ''));

                            if ($roomName !== '') {
                                $roomDisplay = $roomName;
                                if ($roomNumber !== '' && $roomNumber !== $roomName) {
                                    $roomDisplay .= ' - ' . $roomNumber;
                                }
                            } elseif ($roomNumber !== '') {
                                $roomDisplay = 'Room ' . $roomNumber;
                            } else {
                                $roomDisplay = 'Room not assigned';
                            }

                            $bedDisplay = $bedNumber !== ''
                                ? 'Bed ' . $bedNumber
                                : 'Bed not assigned';

                            $allocationStatus = trim((string)($ipd['allocation_status'] ?? ''));

                            $statusClass = 'ipd-status-active';

                            if (in_array($ipdStatus, ['discharged', 'completed'], true)) {
                                $statusClass = 'ipd-status-completed';
                            } elseif (in_array($ipdStatus, ['cancelled', 'canceled'], true)) {
                                $statusClass = 'ipd-status-cancelled';
                            }
                            ?>

                            <tr class="patient-ipd-row">
                                <td>
                                    <strong><?= htmlspecialchars($ipd['ipd_no'] ?: '-', ENT_QUOTES, 'UTF-8') ?></strong>
                                    <small>ID #<?= (int)$ipd['id'] ?></small>
                                </td>

                                <td>
                                    <?= htmlspecialchars($ipd['file_no'] ?: '-', ENT_QUOTES, 'UTF-8') ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($ipdDate, ENT_QUOTES, 'UTF-8') ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($ipdTime, ENT_QUOTES, 'UTF-8') ?>
                                </td>

                                <td>
                                    <span class="patient-ipd-admission-type">
                                        🏥 <?= htmlspecialchars(
                                            ucfirst(strtolower((string)($ipd['admission_type'] ?? '-'))),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </span>
                                </td>

                                <td>
                                    <strong><?= htmlspecialchars($doctorName, ENT_QUOTES, 'UTF-8') ?></strong>
                                </td>

                                <td>
                                    <?= htmlspecialchars($departmentName, ENT_QUOTES, 'UTF-8') ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($roomDisplay, ENT_QUOTES, 'UTF-8') ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($bedDisplay, ENT_QUOTES, 'UTF-8') ?>
                                </td>

                                <td>
                                    <span class="patient-ipd-status <?= $statusClass ?>">
                                        <?= htmlspecialchars($ipd['status'] ?: '-', ENT_QUOTES, 'UTF-8') ?>
                                    </span>

                                    <?php if ((int)($ipd['is_emergency'] ?? 0) === 1): ?>
                                        <span class="patient-ipd-emergency">Emergency</span>
                                    <?php endif; ?>

                                    <?php if ((int)($ipd['is_vip'] ?? 0) === 1): ?>
                                        <span class="patient-ipd-vip">VIP</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    ₹<?= number_format((float)($ipd['total_amount'] ?? 0), 2) ?>
                                </td>

                                <td>
                                    ₹<?= number_format((float)($ipd['paid_amount'] ?? 0), 2) ?>
                                </td>

                                <td>
                                    <strong class="<?= (float)($ipd['balance_amount'] ?? 0) > 0 ? 'patient-ipd-balance-due' : 'patient-ipd-balance-clear' ?>">
                                        ₹<?= number_format((float)($ipd['balance_amount'] ?? 0), 2) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?= htmlspecialchars($ipd['payment_type'] ?: '-', ENT_QUOTES, 'UTF-8') ?>
                                </td>

                                <td>
                                    <div class="patient-ipd-actions">
                                        <button
    type="button"
    class="patient-ipd-view-btn"
    onclick="togglePatientIpd(<?= (int)$ipd['id'] ?>, this)">
    Expand
</button>

                                        <?php if (!empty($ipd['appointment_id'])): ?>
                                            <a
                                                href="/modules/ipd/ipd_slip.php?appointment_id=<?= (int)$ipd['appointment_id'] ?>"
                                                target="_blank"
                                                class="patient-ipd-print-btn">
                                                🖨 Print
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                            <tr
                                id="patient-ipd-details-<?= (int)$ipd['id'] ?>"
                                class="patient-ipd-details-row">

                                <td colspan="15">
                                    <div class="patient-ipd-details">
                                        <div class="patient-ipd-details-grid">

                                            <div class="patient-ipd-detail-box">
                                                <span>IPD No.</span>
                                                <strong><?= htmlspecialchars($ipd['ipd_no'] ?: '-', ENT_QUOTES, 'UTF-8') ?></strong>
                                            </div>

                                            <div class="patient-ipd-detail-box">
                                                <span>File No.</span>
                                                <strong><?= htmlspecialchars($ipd['file_no'] ?: '-', ENT_QUOTES, 'UTF-8') ?></strong>
                                            </div>

                                            <div class="patient-ipd-detail-box">
                                                <span>Admission Date</span>
                                                <strong><?= htmlspecialchars($ipdDate, ENT_QUOTES, 'UTF-8') ?></strong>
                                            </div>

                                            <div class="patient-ipd-detail-box">
                                                <span>Admission Time</span>
                                                <strong><?= htmlspecialchars($ipdTime, ENT_QUOTES, 'UTF-8') ?></strong>
                                            </div>

                                            <div class="patient-ipd-detail-box">
                                                <span>Admission Type</span>
                                                <strong><?= htmlspecialchars($ipd['admission_type'] ?: '-', ENT_QUOTES, 'UTF-8') ?></strong>
                                            </div>

                                            <div class="patient-ipd-detail-box">
                                                <span>Status</span>
                                                <strong><?= htmlspecialchars($ipd['status'] ?: '-', ENT_QUOTES, 'UTF-8') ?></strong>
                                            </div>

                                            <div class="patient-ipd-detail-box">
                                                <span>Doctor</span>
                                                <strong><?= htmlspecialchars($doctorName, ENT_QUOTES, 'UTF-8') ?></strong>
                                            </div>

                                            <div class="patient-ipd-detail-box">
                                                <span>Department</span>
                                                <strong><?= htmlspecialchars($departmentName, ENT_QUOTES, 'UTF-8') ?></strong>
                                            </div>

                                            <div class="patient-ipd-detail-box">
                                                <span>Room</span>
                                                <strong><?= htmlspecialchars($roomDisplay, ENT_QUOTES, 'UTF-8') ?></strong>
                                            </div>

                                            <div class="patient-ipd-detail-box">
                                                <span>Bed</span>
                                                <strong><?= htmlspecialchars($bedDisplay, ENT_QUOTES, 'UTF-8') ?></strong>
                                            </div>

                                            <div class="patient-ipd-detail-box">
                                                <span>Room Status</span>
                                                <strong><?= htmlspecialchars($allocationStatus ?: '-', ENT_QUOTES, 'UTF-8') ?></strong>
                                            </div>

                                            <div class="patient-ipd-detail-box">
                                                <span>Expected Stay</span>
                                                <strong>
                                                    <?= $ipd['expected_stay'] !== null && $ipd['expected_stay'] !== ''
                                                        ? (int)$ipd['expected_stay'] . ' Days'
                                                        : '-' ?>
                                                </strong>
                                            </div>

                                            <div class="patient-ipd-detail-box patient-ipd-detail-wide">
                                                <span>Admission Reason</span>
                                                <strong><?= htmlspecialchars($ipd['admission_reason'] ?: '-', ENT_QUOTES, 'UTF-8') ?></strong>
                                            </div>

                                            <div class="patient-ipd-detail-box patient-ipd-detail-wide">
                                                <span>Primary Diagnosis</span>
                                                <strong><?= htmlspecialchars($ipd['primary_diagnosis'] ?: '-', ENT_QUOTES, 'UTF-8') ?></strong>
                                            </div>

                                            <div class="patient-ipd-detail-box">
                                                <span>Total Bill</span>
                                                <strong>₹<?= number_format((float)($ipd['total_amount'] ?? 0), 2) ?></strong>
                                            </div>

                                            <div class="patient-ipd-detail-box">
                                                <span>Discount</span>
                                                <strong>₹<?= number_format((float)($ipd['discount_amount'] ?? 0), 2) ?></strong>
                                            </div>

                                            <div class="patient-ipd-detail-box">
                                                <span>Net Amount</span>
                                                <strong>₹<?= number_format((float)($ipd['net_amount'] ?? 0), 2) ?></strong>
                                            </div>

                                            <div class="patient-ipd-detail-box">
                                                <span>Paid Amount</span>
                                                <strong>₹<?= number_format((float)($ipd['paid_amount'] ?? 0), 2) ?></strong>
                                            </div>

                                            <div class="patient-ipd-detail-box">
                                                <span>Balance Amount</span>
                                                <strong class="<?= (float)($ipd['balance_amount'] ?? 0) > 0 ? 'patient-ipd-balance-due' : 'patient-ipd-balance-clear' ?>">
                                                    ₹<?= number_format((float)($ipd['balance_amount'] ?? 0), 2) ?>
                                                </strong>
                                            </div>

                                            <div class="patient-ipd-detail-box">
                                                <span>Payment Type</span>
                                                <strong><?= htmlspecialchars($ipd['payment_type'] ?: '-', ENT_QUOTES, 'UTF-8') ?></strong>
                                            </div>

                                            <div class="patient-ipd-detail-box">
                                                <span>Initial Deposit</span>
                                                <strong>₹<?= number_format((float)($ipd['initial_deposit'] ?? 0), 2) ?></strong>
                                            </div>

                                            <div class="patient-ipd-detail-box">
                                                <span>Credit Limit</span>
                                                <strong>₹<?= number_format((float)($ipd['credit_limit'] ?? 0), 2) ?></strong>
                                            </div>

                                            <?php if (!empty($ipd['released_date'])): ?>
                                                <div class="patient-ipd-detail-box">
                                                    <span>Released Date</span>
                                                    <strong><?= htmlspecialchars(date('d M Y', strtotime($ipd['released_date'])), ENT_QUOTES, 'UTF-8') ?></strong>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($ipd['attendant_name'])): ?>
                                                <div class="patient-ipd-detail-box">
                                                    <span>Attendant</span>
                                                    <strong><?= htmlspecialchars($ipd['attendant_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($ipd['attendant_mobile'])): ?>
                                                <div class="patient-ipd-detail-box">
                                                    <span>Attendant Mobile</span>
                                                    <strong><?= htmlspecialchars($ipd['attendant_mobile'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                </div>
                                            <?php endif; ?>

                                        </div>

                                        <div class="patient-ipd-details-footer">
                                            <?php if (!empty($ipd['appointment_id'])): ?>
                                                <a
                                                    href="/modules/ipd/ipd_slip.php?appointment_id=<?= (int)$ipd['appointment_id'] ?>"
                                                    target="_blank"
                                                    class="patient-ipd-print-btn">
                                                    🖨 Print IP Slip
                                                </a>
                                            <?php endif; ?>

                                            <button
                                                type="button"
                                                class="patient-ipd-close-btn"
                                                onclick="togglePatientIpd(<?= (int)$ipd['id'] ?>)">
                                                Close
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php elseif ($page === 'prescriptions'): ?>
    <section class="page-section">
        <div class="page-header">
            <div>
                <h1>Prescriptions</h1>
                <p>View your OPD and IPD prescriptions</p>
            </div>
        </div>

        <?php if (empty($prescriptions)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">◈</div>
                <h3>No Prescriptions Found</h3>
                <p>No prescriptions are available for your account.</p>
            </div>
        <?php else: ?>
            <div class="prescription-list">
                <?php foreach ($prescriptions as $prescription): ?>
                    <?php
                    $prescriptionType = strtolower((string)$prescription['patient_type']) === 'ipd' ? 'IPD' : 'OPD';
                    $prescriptionNo = 'RX-' . str_pad((string)$prescription['id'], 6, '0', STR_PAD_LEFT);
                    $prescriptionDate = !empty($prescription['created_at'])
                        ? date('d M Y, h:i A', strtotime($prescription['created_at']))
                        : '-';
                    ?>
                    <div class="prescription-card">
                        <div class="prescription-card-top">
                            <div>
                                <span class="prescription-type <?= strtolower($prescriptionType) ?>">
                                    <?= htmlspecialchars($prescriptionType, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <h3><?= htmlspecialchars($prescriptionNo, ENT_QUOTES, 'UTF-8') ?></h3>
                                <p><?= htmlspecialchars($prescriptionDate, ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <div class="prescription-card-action">
                                <button type="button" class="btn-view-prescription" onclick="togglePrescription(this)">
                                    View Details
                                    <span>›</span>
                                </button>
                            </div>
                        </div>

                        <div class="prescription-summary">
                            <div>
                                <span>Doctor</span>
                                <strong>
                                    <?= htmlspecialchars($prescription['doctor_name'] ?: '-', ENT_QUOTES, 'UTF-8') ?>
                                </strong>
                            </div>
                            <div>
                                <span>Department</span>
                                <strong>
                                    <?= htmlspecialchars($prescription['department_name'] ?: '-', ENT_QUOTES, 'UTF-8') ?>
                                </strong>
                            </div>
                            <div>
                                <span><?= $prescriptionType === 'IPD' ? 'IPD No.' : 'OPD No.' ?></span>
                                <strong>
                                    <?= htmlspecialchars(
                                        $prescriptionType === 'IPD'
                                            ? ($prescription['ipd_no'] ?: '-')
                                            : ($prescription['opd_no'] ?: '-'),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </strong>
                            </div>
                        </div>

                        <div class="prescription-details">
                            <?php if (!empty($prescription['diagnosis'])): ?>
                                <div class="prescription-detail-section">
                                    <h4>Diagnosis</h4>
                                    <p><?= nl2br(htmlspecialchars($prescription['diagnosis'], ENT_QUOTES, 'UTF-8')) ?></p>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($prescription['chief_complaint'])): ?>
                                <div class="prescription-detail-section">
                                    <h4>Chief Complaint</h4>
                                    <p><?= nl2br(htmlspecialchars($prescription['chief_complaint'], ENT_QUOTES, 'UTF-8')) ?></p>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($prescription['present_illness'])): ?>
                                <div class="prescription-detail-section">
                                    <h4>Present Illness</h4>
                                    <p><?= nl2br(htmlspecialchars($prescription['present_illness'], ENT_QUOTES, 'UTF-8')) ?></p>
                                </div>
                            <?php endif; ?>

                            <?php
                            $medicines = [];
                            if (!empty($prescription['medicines'])) {
                                $decodedMedicines = json_decode($prescription['medicines'], true);
                                if (is_array($decodedMedicines)) {
                                    $medicines = $decodedMedicines;
                                }
                            }
                            ?>

                            <?php if (!empty($medicines)): ?>
                                <div class="prescription-detail-section">
                                    <h4>Medicines</h4>
                                    <div class="medicine-table-wrap">
                                        <table class="medicine-table">
                                            <thead>
                                                <tr>
                                                    <th>Medicine</th>
                                                    <th>Frequency</th>
                                                    <th>Meal</th>
                                                    <th>Qty</th>
                                                    <th>Morning</th>
                                                    <th>Afternoon</th>
                                                    <th>Evening</th>
                                                    <th>Comments</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($medicines as $medicine): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($medicine['medicine'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= htmlspecialchars($medicine['frequency'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= htmlspecialchars($medicine['meal'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= htmlspecialchars($medicine['qty'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= htmlspecialchars($medicine['m'] ?? '0', ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= htmlspecialchars($medicine['n'] ?? '0', ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= htmlspecialchars($medicine['e'] ?? '0', ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= htmlspecialchars($medicine['comments'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($prescription['advice'])): ?>
                                <div class="prescription-detail-section">
                                    <h4>Advice</h4>
                                    <p><?= nl2br(htmlspecialchars($prescription['advice'], ENT_QUOTES, 'UTF-8')) ?></p>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($prescription['diet_details'])): ?>
                                <div class="prescription-detail-section">
                                    <h4>Diet Details</h4>
                                    <p><?= nl2br(htmlspecialchars($prescription['diet_details'], ENT_QUOTES, 'UTF-8')) ?></p>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($prescription['remarks'])): ?>
                                <div class="prescription-detail-section">
                                    <h4>Remarks</h4>
                                    <p><?= nl2br(htmlspecialchars($prescription['remarks'], ENT_QUOTES, 'UTF-8')) ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php elseif ($page === 'billing'): ?>
    <section class="page-section">
        <div class="page-header">
            <div>
                <h1>Billing</h1>
                <p>View your OPD and IPD bills</p>
            </div>
        </div>

        <?php if (empty($billingRecords)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">▣</div>
                <h3>No Bills Found</h3>
                <p>No billing records are available for your account.</p>
            </div>
        <?php else: ?>
            <div class="billing-list">
                <?php foreach ($billingRecords as $bill): ?>
                    <?php
                    $billType = strtoupper((string)$bill['bill_type']);
                    $billDate = !empty($bill['bill_date'])
                        ? date('d M Y', strtotime($bill['bill_date']))
                        : '-';
                    $grossAmount = (float)($bill['gross_amount'] ?? 0);
                    $discountAmount = (float)($bill['discount_amount'] ?? 0);
                    $netAmount = (float)($bill['net_amount'] ?? 0);
                    $paidAmount = (float)($bill['paid_amount'] ?? 0);
                    $balanceAmount = (float)($bill['balance_amount'] ?? 0);
                    $status = (string)($bill['payment_status'] ?? '');
                    ?>

                    <div class="billing-card">
                        <div class="billing-card-top">
                            <div>
                                <span class="billing-type <?= strtolower($billType) ?>">
                                    <?= htmlspecialchars($billType, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <h3>
                                    <?= htmlspecialchars($bill['bill_no'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                                </h3>
                                <p><?= htmlspecialchars($billDate, ENT_QUOTES, 'UTF-8') ?></p>
                            </div>

                            <div class="billing-status">
                                <span class="billing-status-badge <?= strtolower($status) ?>">
                                    <?= htmlspecialchars($status ?: '-', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                        </div>

                        <div class="billing-reference">
                            <div>
                                <span><?= $billType === 'IPD' ? 'IPD No.' : 'OPD ID' ?></span>
                                <strong>
                                    <?= htmlspecialchars(
                                        $billType === 'IPD'
                                            ? ($bill['ipd_no'] ?: '-')
                                            : ($bill['opd_id'] ?: '-'),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </strong>
                            </div>
                            <div>
                                <span>Gross Amount</span>
                                <strong>₹<?= number_format($grossAmount, 2) ?></strong>
                            </div>
                            <div>
                                <span>Discount</span>
                                <strong>₹<?= number_format($discountAmount, 2) ?></strong>
                            </div>
                            <div>
                                <span>Net Amount</span>
                                <strong>₹<?= number_format($netAmount, 2) ?></strong>
                            </div>
                        </div>

                        <div class="billing-payment-summary">
                            <div>
                                <span>Paid Amount</span>
                                <strong>₹<?= number_format($paidAmount, 2) ?></strong>
                            </div>
                            <div>
                                <span>Balance</span>
                                <strong class="<?= $balanceAmount > 0 ? 'balance-due' : 'balance-clear' ?>">
                                    ₹<?= number_format($balanceAmount, 2) ?>
                                </strong>
                            </div>
                        </div>

                        <?php if (!empty($bill['remarks'])): ?>
                            <div class="billing-remarks">
                                <span>Remarks</span>
                                <p><?= nl2br(htmlspecialchars($bill['remarks'], ENT_QUOTES, 'UTF-8')) ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php elseif ($page === 'invoice'): ?>
<section class="page-section">
    <div class="page-header">
        <div>
            <h1>Invoices</h1>
            <p>View your OPD and IPD invoices</p>
        </div>
    </div>

    <?php if (empty($invoiceRecords)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">▤</div>
            <h3>No Invoices Found</h3>
            <p>No invoices are available for your account.</p>
        </div>
    <?php else: ?>
        <div class="billing-list">
            <?php foreach ($invoiceRecords as $invoice): ?>
                <?php
                $invoiceType = strtoupper((string)$invoice['invoice_type']);
                $invoiceDate = !empty($invoice['bill_date'])
                    ? date('d M Y', strtotime($invoice['bill_date']))
                    : '-';
                $grossAmount = (float)($invoice['gross_amount'] ?? 0);
                $discountAmount = (float)($invoice['discount_amount'] ?? 0);
                $netAmount = (float)($invoice['net_amount'] ?? 0);
                $paidAmount = (float)($invoice['paid_amount'] ?? 0);
                $balanceAmount = (float)($invoice['balance_amount'] ?? 0);
                $status = (string)($invoice['payment_status'] ?? '');
                ?>

                <div class="billing-card">
                    <div class="billing-card-top">
                        <div>
                            <span class="billing-type <?= strtolower($invoiceType) ?>">
                                <?= htmlspecialchars($invoiceType, ENT_QUOTES, 'UTF-8') ?>
                            </span>

                            <h3>
                                <?= htmlspecialchars($invoice['bill_no'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                            </h3>

                            <p>
                                <?= htmlspecialchars($invoiceDate, ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>

                        <div class="billing-status">
                            <span class="billing-status-badge <?= strtolower($status) ?>">
                                <?= htmlspecialchars($status ?: '-', ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                    </div>

                    <div class="billing-reference">
                        <div>
                            <span><?= $invoiceType === 'IPD' ? 'IPD No.' : 'OPD ID' ?></span>
                            <strong>
                                <?= htmlspecialchars(
                                    $invoiceType === 'IPD'
                                        ? ($invoice['ipd_no'] ?: '-')
                                        : ($invoice['opd_id'] ?: '-'),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </strong>
                        </div>

                        <div>
                            <span>Gross Amount</span>
                            <strong>₹<?= number_format($grossAmount, 2) ?></strong>
                        </div>

                        <div>
                            <span>Discount</span>
                            <strong>₹<?= number_format($discountAmount, 2) ?></strong>
                        </div>

                        <div>
                            <span>Net Amount</span>
                            <strong>₹<?= number_format($netAmount, 2) ?></strong>
                        </div>
                    </div>

                    <div class="billing-payment-summary">
                        <div>
                            <span>Paid Amount</span>
                            <strong>₹<?= number_format($paidAmount, 2) ?></strong>
                        </div>

                        <div>
                            <span>Balance</span>
                            <strong class="<?= $balanceAmount > 0 ? 'balance-due' : 'balance-clear' ?>">
                                ₹<?= number_format($balanceAmount, 2) ?>
                            </strong>
                        </div>
                    </div>

                    <?php if (!empty($invoice['remarks'])): ?>
                        <div class="billing-remarks">
                            <span>Remarks</span>
                            <p>
                                <?= nl2br(htmlspecialchars($invoice['remarks'], ENT_QUOTES, 'UTF-8')) ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php elseif ($page === 'telemedicine'): ?>
    <div class="content">
        <div class="page-header">
            <div>
                <h1>Telemedicine Consultation</h1>
                <p>Secure online consultation for your OPD visit.</p>
            </div>
            <a href="/patient/dashboard.php?page=opd" class="book-btn">← &nbsp; Back to OPD Visits</a>
        </div>
        <?php if (!$consultation): ?>
            <div class="empty-state">
                <div class="empty-icon">◉</div>
                <h3>Consultation Not Available</h3>
                <p>This telemedicine consultation is not available for your account or is no longer active.</p>
            </div>
        <?php else: ?>
            <section class="right-card" style="margin-bottom:14px;">
                <div class="right-card-header">
                    <h3><?= htmlspecialchars($consultation['doctor_name'] ?: 'Doctor', ENT_QUOTES, 'UTF-8') ?></h3>
                    <span><?= htmlspecialchars($consultation['department_name'] ?: 'Department', ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="quick-info-row"><div class="quick-info-label">OPD No.</div><div class="quick-info-value"><?= htmlspecialchars($consultation['opd_no'] ?: '-', ENT_QUOTES, 'UTF-8') ?></div></div>
                <div class="quick-info-row"><div class="quick-info-label">Date & Time</div><div class="quick-info-value"><?= htmlspecialchars(date('d M Y', strtotime($consultation['appointment_date'])), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($consultation['appointment_time'], ENT_QUOTES, 'UTF-8') ?></div></div>
            </section>
            <div style="width:100%;height:calc(100vh - 250px);min-height:520px;background:#000;border-radius:12px;overflow:hidden;">
                <iframe
                    src="https://meet.jit.si/<?= rawurlencode($consultation['room_name']) ?>#config.prejoinPageEnabled=true"
                    style="width:100%;height:100%;border:0;display:block;"
                    allow="camera; microphone; fullscreen; display-capture; autoplay"
                    allowfullscreen
                ></iframe>
            </div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="content home-content">
        <section class="welcome-hero">
            <div class="welcome-copy">
                <h1>Good Morning, <?= htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8') ?>! <span>👋</span></h1>
                <p>Your health is our priority. Manage your appointments, medical records and more — all in one place.</p>
                <div class="welcome-patient">
                    <div class="welcome-avatar"><?= htmlspecialchars($patientInitial, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="welcome-patient-info">
                        <strong><?= htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8') ?></strong>
                        <span>Patient ID: <?= htmlspecialchars((string)$patientId, ENT_QUOTES, 'UTF-8') ?></span>
                        <div class="welcome-contact">
                            <span>☎ <?= htmlspecialchars($patientMobile, ENT_QUOTES, 'UTF-8') ?></span>
                            <span>✉ <?= htmlspecialchars($patientEmail ?: 'Not available', ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                    <a href="/patient/dashboard.php?page=profile" class="hero-profile-btn">View Profile <span>→</span></a>
                </div>
            </div>
            <div class="welcome-art" aria-hidden="true"></div>
        </section>

        <section class="services-grid home-services">
            <a href="#" class="service-card service-book">
                <div class="service-icon"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M7 3v4M17 3v4M3 10h18M8 14h2M14 14h2M8 18h2"></path></svg></div>
                <div class="service-content"><h4>Add Appointment</h4><p>Schedule your visit</p></div>
                <div class="service-arrow">→</div>
            </a>
            <a href="/patient/dashboard.php?page=appointments" class="service-card service-appointments">
                <div class="service-icon"><svg viewBox="0 0 24 24"><path d="M5 6h14M5 12h14M5 18h14M3 6h.01M3 12h.01M3 18h.01"></path></svg></div>
                <div class="service-content"><h4>Appointment List</h4><p>View &amp; manage</p></div>
                <div class="service-arrow">→</div>
            </a>
            <a href="/patient/dashboard.php?page=opd" class="service-card service-opd">
                <div class="service-icon"><svg viewBox="0 0 24 24"><path d="M6 4v7a6 6 0 0 0 12 0V4"></path><path d="M6 4H4M18 4h2M12 17v3M9 20h6"></path></svg></div>
                <div class="service-content"><h4>OPD Visits</h4><p>Your OPD history</p></div>
                <div class="service-arrow">→</div>
            </a>
            <a href="/patient/dashboard.php?page=ipd" class="service-card service-ipd">
                <div class="service-icon"><svg viewBox="0 0 24 24"><path d="M4 18V7h16v11"></path><path d="M3 18h18M7 7V5h10v2M7 12h10"></path></svg></div>
                <div class="service-content"><h4>IPD Records</h4><p>Your admissions</p></div>
                <div class="service-arrow">→</div>
            </a>
            <a href="/patient/dashboard.php?page=prescriptions" class="service-card service-prescriptions">
                <div class="service-icon"><svg viewBox="0 0 24 24"><path d="m8 4 12 12-4 4L4 8z"></path><path d="m14 6 4 4"></path></svg></div>
                <div class="service-content"><h4>Prescriptions</h4><p>View prescriptions</p></div>
                <div class="service-arrow">→</div>
            </a>
            <a href="/patient/dashboard.php?page=billing" class="service-card service-billing">
                <div class="service-icon"><svg viewBox="0 0 24 24"><path d="M5 3h14v18l-3-2-4 2-4-2-3 2z"></path><path d="M9 8h6M9 12h6M9 16h4"></path></svg></div>
                <div class="service-content"><h4>Billing &amp; Invoice</h4><p>Bills and payments</p></div>
                <div class="service-arrow">→</div>
            </a>
            <a href="#" class="service-card service-telemedicine">
                <div class="service-icon"><svg viewBox="0 0 24 24"><rect x="3" y="7" width="14" height="10" rx="2"></rect><path d="m17 10 4-2v8l-4-2z"></path></svg></div>
                <div class="service-content"><h4>Telemedicine</h4><p>Consult online</p></div>
                <div class="service-arrow">→</div>
            </a>
        </section>

        <section class="home-lower-grid">
            <div class="home-panel next-panel">
                <div class="home-panel-header">
                    <h2>Next Appointment</h2>
                    <a href="/patient/dashboard.php?page=appointments">View All <span>→</span></a>
                </div>
                <?php if ($homeUpcomingAppointment): ?>
                    <?php
                    $upcomingDate = strtotime(
                        $homeUpcomingAppointment['appointment_date'] . ' ' .
                        ($homeUpcomingAppointment['appointment_time'] ?: '00:00:00')
                    );
                    $upcomingDateText = $upcomingDate ? date('D, d M Y', $upcomingDate) : '-';
                    $upcomingTimeText = '-';
                    if (!empty($homeUpcomingAppointment['appointment_time'])) {
                        $upcomingTimeTimestamp = strtotime((string)$homeUpcomingAppointment['appointment_time']);
                        $upcomingTimeText = $upcomingTimeTimestamp !== false ? date('h:i A', $upcomingTimeTimestamp) : (string)$homeUpcomingAppointment['appointment_time'];
                    }
                    $upcomingDoctor = trim((string)($homeUpcomingAppointment['doctor_name'] ?? ''));
                    $upcomingDoctor = $upcomingDoctor !== '' ? $upcomingDoctor : 'Doctor not assigned';
                    $upcomingDepartment = trim((string)($homeUpcomingAppointment['department_name'] ?? ''));
                    $upcomingDepartment = $upcomingDepartment !== '' ? $upcomingDepartment : 'Department not assigned';
                    $upcomingMode = strtoupper((string)($homeUpcomingAppointment['consultation_mode'] ?? 'PHYSICAL'));
                    $upcomingModeText = $upcomingMode === 'TELEMEDICINE' ? 'Telemedicine' : 'Physical Consultation';
                    ?>
                    <div class="next-appointment">
                        <div class="next-date-row">
                            <div class="next-date-icon"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M7 3v4M17 3v4M3 10h18"></path></svg></div>
                            <div><strong><?= htmlspecialchars($upcomingDateText, ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars($upcomingTimeText, ENT_QUOTES, 'UTF-8') ?></span></div>
                            <em>Upcoming</em>
                        </div>
                        <div class="next-doctor">
                            <div class="next-doctor-avatar">Dr</div>
                            <div><strong><?= htmlspecialchars($upcomingDoctor, ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars($upcomingDepartment, ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($upcomingModeText, ENT_QUOTES, 'UTF-8') ?>)</span></div>
                        </div>
                        <div class="next-meta">
                            <div><span>▣</span> Mode <strong><?= htmlspecialchars($upcomingModeText, ENT_QUOTES, 'UTF-8') ?></strong></div>
                            <div><span>▤</span> Department <strong><?= htmlspecialchars($upcomingDepartment, ENT_QUOTES, 'UTF-8') ?></strong></div>
                        </div>
                        <a href="/patient/dashboard.php?page=appointments" class="join-btn home-join-btn">▣ &nbsp; View Appointment</a>
                        <a href="/patient/dashboard.php?page=appointments" class="reschedule-btn">▣ &nbsp; Reschedule</a>
                    </div>
                <?php else: ?>
                    <div class="next-appointment empty-next">
                        <div class="next-date-row"><div class="next-date-icon"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M7 3v4M17 3v4M3 10h18"></path></svg></div><div><strong>No upcoming appointment</strong><span>Your schedule is clear</span></div></div>
                        <p>Your upcoming appointments will appear here.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="home-panel recent-appointments-panel">
                <div class="home-panel-header">
                    <h2>Recent Appointments</h2>
                    <a href="/patient/dashboard.php?page=appointments">View All <span>→</span></a>
                </div>
                <div class="recent-appointments-list">
                    <?php if (empty($homeRecentAppointments)): ?>
                        <div class="recent-empty">No recent appointments</div>
                    <?php else: ?>
                        <?php foreach ($homeRecentAppointments as $recentAppointment): ?>
                            <?php
$recentTimestamp = !empty($recentAppointment['appointment_date']) ? strtotime((string)$recentAppointment['appointment_date']) : false;
$recentStatus = strtolower(trim((string)($recentAppointment['status'] ?? '')));
$recentStatusClass = $recentStatus === 'completed' ? 'completed' : (($recentStatus === 'cancelled' || $recentStatus === 'canceled') ? 'cancelled' : 'pending');
$recentMonth = $recentTimestamp !== false ? date('M', $recentTimestamp) : '-';
$recentDay = $recentTimestamp !== false ? date('d', $recentTimestamp) : '-';
$recentDoctor = trim((string)($recentAppointment['doctor_name'] ?? ''));
$recentDoctor = $recentDoctor !== '' ? $recentDoctor : 'Doctor not assigned';
$recentDepartment = trim((string)($recentAppointment['department_name'] ?? ''));
$recentDepartment = $recentDepartment !== '' ? $recentDepartment : 'Department not assigned';
$recentTime = '-';
if (!empty($recentAppointment['appointment_time'])) {
    $recentTimeTimestamp = strtotime((string)$recentAppointment['appointment_time']);
    if ($recentTimeTimestamp !== false) {
        $recentTime = date('h:i A', $recentTimeTimestamp);
    }
}
?>
                            <a href="/patient/dashboard.php?page=appointments" class="recent-appointment-row">
                                <div class="recent-date-box"><strong><?= htmlspecialchars($recentDay, ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars($recentMonth, ENT_QUOTES, 'UTF-8') ?></span></div>
                                <div class="recent-doctor"><strong><?= htmlspecialchars($recentDoctor, ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars($recentDepartment, ENT_QUOTES, 'UTF-8') ?></span><small><?= htmlspecialchars($recentTime, ENT_QUOTES, 'UTF-8') ?></small></div>
                                <span class="recent-status <?= $recentStatusClass ?>"><?= htmlspecialchars(ucfirst($recentStatus ?: 'Completed'), ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="recent-arrow">›</span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="home-right-stack">
                <div class="home-panel quick-info-panel">
                    <div class="home-panel-header"><h2>Quick Info</h2></div>
                    <div class="quick-info-row"><div class="quick-info-icon">▣</div><div class="quick-info-label">Hospital ID</div><div class="quick-info-value"><?= htmlspecialchars((string)$hospitalId, ENT_QUOTES, 'UTF-8') ?></div></div>
                    <div class="quick-info-row"><div class="quick-info-icon">☎</div><div class="quick-info-label">Mobile</div><div class="quick-info-value"><?= htmlspecialchars($patientMobile, ENT_QUOTES, 'UTF-8') ?></div></div>
                    <div class="quick-info-row"><div class="quick-info-icon">✉</div><div class="quick-info-label">Email</div><div class="quick-info-value"><?= htmlspecialchars($patientEmail ?: 'Not Available', ENT_QUOTES, 'UTF-8') ?></div></div>
                    <div class="quick-info-row"><div class="quick-info-icon">♧</div><div class="quick-info-label">Blood Group</div><div class="quick-info-value">Not Available</div></div>
                    <div class="quick-info-row"><div class="quick-info-icon">♢</div><div class="quick-info-label">Allergies</div><div class="quick-info-value">Not Available</div></div>
                </div>
                <div class="home-panel health-tips-panel">
                    <div class="home-panel-header"><h2><span class="bulb">💡</span> Health Tips</h2><a href="#">View All</a></div>
                    <div class="health-tip"><div class="health-tip-icon">♟</div><div><strong>Stay hydrated</strong><p>Drink at least 8 glasses of water daily for better health and immunity.</p></div><span>›</span></div>
                </div>
            </div>
        </section>

        <section class="health-journey">
            <div class="health-journey-copy"><span class="journey-heart">♥</span><div><h2>Your Health Journey Matters</h2><p>Access your medical records, stay on top of your appointments and take control of your health — anytime, anywhere.</p></div></div>
            <div class="health-journey-art" aria-hidden="true"></div>
        </section>
    </div>
    <?php endif; ?>
    <?php if ($page === 'opd' || $page === 'ipd'): ?>
<script>
function togglePatientOpd(button){
    const row = button.closest('.patient-opd-row');

    if(!row){
        return;
    }

    const detailsRow = row.nextElementSibling;

    if(!detailsRow || !detailsRow.classList.contains('patient-opd-details-row')){
        return;
    }

    const isOpen = detailsRow.classList.contains('open');

    document.querySelectorAll('.patient-opd-details-row.open').forEach(function(item){
        item.classList.remove('open');

        const previousRow = item.previousElementSibling;

        if(previousRow){
            const otherButton = previousRow.querySelector('.patient-expand-btn');

            if(otherButton){
                otherButton.innerHTML = 'Expand';
            }
        }
    });

    if(!isOpen){
        detailsRow.classList.add('open');
        button.innerHTML = 'Collapse';
    }
}
function toggleIpdDetails(button){
    const card = button.closest('.appointment-card');
    if(!card){
        return;
    }

    const details = card.querySelector('.appointment-extra');
    if(!details){
        return;
    }

    const isOpen = details.classList.contains('open');

    document.querySelectorAll('.appointment-card .appointment-extra.open').forEach(function(item){
        item.classList.remove('open');

        const otherButton = item
            .closest('.appointment-card')
            .querySelector('.view-btn');

        if(otherButton){
            otherButton.textContent = 'View Details';
        }
    });

    if(!isOpen){
        details.classList.add('open');
        button.textContent = 'Hide Details';
    }
}

function togglePatientIpd(id){
    const row=document.getElementById('patient-ipd-details-'+id);
    if(!row){
        return;
    }
    document.querySelectorAll('.patient-ipd-details-row').forEach(function(item){
        if(item!==row){
            item.classList.remove('open');
        }
    });
    row.classList.toggle('open');
}
</script>
<?php endif; ?>
</main>
<?php if ($page === 'appointments'): ?>
<script>
function togglePatientAppointment(button){
    const row = button.closest('.patient-appointment-row');
    if(!row){
        return;
    }

    const detailsRow = row.nextElementSibling;
    if(!detailsRow || !detailsRow.classList.contains('patient-appointment-details-row')){
        return;
    }

    const isOpen = detailsRow.classList.contains('open');

    document.querySelectorAll('.patient-appointment-details-row.open').forEach(function(item){
        item.classList.remove('open');

        const previousRow = item.previousElementSibling;
        if(previousRow){
            const otherButton = previousRow.querySelector('.patient-expand-btn');
            if(otherButton){
                otherButton.innerHTML = 'Expand';
            }
        }
    });

    if(!isOpen){
        detailsRow.classList.add('open');
        button.innerHTML = 'Collapse';
    }
}

function togglePrescription(button){
    const card = button.closest('.prescription-card');
    if(!card){
        return;
    }

    const details = card.querySelector('.prescription-details');
    if(!details){
        return;
    }

    const isOpen = card.classList.toggle('open');

    if(isOpen){
        button.innerHTML = 'Hide Details <span>‹</span>';
    }else{
        button.innerHTML = 'View Details <span>›</span>';
    }
}
function togglePatientIpd(id,button){
    const row=document.getElementById('patient-ipd-details-'+id);
    if(!row){
        return;
    }
    const isOpen=row.classList.contains('open');
    document.querySelectorAll('.patient-ipd-details-row').forEach(function(item){
        item.classList.remove('open');
    });
    document.querySelectorAll('.patient-ipd-view-btn').forEach(function(btn){
        btn.innerHTML='Expand';
    });
    if(!isOpen){
        row.classList.add('open');
        if(button){
            button.innerHTML='Collapse';
        }else{
            const previousRow=row.previousElementSibling;
            if(previousRow){
                const sourceButton=previousRow.querySelector('.patient-ipd-view-btn');
                if(sourceButton){
                    sourceButton.innerHTML='Collapse';
                }
            }
        }
    }
}
</script>
<?php endif; ?>
<nav class="mobile-bottom">
    <a href="/patient/dashboard.php?page=home" class="<?= $page === 'home' ? 'active' : '' ?>"><span class="mobile-bottom-icon">⌂</span>Home</a>
    <a href="/patient/dashboard.php?page=appointments" class="<?= $page === 'appointments' ? 'active' : '' ?>"><span class="mobile-bottom-icon">▣</span>Appointments</a>
    <a href="/patient/dashboard.php?page=opd" class="<?= $page === 'opd' ? 'active' : '' ?>"><span class="mobile-bottom-icon">♧</span>OPD</a>
    <a href="/patient/dashboard.php?page=billing" class="<?= $page === 'billing' ? 'active' : '' ?>"><span class="mobile-bottom-icon">₹</span>Billing</a>
    <a href="/patient/logout.php"><span class="mobile-bottom-icon">⎋</span>Logout</a>
</nav>
</body>
</html>