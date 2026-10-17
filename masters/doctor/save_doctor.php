<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/communication/send_mail.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/communication/sms/send_sms.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/communication/whatsapp/send_whatsapp.php';
/* ================= BASIC CHECKS ================= */
if (!isset($_SESSION['user_id'])) {
    die('Session expired');
}
/* ================= DEBUG HELPER ================= */
function debugFail($msg, $extra = []) {
    echo "<h3>SAVE FAILED</h3>";
    echo "<b>$msg</b><br><br>";
    if (!empty($extra)) {
        echo "<pre>";
        print_r($extra);
        echo "</pre>";
    }
    exit;
}
/* ================= INPUT ================= */
$user_id            = (int)($_POST['user_id'] ?? 0);
$license_no         = trim($_POST['license_no'] ?? '');
$is_surgeon         = (int)($_POST['is_surgeon'] ?? 0);
$time_slot_minutes  = (int)($_POST['time_slot_minutes'] ?? 0);
if ($user_id <= 0) {
    debugFail('Invalid user selected', $_POST);
}
if ($time_slot_minutes <= 0) {
    debugFail('Invalid time slot minutes', $_POST);
}
try {
    /* ================= TRANSACTION START ================= */
    $conn->begin_transaction();
    
   


$oldDoctor = [];
/* ================= CHECK IF DOCTOR EXISTS ================= */
$doctor_id = null;

$chk = $conn->prepare("SELECT id FROM doctor_master WHERE user_id = ?");
$chk->bind_param("i", $user_id);
$chk->execute();
$chk->bind_result($doctor_id);
$exists = $chk->fetch() ? true : false;
$chk->close();

$oldDoctor = [];

if ($exists) {
    $q = $conn->prepare("
        SELECT *
        FROM doctor_master
        WHERE id = ?
    ");
    $q->bind_param("i", $doctor_id);
    $q->execute();
    $oldDoctor = $q->get_result()->fetch_assoc();
    $q->close();
}
$oldSpecializations = [];
$oldCharges = [];
$oldShare = [];
$oldMedia = [];
$oldSchedule = [];
if ($exists) {

    $q = $conn->prepare("
        SELECT specialization_code
        FROM doctor_specializations
        WHERE doctor_id = ?
        ORDER BY specialization_code
    ");
    $q->bind_param("i", $doctor_id);
    $q->execute();
    $res = $q->get_result();
    while ($r = $res->fetch_assoc()) {
        $oldSpecializations[] = $r['specialization_code'];
    }
    $q->close();
}
if ($exists) {
    $q = $conn->prepare("
        SELECT
            visit_type,
            charge_amount,
            valid_from_day,
            valid_to_day
        FROM doctor_charges
        WHERE doctor_id = ?
        ORDER BY visit_type
    ");
    $q->bind_param("i", $doctor_id);
    $q->execute();
    $res = $q->get_result();
    while ($r = $res->fetch_assoc()) {
        $oldCharges[] = $r;
    }
    $q->close();
}
if ($exists) {

    $q = $conn->prepare("
        SELECT *
        FROM doctor_share
        WHERE doctor_id = ?
    ");

    $q->bind_param("i", $doctor_id);
    $q->execute();

    $res = $q->get_result();
    $oldShare = $res->fetch_assoc();

    $q->close();
}
if ($exists) {

    $q = $conn->prepare("
        SELECT *
        FROM doctor_media
        WHERE doctor_id = ?
    ");

    $q->bind_param("i", $doctor_id);
    $q->execute();

    $res = $q->get_result();
    $oldMedia = $res->fetch_assoc();

    $q->close();
}
$oldSchedule = [];

if ($exists) {

    $sql = "
    SELECT
        w.day_of_week,
        s.id AS schedule_id,
        s.start_time,
        s.end_time,
        b.break_start,
        b.break_end
    FROM doctor_working_days w
    LEFT JOIN doctor_day_schedules s
        ON s.working_day_id = w.id
    LEFT JOIN doctor_schedule_breaks b
        ON b.schedule_id = s.id
    WHERE w.doctor_id = ?
    ORDER BY
        w.day_of_week,
        s.id,
        b.id
    ";

    $q = $conn->prepare($sql);
    $q->bind_param("i", $doctor_id);
    $q->execute();

    $res = $q->get_result();

    while ($row = $res->fetch_assoc()) {

        $day = strtoupper($row['day_of_week']);

        if (!isset($oldSchedule[$day])) {
            $oldSchedule[$day] = [];
        }

        if (!isset($oldSchedule[$day][$row['schedule_id']])) {

    $oldSchedule[$day][$row['schedule_id']] = [
        'from' => $row['start_time'],
        'to'   => $row['end_time'],
        'breaks' => []
    ];
}

if (!empty($row['break_start']) && !empty($row['break_end'])) {

    $oldSchedule[$day][$row['schedule_id']]['breaks'][] = [
        'from' => $row['break_start'],
        'to'   => $row['break_end']
    ];
}
    }
foreach ($oldSchedule as $day => $slots) {
    $oldSchedule[$day] = array_values($slots);
}
    $q->close();
}
/* ================= PERMISSION CHECK ================= */
if (!$exists && !checkPermission('doctor_master','add')) {
    die('Access Denied');
}
if ($exists && !checkPermission('doctor_master','edit')) {
    die('Access Denied');
}
/* =====================================================
   1️⃣ INSERT OR UPDATE doctor_master
   ===================================================== */
if ($exists) {

    // 🔁 UPDATE
    $stmt = $conn->prepare("
        UPDATE doctor_master
        SET
            license_no = ?,
            is_surgeon = ?,
            time_slot_minutes = ?,
            updated_at = NOW()
        WHERE user_id = ?
    ");

    $stmt->bind_param(
        "siii",
        $license_no,
        $is_surgeon,
        $time_slot_minutes,
        $user_id
    );

    if (!$stmt->execute()) {
        throw new Exception('doctor_master update failed: '.$stmt->error);
    }

} else {

    // ➕ INSERT
    $stmt = $conn->prepare("
        INSERT INTO doctor_master
(
    user_id,
    license_no,
    is_surgeon,
    time_slot_minutes,
    created_by,
    created_at
)
VALUES
(
    ?, ?, ?, ?, ?, NOW()
)
    ");

    $stmt->bind_param(
        "isiii",
        $user_id,
        $license_no,
        $is_surgeon,
        $time_slot_minutes,
        $_SESSION['user_id']
    );

    if (!$stmt->execute()) {
        throw new Exception('doctor_master insert failed: '.$stmt->error);
    }

    $doctor_id = $stmt->insert_id;
}

$stmt->close();

   /* =====================================================
   2️⃣ SAVE SPECIALIZATIONS (EDIT SAFE)
   ===================================================== */

/* 🔥 Always clear old mappings */
$del = $conn->prepare("
    DELETE FROM doctor_specializations
    WHERE doctor_id = ?
");
$del->bind_param("i", $doctor_id);
$del->execute();
$del->close();

/* ➕ Insert selected specializations */
if (!empty($_POST['specialization_codes']) && is_array($_POST['specialization_codes'])) {

    $stmt = $conn->prepare("
        INSERT INTO doctor_specializations
        (doctor_id, specialization_code)
        VALUES (?,?)
    ");

    foreach ($_POST['specialization_codes'] as $code) {

        if ($code === '') continue;

        $stmt->bind_param("is", $doctor_id, $code);

        if (!$stmt->execute()) {
            throw new Exception(
                'Specialization insert failed: '.$stmt->error
            );
        }
    }

    $stmt->close();
}


   /* =====================================================
   DOCTOR CHARGES
===================================================== */

if (!empty($_POST['charges']) && is_array($_POST['charges'])) {

    /* Clean old charges (edit-safe) */
    $del = $conn->prepare("DELETE FROM doctor_charges WHERE doctor_id = ?");
    $del->bind_param("i", $doctor_id);
    $del->execute();
    $del->close();

    $stmt = $conn->prepare("
        INSERT INTO doctor_charges
            (doctor_id, visit_type, charge_amount, valid_from_day, valid_to_day)
        VALUES (?,?,?,?,?)
    ");

    foreach ($_POST['charges'] as $row) {

        if (empty($row['visit_type'])) {
            continue;
        }

        $visitType = $row['visit_type'];
        $amount    = (float)$row['charge_amount'];
        $fromDay   = (int)$row['valid_from_day'];
        $toDay     = (int)$row['valid_to_day'];

        $stmt->bind_param(
            "isdii",
            $doctor_id,
            $visitType,
            $amount,
            $fromDay,
            $toDay
        );

        if (!$stmt->execute()) {
            throw new Exception("Doctor charges save failed: ".$stmt->error);
        }
    }

    $stmt->close();
}


  /* =====================================================
   4️⃣ SAVE DOCTOR SHARE (UPSERT – EDIT SAFE)
   ===================================================== */

if (!empty($_POST['doctor_share'])) {

    $share = $_POST['doctor_share'];

    $stmt = $conn->prepare("
        INSERT INTO doctor_share
        (
            doctor_id,

            consultation_percentage,
            consultation_flat_amount,
            consultation_wef_date,

            invoice_percentage,
            invoice_flat_amount,
            invoice_wef_date,

            service_percentage,
            service_flat_amount,
            service_wef_date
        )
        VALUES (?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE

            consultation_percentage   = VALUES(consultation_percentage),
            consultation_flat_amount  = VALUES(consultation_flat_amount),
            consultation_wef_date     = VALUES(consultation_wef_date),

            invoice_percentage        = VALUES(invoice_percentage),
            invoice_flat_amount       = VALUES(invoice_flat_amount),
            invoice_wef_date          = VALUES(invoice_wef_date),

            service_percentage        = VALUES(service_percentage),
            service_flat_amount       = VALUES(service_flat_amount),
            service_wef_date          = VALUES(service_wef_date)
    ");

    if (!$stmt) {
        throw new Exception('Doctor share prepare failed: '.$conn->error);
    }

   $c_pct  = $share['consultation']['percentage'] ?? null;
$c_flat = $share['consultation']['flat_amount'] ?? null;
$c_wef  = $share['consultation']['wef_date'] ?: null;

$i_pct  = $share['invoice']['percentage'] ?? null;
$i_flat = $share['invoice']['flat_amount'] ?? null;
$i_wef  = $share['invoice']['wef_date'] ?: null;

$s_pct  = $share['service']['percentage'] ?? null;
$s_flat = $share['service']['flat_amount'] ?? null;
$s_wef  = $share['service']['wef_date'] ?: null;

$stmt->bind_param(
    "iddsddsdds",
    $doctor_id,
    $c_pct,
    $c_flat,
    $c_wef,
    $i_pct,
    $i_flat,
    $i_wef,
    $s_pct,
    $s_flat,
    $s_wef
);


    if (!$stmt->execute()) {
        throw new Exception('Doctor share save failed: '.$stmt->error);
    }

    $stmt->close();
}


    /* =====================================================
       5️⃣  DOCTOR MEDIA (profile photo + signature)
===================================================== */

$profilePath   = null;
$signaturePath = null;

/* Base directory */
$baseDir = $_SERVER['DOCUMENT_ROOT'] . "/uploads/doctors/{$doctor_id}";
if (!is_dir($baseDir)) {
    if (!mkdir($baseDir, 0777, true)) {
        throw new Exception("Failed to create directory: {$baseDir}");
    }
}

/* ---------- PROFILE PHOTO ---------- */
if (!empty($_FILES['doctor_profile_photo']['name'])) {

    if ($_FILES['doctor_profile_photo']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Profile photo upload error');
    }

    $ext = strtolower(pathinfo($_FILES['doctor_profile_photo']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png'];

    if (!in_array($ext, $allowed)) {
        throw new Exception('Invalid profile photo format');
    }

    $profilePath = "uploads/doctors/{$doctor_id}/profile.".$ext;

    if (!move_uploaded_file(
        $_FILES['doctor_profile_photo']['tmp_name'],
        $_SERVER['DOCUMENT_ROOT'].'/'.$profilePath
    )) {
        throw new Exception('Failed to save profile photo');
    }
}

/* ---------- SIGNATURE ---------- */
if (!empty($_FILES['doctor_signature']['name'])) {

    if ($_FILES['doctor_signature']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Signature upload error');
    }

    $ext = strtolower(pathinfo($_FILES['doctor_signature']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png'];

    if (!in_array($ext, $allowed)) {
        throw new Exception('Invalid signature format');
    }

    $signaturePath = "uploads/doctors/{$doctor_id}/signature.".$ext;

    if (!move_uploaded_file(
        $_FILES['doctor_signature']['tmp_name'],
        $_SERVER['DOCUMENT_ROOT'].'/'.$signaturePath
    )) {
        throw new Exception('Failed to save signature');
    }
}

/* ---------- INSERT / UPDATE doctor_media ---------- */
/* ================= SAFE DOCTOR MEDIA SAVE ================= */
$newMedia = $oldMedia;
if ($profilePath || $signaturePath) {

    if ($profilePath && $signaturePath) {

        $stmt = $conn->prepare("
            INSERT INTO doctor_media (doctor_id, profile_photo, signature_image)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                profile_photo = VALUES(profile_photo),
                signature_image = VALUES(signature_image)
        ");
        $stmt->bind_param("iss", $doctor_id, $profilePath, $signaturePath);

    } elseif ($profilePath) {

        $stmt = $conn->prepare("
            INSERT INTO doctor_media (doctor_id, profile_photo)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE
                profile_photo = VALUES(profile_photo)
        ");
        $stmt->bind_param("is", $doctor_id, $profilePath);

    } elseif ($signaturePath) {

        $stmt = $conn->prepare("
            INSERT INTO doctor_media (doctor_id, signature_image)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE
                signature_image = VALUES(signature_image)
        ");
        $stmt->bind_param("is", $doctor_id, $signaturePath);
    }

    if (!$stmt->execute()) {
        throw new Exception('Doctor media save failed: '.$stmt->error);
    }
    $stmt->close();
    $newMedia = [];

$q = $conn->prepare("
    SELECT *
    FROM doctor_media
    WHERE doctor_id = ?
");
$q->bind_param("i", $doctor_id);
$q->execute();
$newMedia = $q->get_result()->fetch_assoc();
$q->close();
}
 /* =====================================================
   6️⃣ DOCTOR WEEKLY SCHEDULE (FINAL FIX)
===================================================== */

if (!empty($_POST['schedule']) && is_array($_POST['schedule'])) {
    /* ================= NORMALIZE & MERGE DAYS ================= */
$normalizedSchedule = [];

foreach ($_POST['schedule'] as $day => $slots) {

    // normalize key once
    $dayKey = ucfirst(strtolower($day));

    if (!isset($normalizedSchedule[$dayKey])) {
        $normalizedSchedule[$dayKey] = [];
    }

    if (is_array($slots)) {
        foreach ($slots as $slot) {
            $normalizedSchedule[$dayKey][] = $slot;
        }
    }
}

/* overwrite original schedule */
$_POST['schedule'] = $normalizedSchedule;


    /* Remove old schedule safely */
    /* =====================================================
   CLEAN OLD SCHEDULE + BREAKS (EDIT SAFE)
===================================================== */

/* 1️⃣ DELETE BREAKS */
$del = $conn->prepare("
    DELETE b
    FROM doctor_schedule_breaks b
    INNER JOIN doctor_day_schedules s ON s.id = b.schedule_id
    INNER JOIN doctor_working_days d ON d.id = s.working_day_id
    WHERE d.doctor_id = ?
");
$del->bind_param("i", $doctor_id);
$del->execute();
$del->close();

/* 2️⃣ DELETE DAY SCHEDULES */
$del = $conn->prepare("
    DELETE s
    FROM doctor_day_schedules s
    INNER JOIN doctor_working_days d ON d.id = s.working_day_id
    WHERE d.doctor_id = ?
");
$del->bind_param("i", $doctor_id);
$del->execute();
$del->close();

/* 3️⃣ DELETE WORKING DAYS */
$del = $conn->prepare("
    DELETE FROM doctor_working_days
    WHERE doctor_id = ?
");
$del->bind_param("i", $doctor_id);
$del->execute();
$del->close();

   
    /* Save fresh schedule */
    foreach ($_POST['schedule'] as $day => $slots) {

    // ✅ normalize day (CRITICAL)
    $day = ucfirst(strtolower($day));


        /* SAVE DAY ALWAYS */
        $wd = $conn->prepare("
            INSERT INTO doctor_working_days
                (doctor_id, day_of_week, is_active)
            VALUES (?, ?, 1)
        ");
        $wd->bind_param("is", $doctor_id, $day);
        $wd->execute();
        $working_day_id = $wd->insert_id;
        $wd->close();

        /* SAVE TIME SLOTS (if any) */
        if (!is_array($slots)) continue;

        $stmt = $conn->prepare("
            INSERT INTO doctor_day_schedules
                (working_day_id, start_time, end_time, is_active)
            VALUES (?, ?, ?, 1)
        ");

        foreach ($slots as $slot) {

            if (empty($slot['from']) || empty($slot['to'])) continue;

            $stmt->bind_param(
                "iss",
                $working_day_id,
                $slot['from'],
                $slot['to']
            );

            if (!$stmt->execute()) {
    throw new Exception('Schedule save failed: '.$stmt->error);
}

$schedule_id = $stmt->insert_id;

/* ================= SAVE BREAKS ================= */
if (!empty($slot['breaks']) && is_array($slot['breaks'])) {

    $brStmt = $conn->prepare("
        INSERT INTO doctor_schedule_breaks
            (schedule_id, break_start, break_end, created_at)
        VALUES (?, ?, ?, NOW())
    ");

    foreach ($slot['breaks'] as $br) {

        if (empty($br['from']) || empty($br['to'])) {
            continue;
        }

        $brStmt->bind_param(
            "iss",
            $schedule_id,
            $br['from'],
            $br['to']
        );

        if (!$brStmt->execute()) {
            throw new Exception(
                'Break save failed: '.$brStmt->error
            );
        }
    }
    $brStmt->close();
}
        }
        $stmt->close();
    }
}
$newDoctor = [
    'license_no'        => $license_no,
    'is_surgeon'        => $is_surgeon,
    'time_slot_minutes' => $time_slot_minutes,
    'specializations'   => $_POST['specialization_codes'] ?? [],
    'charges'           => $_POST['charges'] ?? [],
    'share'             => $_POST['doctor_share'] ?? [],
    'schedule'          => $_POST['schedule'] ?? []
];
$changedFields = [];
if ($exists) {
    if (($oldDoctor['license_no'] ?? '') != $license_no) {
        $changedFields['License No'] = [
            'old' => $oldDoctor['license_no'] ?? '',
            'new' => $license_no
        ];
    }
    if ((int)($oldDoctor['is_surgeon'] ?? 0) != $is_surgeon) {
        $changedFields['Surgeon'] = [
            'old' => ($oldDoctor['is_surgeon'] ?? 0) ? 'Yes' : 'No',
            'new' => $is_surgeon ? 'Yes' : 'No'
        ];
    }
    if ((int)($oldDoctor['time_slot_minutes'] ?? 0) != $time_slot_minutes) {
        $changedFields['Time Slot'] = [
            'old' => $oldDoctor['time_slot_minutes'] ?? '',
            'new' => $time_slot_minutes
        ];
    }
    $oldSpecs = $oldSpecializations;
sort($oldSpecs);

$newSpecs = $_POST['specialization_codes'] ?? [];
sort($newSpecs);

if ($oldSpecs != $newSpecs) {
    $changedFields['Specializations'] = [
        'old' => implode(', ', $oldSpecs),
        'new' => implode(', ', $newSpecs)
    ];
}
$share = $_POST['doctor_share'] ?? [];

/* Consultation */
if (($oldShare['consultation_percentage'] ?? '') != ($share['consultation']['percentage'] ?? '')) {
    $changedFields['Consultation %'] = [
        'old' => $oldShare['consultation_percentage'] ?? '',
        'new' => $share['consultation']['percentage'] ?? ''
    ];
}

if (($oldShare['consultation_flat_amount'] ?? '') != ($share['consultation']['flat_amount'] ?? '')) {
    $changedFields['Consultation Flat Amount'] = [
        'old' => $oldShare['consultation_flat_amount'] ?? '',
        'new' => $share['consultation']['flat_amount'] ?? ''
    ];
}

if (($oldShare['consultation_wef_date'] ?? '') != ($share['consultation']['wef_date'] ?? '')) {
    $changedFields['Consultation WEF Date'] = [
        'old' => $oldShare['consultation_wef_date'] ?? '',
        'new' => $share['consultation']['wef_date'] ?? ''
    ];
}

/* Invoice */
if (($oldShare['invoice_percentage'] ?? '') != ($share['invoice']['percentage'] ?? '')) {
    $changedFields['Invoice %'] = [
        'old' => $oldShare['invoice_percentage'] ?? '',
        'new' => $share['invoice']['percentage'] ?? ''
    ];
}

if (($oldShare['invoice_flat_amount'] ?? '') != ($share['invoice']['flat_amount'] ?? '')) {
    $changedFields['Invoice Flat Amount'] = [
        'old' => $oldShare['invoice_flat_amount'] ?? '',
        'new' => $share['invoice']['flat_amount'] ?? ''
    ];
}

if (($oldShare['invoice_wef_date'] ?? '') != ($share['invoice']['wef_date'] ?? '')) {
    $changedFields['Invoice WEF Date'] = [
        'old' => $oldShare['invoice_wef_date'] ?? '',
        'new' => $share['invoice']['wef_date'] ?? ''
    ];
}

/* Service */
if (($oldShare['service_percentage'] ?? '') != ($share['service']['percentage'] ?? '')) {
    $changedFields['Service %'] = [
        'old' => $oldShare['service_percentage'] ?? '',
        'new' => $share['service']['percentage'] ?? ''
    ];
}

if (($oldShare['service_flat_amount'] ?? '') != ($share['service']['flat_amount'] ?? '')) {
    $changedFields['Service Flat Amount'] = [
        'old' => $oldShare['service_flat_amount'] ?? '',
        'new' => $share['service']['flat_amount'] ?? ''
    ];
}

if (($oldShare['service_wef_date'] ?? '') != ($share['service']['wef_date'] ?? '')) {
    $changedFields['Service WEF Date'] = [
        'old' => $oldShare['service_wef_date'] ?? '',
        'new' => $share['service']['wef_date'] ?? ''
    ];
}
/* ================= DOCTOR CHARGES AUDIT ================= */

$oldChargesMap = [];
foreach ($oldCharges as $row) {
    $oldChargesMap[$row['visit_type']] = $row;
}

$newChargesMap = [];
foreach (($_POST['charges'] ?? []) as $row) {
    if (!empty($row['visit_type'])) {
        $newChargesMap[$row['visit_type']] = $row;
    }
}

$visitTypes = ['NORMAL', 'REVISIT', 'EMERGENCY'];

foreach ($visitTypes as $visitType) {

    $old = $oldChargesMap[$visitType] ?? [];
    $new = $newChargesMap[$visitType] ?? [];

    if (($old['charge_amount'] ?? '') != ($new['charge_amount'] ?? '')) {
        $changedFields[$visitType . ' Charge Amount'] = [
            'old' => $old['charge_amount'] ?? '',
            'new' => $new['charge_amount'] ?? ''
        ];
    }

    if (($old['valid_from_day'] ?? '') != ($new['valid_from_day'] ?? '')) {
        $changedFields[$visitType . ' Valid From Day'] = [
            'old' => $old['valid_from_day'] ?? '',
            'new' => $new['valid_from_day'] ?? ''
        ];
    }

    if (($old['valid_to_day'] ?? '') != ($new['valid_to_day'] ?? '')) {
        $changedFields[$visitType . ' Valid To Day'] = [
            'old' => $old['valid_to_day'] ?? '',
            'new' => $new['valid_to_day'] ?? ''
        ];
    }
}
/* ================= DOCTOR MEDIA AUDIT ================= */

if (($oldMedia['profile_photo'] ?? '') != ($newMedia['profile_photo'] ?? '')) {

    $changedFields['Profile Photo'] = [
        'old' => empty($oldMedia['profile_photo']) ? 'Not Uploaded' : 'Uploaded',
        'new' => empty($newMedia['profile_photo']) ? 'Not Uploaded' : 'Uploaded'
    ];
}

if (($oldMedia['signature_image'] ?? '') != ($newMedia['signature_image'] ?? '')) {

    $changedFields['Signature'] = [
        'old' => empty($oldMedia['signature_image']) ? 'Not Uploaded' : 'Uploaded',
        'new' => empty($newMedia['signature_image']) ? 'Not Uploaded' : 'Uploaded'
    ];
}
/* ================= DOCTOR SCHEDULE AUDIT ================= */

$newSchedule = [];

foreach (($_POST['schedule'] ?? []) as $day => $slots) {

    $day = strtoupper(trim($day));

    if (!isset($newSchedule[$day])) {
        $newSchedule[$day] = [];
    }

    foreach ($slots as $slot) {

        $newSchedule[$day][] = [
            'from'   => trim($slot['from'] ?? ''),
            'to'     => trim($slot['to'] ?? ''),
            'breaks' => $slot['breaks'] ?? []
        ];
    }
}

/* ================= COMPARE SCHEDULE ================= */

foreach ($newSchedule as $day => $slots) {

    $oldSlots = $oldSchedule[$day] ?? [];

    foreach ($slots as $i => $slot) {

        $oldSlot = $oldSlots[$i] ?? [];

        /* ---------- FROM TIME ---------- */

        if (($oldSlot['from'] ?? '') != ($slot['from'] ?? '')) {

            $changedFields[$day . ' From Time'] = [
                'old' => $oldSlot['from'] ?? '',
                'new' => $slot['from'] ?? ''
            ];
        }

        /* ---------- TO TIME ---------- */

        if (($oldSlot['to'] ?? '') != ($slot['to'] ?? '')) {

            $changedFields[$day . ' To Time'] = [
                'old' => $oldSlot['to'] ?? '',
                'new' => $slot['to'] ?? ''
            ];
        }

        /* ---------- BREAK AUDIT ---------- */

        $oldBreaks = $oldSlot['breaks'] ?? [];
        $newBreaks = [];

if (!empty($slot['breaks']) && is_array($slot['breaks'])) {

    foreach ($slot['breaks'] as $br) {

        if (empty($br['from']) || empty($br['to'])) {
            continue;
        }

        $newBreaks[] = [
            'from' => trim($br['from']),
            'to'   => trim($br['to'])
        ];
    }
}

        $maxBreaks = max(count($oldBreaks), count($newBreaks));

        for ($b = 0; $b < $maxBreaks; $b++) {

            $oldBreak = $oldBreaks[$b] ?? [];
            $newBreak = $newBreaks[$b] ?? [];

            if (($oldBreak['from'] ?? '') != ($newBreak['from'] ?? '')) {

                $changedFields[$day . ' Break ' . ($b + 1) . ' From'] = [
                    'old' => $oldBreak['from'] ?? '',
                    'new' => $newBreak['from'] ?? ''
                ];
            }

            if (($oldBreak['to'] ?? '') != ($newBreak['to'] ?? '')) {

                $changedFields[$day . ' Break ' . ($b + 1) . ' To'] = [
                    'old' => $oldBreak['to'] ?? '',
                    'new' => $newBreak['to'] ?? ''
                ];
            }
        }
    }
}
}
/* ================= COMMIT ================= */
    $conn->commit();
    /* ================= FETCH USER CONTACT DETAILS ================= */
$userQ = $conn->prepare("
    SELECT full_name, email, mobile_no
    FROM users
    WHERE id = ?
");
$userQ->bind_param("i", $user_id);
$userQ->execute();
$userQ->bind_result($full_name, $email, $mobile_no);
$userQ->fetch();
$userQ->close();
    /* ================= COMMUNICATION (ONLY ON NEW DOCTOR) ================= */
if (!$exists) {

    /* ================= EMAIL ================= */
    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $tpl = $_SERVER['DOCUMENT_ROOT']
            . '/communication/email_templates/doctor_welcome.php';

        if (file_exists($tpl)) {

            // variables used inside template
            $doctor_name = $full_name ?? 'Doctor';

            $emailData = require $tpl;

            sendDynamicMail(
                $email,
                $emailData['subject'],
                $emailData['message']
            );
        }
    }

    /* ================= SMS ================= */
    if (!empty($mobile_no)) {

        $tpl = $_SERVER['DOCUMENT_ROOT']
            . '/communication/sms/sms_templates/doctor_welcome.php';

        if (file_exists($tpl)) {
            $smsMessage = require $tpl;
            sendSMS($mobile_no, $smsMessage);
        }
    }

    /* ================= WHATSAPP ================= */
    if (!empty($mobile_no)) {

        $tpl = $_SERVER['DOCUMENT_ROOT']
            . '/communication/whatsapp/whatsapp_templates/doctor_welcome.php';

        if (file_exists($tpl)) {
            $waMessage = require $tpl;
            sendWhatsApp($mobile_no, $waMessage);
        }
    }
}
/* ================= AUDIT LOG ================= */
if (!$exists) {
    // Create Audit
    addAuditLog(
        'Doctor Master',
        'Create',
        "Doctor Created : {$full_name}",
        $doctor_id,
        '',
        json_encode($newDoctor),
        'SUCCESS'
    );
} elseif (!empty($changedFields)) {
    // Store ONLY changed fields
    $oldChangedFields = [];
    $newChangedFields = [];
    foreach ($changedFields as $field => $values) {
        $oldChangedFields[$field] = $values['old'];
        $newChangedFields[$field] = $values['new'];
    }
    addAuditLog(
        'Doctor Master',
        'Update',
        "Doctor Updated : {$full_name}",
        $doctor_id,
        json_encode($oldChangedFields),
        json_encode($newChangedFields),
        'SUCCESS'
    );
}
header("Location: doctor_list.php?success=1");
exit;
} catch (Exception $e) {
    $conn->rollback();
    debugFail($e->getMessage(), $_POST);
}