<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

header('Content-Type: application/json');

/* ================= SESSION CHECK ================= */
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit;
}

/* ================= INPUT ================= */
$user_id = (int)($_GET['user_id'] ?? 0);
if ($user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid doctor']);
    exit;
}

/* ================= CHECK DOCTOR MASTER ================= */
$doctor_id = null;
$chk = $conn->prepare("SELECT id FROM doctor_master WHERE user_id = ?");
$chk->bind_param("i", $user_id);
$chk->execute();
$chk->bind_result($doctor_id);
$exists = $chk->fetch() ? true : false;
$chk->close();

/* ================= USER + MAPPINGS ================= */
$sql = "
SELECT 
    u.employee_id,
    u.full_name,
    u.username,
    u.email,
    u.mobile_no,
    u.gender,
    u.dob,
    u.marital_status,
    u.aadhar_no,
    u.status,

    etm_main.type_name AS employee_type,
    GROUP_CONCAT(DISTINCT etm_sub.type_name) AS employee_sub_type,
    GROUP_CONCAT(DISTINCT qm.qualification_name) AS qualification,

    GROUP_CONCAT(DISTINCT uh.hospital_id) AS hospital_ids,
    GROUP_CONCAT(DISTINCT ub.branch_id) AS branch_ids,
    GROUP_CONCAT(DISTINCT ud.department_id) AS department_ids,
    GROUP_CONCAT(DISTINCT uds.designation_code) AS designation_ids

FROM users u
LEFT JOIN employee_type_master etm_main ON etm_main.id = u.employee_type_id
LEFT JOIN user_employee_sub_types ust ON ust.user_id = u.id
LEFT JOIN employee_type_master etm_sub 
       ON etm_sub.id = ust.sub_type_id AND etm_sub.parent_id = 2
LEFT JOIN user_qualifications uq ON uq.user_id = u.id
LEFT JOIN qualification_master qm ON qm.id = uq.qualification_id
LEFT JOIN user_hospitals uh ON uh.user_id = u.id
LEFT JOIN user_branches ub ON ub.user_id = u.id
LEFT JOIN user_departments ud ON ud.user_id = u.id
LEFT JOIN user_designations uds ON uds.user_id = u.id
WHERE u.id = ?
GROUP BY u.id
LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

if (!$row = $res->fetch_assoc()) {
    echo json_encode(['success' => false, 'message' => 'Doctor not found']);
    exit;
}

/* ================= BASE RESPONSE ================= */
$response = [
    'success'   => true,
    'exists'    => $exists,
    'doctor_id' => $doctor_id,
    'data' => [
        'employee_id'       => $row['employee_id'],
        'full_name'         => $row['full_name'],
        'username'          => $row['username'],
        'email'             => $row['email'],
        'mobile_no'         => $row['mobile_no'],
        'gender'            => $row['gender'],
        'dob'               => $row['dob'],
        'marital_status'    => $row['marital_status'],
        'aadhaar'           => $row['aadhar_no'],
        'status'            => $row['status'],
        'employee_type'     => $row['employee_type'],
        'employee_sub_type' => $row['employee_sub_type'],
        'qualification'     => $row['qualification'],

        /* LOCKED MULTI-SELECT IDS */
        'hospital_ids'      => $row['hospital_ids'] ? explode(',', $row['hospital_ids']) : [],
        'branch_ids'        => $row['branch_ids'] ? explode(',', $row['branch_ids']) : [],
        'department_ids'    => $row['department_ids'] ? explode(',', $row['department_ids']) : [],
        'designation_ids'   => $row['designation_ids'] ? explode(',', $row['designation_ids']) : []
    ]
];

/* ================= EDIT MODE DATA ================= */
if ($exists) {
    
    /* -------- Doctor Media -------- */
$r = $conn->query("
    SELECT profile_photo, signature_image
    FROM doctor_media
    WHERE doctor_id = $doctor_id
    LIMIT 1
");

if ($x = $r->fetch_assoc()) {
    $response['media'] = [
        'profile_photo'   => $x['profile_photo'],
        'signature_image' => $x['signature_image']
    ];
}

    /* ================= DOCTOR MASTER CONFIG ================= */
$q = $conn->prepare("
    SELECT license_no, is_surgeon, time_slot_minutes
    FROM doctor_master
    WHERE id = ?
    LIMIT 1
");
$q->bind_param("i", $doctor_id);
$q->execute();
$r = $q->get_result();

if ($cfg = $r->fetch_assoc()) {
    $response['doctor_config'] = [
        'license_no'          => $cfg['license_no'],
        'is_surgeon'          => (int)$cfg['is_surgeon'],
        'time_slot_minutes'  => (int)$cfg['time_slot_minutes']
    ];
}
$q->close();


    /* -------- Specializations -------- */
    $sp = [];
    $q = $conn->prepare("
        SELECT specialization_code
        FROM doctor_specializations
        WHERE doctor_id = ?
    ");
    $q->bind_param("i", $doctor_id);
    $q->execute();
    $r = $q->get_result();
    while ($x = $r->fetch_assoc()) {
        $sp[] = $x['specialization_code'];
    }
    $q->close();
    $response['specialization_codes'] = $sp;

    /* -------- Charges -------- */
    $charges = [];
    $r = $conn->query("
        SELECT visit_type, charge_amount, valid_from_day, valid_to_day
        FROM doctor_charges
        WHERE doctor_id = {$doctor_id}
        ORDER BY valid_from_day
    ");
    while ($x = $r->fetch_assoc()) {
        $charges[] = $x;
    }
    $response['charges'] = $charges;

    /* -------- Weekly Schedule -------- */
    $schedule = [];

$q = $conn->prepare("
    SELECT 
        dwd.day_of_week,
        dds.id AS schedule_id,
        dds.start_time,
        dds.end_time,
        b.break_start,
        b.break_end
    FROM doctor_working_days dwd
    JOIN doctor_day_schedules dds 
        ON dds.working_day_id = dwd.id
    LEFT JOIN doctor_schedule_breaks b
        ON b.schedule_id = dds.id
    WHERE dwd.doctor_id = ?
      AND dwd.is_active = 1
      AND dds.is_active = 1
    ORDER BY dwd.id, dds.start_time, b.break_start
");

$q->bind_param("i", $doctor_id);
$q->execute();
$r = $q->get_result();

while ($row = $r->fetch_assoc()) {

    $day = $row['day_of_week'];

    if (!isset($schedule[$day])) {
        $schedule[$day] = [];
    }

    // find or create schedule slot
    $slotIndex = count($schedule[$day]) - 1;

    if (
        $slotIndex < 0 ||
        $schedule[$day][$slotIndex]['from'] !== $row['start_time'] ||
        $schedule[$day][$slotIndex]['to']   !== $row['end_time']
    ) {
        $schedule[$day][] = [
            'from'   => $row['start_time'],
            'to'     => $row['end_time'],
            'breaks' => []
        ];
        $slotIndex++;
    }

    // add breaks
    if ($row['break_start'] && $row['break_end']) {
        $schedule[$day][$slotIndex]['breaks'][] = [
            'from' => $row['break_start'],
            'to'   => $row['break_end']
        ];
    }
}

$response['schedule'] = $schedule;


    /* -------- Doctor Share -------- */
$r = $conn->query("
    SELECT 
        consultation_percentage,
        consultation_flat_amount,
        consultation_wef_date,

        invoice_percentage,
        invoice_flat_amount,
        invoice_wef_date,

        service_percentage,
        service_flat_amount,
        service_wef_date
    FROM doctor_share
    WHERE doctor_id = $doctor_id
    LIMIT 1
");

if ($x = $r->fetch_assoc()) {
    $response['doctor_share'] = [
        'consultation' => [
            'percentage'  => $x['consultation_percentage'],
            'flat_amount' => $x['consultation_flat_amount'],
            'wef_date'    => $x['consultation_wef_date']
        ],
        'invoice' => [
            'percentage'  => $x['invoice_percentage'],
            'flat_amount' => $x['invoice_flat_amount'],
            'wef_date'    => $x['invoice_wef_date']
        ],
        'service' => [
            'percentage'  => $x['service_percentage'],
            'flat_amount' => $x['service_flat_amount'],
            'wef_date'    => $x['service_wef_date']
        ]
    ];
}


    /* -------- Media -------- */
    $r = $conn->query("
        SELECT profile_photo, signature_image
        FROM doctor_media
        WHERE doctor_id = {$doctor_id}
    ");
    if ($x = $r->fetch_assoc()) {
        $response['media'] = $x;
    }
}


/* ================= OUTPUT ================= */
echo json_encode($response);
