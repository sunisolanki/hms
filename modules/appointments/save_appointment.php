<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$source = strtoupper(trim($_POST['source'] ?? 'INTERNAL'));

if (!in_array($source, ['WEBSITE', 'INTERNAL'], true)) {
    $source = 'INTERNAL';
}

date_default_timezone_set('Asia/Kolkata');

ini_set('display_errors', 1);
error_reporting(E_ALL);

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/communication/send_mail.php';

function getDoctorConsultationFee($conn, $doctor_user_id, $visit_type, $patient_id) {

    // map user → doctor
    $stmt = $conn->prepare("SELECT id FROM doctor_master WHERE user_id=? LIMIT 1");
    $stmt->bind_param("i", $doctor_user_id);
    $stmt->execute();
    $stmt->bind_result($doctor_id);

    if (!$stmt->fetch()) {
        return 0;
    }
    $stmt->close();

    // REVISIT slab
    if ($visit_type === 'REVISIT' && $patient_id > 0) {

$vc = $conn->prepare("
    SELECT COUNT(*) 
    FROM appointments
    WHERE patient_id = ?

        ");
        $vc->bind_param("i", $patient_id);
        $vc->execute();
        $vc->bind_result($visit_count);
        $vc->fetch();
        $vc->close();

        $stmt = $conn->prepare("
            SELECT charge_amount
            FROM doctor_charges
            WHERE doctor_id=?
              AND visit_type='REVISIT'
              AND ? BETWEEN valid_from_day AND valid_to_day
              AND is_active=1
            LIMIT 1
        ");
        $stmt->bind_param("ii", $doctor_id, $visit_count);
        $stmt->execute();
        $stmt->bind_result($fee);

        if ($stmt->fetch()) {
            return $fee;
        }
        $stmt->close();
    }

    // fallback NORMAL
    $stmt = $conn->prepare("
        SELECT charge_amount
        FROM doctor_charges
        WHERE doctor_id=?
          AND visit_type='NORMAL'
          AND is_active=1
        LIMIT 1
    ");
    $stmt->bind_param("i", $doctor_id);
    $stmt->execute();
    $stmt->bind_result($fee);

    return $stmt->fetch() ? $fee : 0;
}


/* =========================
   SECURITY
========================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Invalid request');
}

$is_revisit = (int)($_POST['is_revisit'] ?? 0);
$patient_id = (int)($_POST['patient_id'] ?? 0);
$created_by = (int)($_SESSION['user_id'] ?? 0);



/* =========================
   COLLECT DATA
========================= */
$type             = $_POST['type'] ?? '';
$department_id    = (int)($_POST['department_id'] ?? 0);
$entity_id        = (int)($_POST['entity_id'] ?? 0);

/* =========================
   TELEMEDICINE MODE
========================= */
$consultation_mode = 'PHYSICAL';

if ($type === 'doctor' && ($_POST['consultation_mode'] ?? '') === 'TELEMEDICINE') {
    $consultation_mode = 'TELEMEDICINE';
}
/* =========================
   FIX: AUTO-FETCH DEPARTMENT FOR SERVICE

if ($type === 'service' && $entity_id > 0) {

    $stmt = $conn->prepare("
        SELECT department_id 
        FROM service_master 
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $entity_id);
    $stmt->execute();
    $stmt->bind_result($service_department_id);

    if ($stmt->fetch()) {
        $department_id = (int)$service_department_id;
    }

    $stmt->close();
}

========================= */

$visit_type = ($is_revisit === 1) ? 'REVISIT' : 'NORMAL';

if ($type === 'doctor') {

    // 🔐 INTERNAL → trust UI-calculated fee (NORMAL / REVISIT already handled)
    if ($source === 'INTERNAL') {
        $consultation_fee = (float)($_POST['fee'] ?? 0);
    }
    // 🌐 WEBSITE → always calculate on backend
    else {
        $consultation_fee = getDoctorConsultationFee(
            $conn,
            $entity_id,
            $visit_type,
            $patient_id
        );
    }

} else {
    // service
    $consultation_fee = (float)($_POST['fee'] ?? 0);
}



$appointment_date = $_POST['appointment_date'] ?? '';
$appointment_time = $_POST['appointment_time'] ?? '';

$title            = $_POST['title'] ?? '';
$first_name       = $_POST['first_name'] ?? '';
$last_name        = $_POST['last_name'] ?? '';
$sex              = $_POST['sex'] ?? '';
$marital_status   = $_POST['marital_status'] ?? '';
$guardian_name    = $_POST['guardian_name'] ?? '';

$aadhaar          = $_POST['aadhaar_no'] ?? '';
$mobile           = $_POST['mobile'] ?? '';
$phone            = $_POST['phone'] ?? '';
$email            = $_POST['email'] ?? '';

$age_y = (int)($_POST['age_y'] ?? 0);
$age_m = (int)($_POST['age_m'] ?? 0);
$age_d = (int)($_POST['age_d'] ?? 0);

$dob = trim($_POST['dob'] ?? '');

/* If DOB empty but age provided → calculate DOB */
if ($dob === '' && ($age_y > 0 || $age_m > 0 || $age_d > 0)) {
    $today = new DateTime();
    $today->modify("-{$age_y} years");
    $today->modify("-{$age_m} months");
    $today->modify("-{$age_d} days");
    $dob = $today->format('Y-m-d');
}

/* Final safety - convert empty string to NULL */
if ($dob === '') {
    $dob = null;
}

$age_y            = (int)($_POST['age_y'] ?? 0);
$age_m            = (int)($_POST['age_m'] ?? 0);
$age_d            = (int)($_POST['age_d'] ?? 0);

$city_id          = (int)($_POST['city'] ?? 0);
$pincode          = $_POST['pincode'] ?? '';
$address          = $_POST['address'] ?? '';
$remarks          = $_POST['remarks'] ?? '';


$payment_mode     = $_POST['payment_mode'] ?? '';
$paid_amount      = (float)($_POST['paid_amount'] ?? 0);
$balance_amount   = (float)($_POST['balance'] ?? 0);



/* =========================
   FORCE WEBSITE RULES
========================= */
if ($source === 'WEBSITE') {

    // 🔒 BLOCK WITHOUT PAYMENT
    if (empty($_SESSION['PAYMENT_CONFIRMED']['txnid'])) {
        die('Payment not confirmed. Appointment not saved.');
    }

    // Website users cannot revisit
    $is_revisit = 0;
    $patient_id = 0;

    // ✅ Payment already done online
    $payment_mode   = 'Online';
    $paid_amount    = $consultation_fee;
    $balance_amount = 0;
}

/* =========================
   APPOINTMENT STATUS LOGIC
========================= */

if ($consultation_fee == 0) {
    // Free revisit or free consultation
    $status = 'Confirmed';

} elseif ($source === 'WEBSITE') {
    // Website booking is always prepaid
    $status = 'Confirmed';

} else {
    // Internal / Reception
    if ($paid_amount <= 0) {
        $status = 'Payment Pending';
    } elseif ($paid_amount < $consultation_fee) {
        $status = 'Partial Paid';
    } else {
        $status = 'Confirmed';
    }
}


/* =========================
   BACKEND SAFETY (NO UI MESSAGE)
========================= */
if (!$type || !$entity_id || !$appointment_date || !$appointment_time ||
    !$first_name || !$mobile || !$city_id) {

    header('Location: add_appointment.php?error=validation');
    exit;
}


/* =========================
   STEP 3 — PATIENT MASTER (FINAL)
========================= */

/* Check existing patient */
/* =========================
   PATIENT MASTER — SINGLE SOURCE OF TRUTH
========================= */

/* 1️⃣ Always check by mobile */
/*
$chk = $conn->prepare("SELECT id FROM patient_master WHERE mobile = ? LIMIT 1");
$chk->bind_param("s", $mobile);
$chk->execute();
$res = $chk->get_result();

if ($row = $res->fetch_assoc()) {
    $patient_id = (int)$row['id'];
}
$chk->close();


if ($patient_id === 0) {

    $stmt = $conn->prepare("
        INSERT INTO patient_master (
            title, first_name, last_name, sex, marital_status, guardian_name,
            aadhaar, mobile, phone, email,
            dob, age_y, age_m, age_d,
            city_id, pincode, address
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?
        )
    ");

    $stmt->bind_param(
        "sssssssssssiiiiss",
        $title,
        $first_name,
        $last_name,
        $sex,
        $marital_status,
        $guardian_name,
        $aadhaar,
        $mobile,
        $phone,
        $email,
        $dob,
        $age_y,
        $age_m,
        $age_d,
        $city_id,
        $pincode,
        $address
    );

    $stmt->execute();
    $patient_id = (int)$stmt->insert_id;
    $stmt->close();
}

          
if ($patient_id <= 0) {
    die('PATIENT ID NOT GENERATED');
}
*/
/* =========================
   UPDATE PATIENT MASTER ON REVISIT
========================= */
if ($is_revisit === 1 && $patient_id > 0) {

    $upd = $conn->prepare("
        UPDATE patient_master SET
            title = ?,
            first_name = ?,
            last_name = ?,
            sex = ?,
            marital_status = ?,
            guardian_name = ?,
            aadhaar = ?,
            mobile = ?,
            phone = ?,
            email = ?,
            dob = ?,
            city_id = ?,
            pincode = ?,
            address = ?
        WHERE id = ?
    ");

    $upd->bind_param(
        "sssssssssssissi",
        $title,
        $first_name,
        $last_name,
        $sex,
        $marital_status,
        $guardian_name,
        $aadhaar,
        $mobile,
        $phone,
        $email,
        $dob, 
        $city_id,
        $pincode,
        $address,
        $patient_id
    );

    $upd->execute();
    $upd->close();
}


/* =========================
   SLOT SAFETY CHECK
========================= */
$chk = $conn->prepare("
    SELECT COUNT(*) 
    FROM appointments
    WHERE appointment_date = ?
      AND appointment_time = ?
      AND entity_id = ?
");
$chk->bind_param("ssi", $appointment_date, $appointment_time, $entity_id);
$chk->execute();
$chk->bind_result($slotUsed);
$chk->fetch();
$chk->close();

if ($slotUsed > 0) {
    die('Slot already booked');
}


$payment_txnid = null;

if ($source === 'WEBSITE' && !empty($_SESSION['PAYMENT_CONFIRMED']['txnid'])) {
    $payment_txnid = $_SESSION['PAYMENT_CONFIRMED']['txnid'];
}

/* =========================
   INSERT APPOINTMENT
========================= */
$stmt = $conn->prepare("
    INSERT INTO appointments (
    patient_id,
    visit_type,
    type, consultation_mode, department_id, entity_id,
        appointment_date, appointment_time,
        title, first_name, last_name, sex,
        marital_status, guardian_name,
        aadhaar, mobile, phone, email,
        dob, age_y, age_m, age_d,
        city_id, pincode, address, remarks,
        consultation_fee, payment_mode,
        paid_amount, balance_amount,
        payment_txnid,
        status,
        source,
        created_by
    ) VALUES (
        ?, ?, ?, ?, ?, ?,
        ?, ?, 
        ?, ?, ?, ?,
        ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?,
        ?, ?,
        ?,
        ?,
        ?,
        ?
    )
");

$stmt->bind_param(
    "isssiisssssssssssssiiiisssdsddsssi",
    $patient_id,
    $visit_type,
    $type,
    $consultation_mode,
    $department_id,
    $entity_id,
    $appointment_date,
    $appointment_time,
    $title,
    $first_name,
    $last_name,
    $sex,
    $marital_status,
    $guardian_name,
    $aadhaar,
    $mobile,
    $phone,
    $email,
    $dob,
    $age_y,
    $age_m,
    $age_d,
    $city_id,
    $pincode,
    $address,
    $remarks,
    $consultation_fee,
    $payment_mode,
    $paid_amount,
    $balance_amount,
    $payment_txnid,
    $status,
    $source,
    $created_by
);

if (!$stmt->execute()) {
    die('Save failed: ' . $stmt->error);
}

$appointment_id = $stmt->insert_id;
$stmt->close();

/* =========================
   TELEMEDICINE CONSULTATION
========================= */
if ($consultation_mode === 'TELEMEDICINE' && $type === 'doctor') {
    $room_name = 'WSH-' . $appointment_id . '-' . bin2hex(random_bytes(8));

    $teleStmt = $conn->prepare("
        INSERT INTO telemedicine_consultations (
            appointment_id,
            room_name,
            room_status
        ) VALUES (?, ?, 'scheduled')
    ");

    if (!$teleStmt) {
        die('Telemedicine setup failed: ' . $conn->error);
    }

    $teleStmt->bind_param(
        "is",
        $appointment_id,
        $room_name
    );

    if (!$teleStmt->execute()) {
        die('Telemedicine setup failed: ' . $teleStmt->error);
    }

    $teleStmt->close();
}
/* =========================
   APPOINTMENT SEQ (GLOBAL)
========================= */
$appointment_seq = $appointment_id;

/* =========================
   TOKEN NUMBER (SAFE)
========================= */
$stmt = $conn->prepare("
    SELECT COUNT(*) + 1
    FROM appointments
    WHERE appointment_date = ?
      AND entity_id = ?
      AND id <> ?
");
$stmt->bind_param("sii", $appointment_date, $entity_id, $appointment_id);
$stmt->execute();
$stmt->bind_result($token_no);
$stmt->fetch();
$stmt->close();

/* =========================
   GENERATE APPOINTMENT CODE
========================= */
$dt = new DateTime($appointment_date . ' ' . date('H:i:s'));

$appointment_code =
    'APT-' .
    $appointment_seq .
    $token_no .
    $dt->format('d') .
    $dt->format('m') .
    $dt->format('y') .
    $dt->format('H') .
    $dt->format('i') .
    $dt->format('s');

/* =========================
   UPDATE RECORD
========================= */
$stmt = $conn->prepare("
    UPDATE appointments
    SET appointment_code = ?,
        token_no = ?,
        appointment_seq = ?
    WHERE id = ?
");
$stmt->bind_param(
    "siii",
    $appointment_code,
    $token_no,
    $appointment_seq,
    $appointment_id
);
$stmt->execute();
$stmt->close();

$entityName = '';

if ($type === 'doctor') {
    $q = $conn->prepare("SELECT full_name FROM users WHERE id=?");
    $q->bind_param("i", $entity_id);
    $q->execute();
    $q->bind_result($entityName);
    $q->fetch();
    $q->close();
}

if ($type === 'service') {
    $q = $conn->prepare("SELECT service_name FROM service_master WHERE id=?");
    $q->bind_param("i", $entity_id);
    $q->execute();
    $q->bind_result($entityName);
    $q->fetch();
    $q->close();
}

/* =========================
   COMMUNICATION (EMAIL / SMS / WHATSAPP)
========================= */

$patient_name = trim($title . ' ' . $first_name . ' ' . $last_name);

$comm = [
    '{{PATIENT_NAME}}'     => $patient_name,
    '{{APPOINTMENT_CODE}}' => $appointment_code,
    '{{DATE}}'             => date('d-m-Y', strtotime($appointment_date)),
    '{{TIME}}'             => $appointment_time,
    '{{ENTITY}}'           => $entityName,
    '{{FEE}}'              => number_format($consultation_fee, 2),
    '{{MOBILE}}'           => $mobile,
    '{{EMAIL}}'            => $email
];

/* ===== EMAIL ===== */
if (is_string($email) && trim($email) !== '') {

    $emailTpl = require $_SERVER['DOCUMENT_ROOT'] .
        '/communication/email_templates/appointment_confirmed.php';

    $emailBody = str_replace(
        array_keys($comm),
        array_values($comm),
        $emailTpl
    );

   $mailSent = sendSMTPMail(
    $email,
    'Appointment Confirmed - ' . $appointment_code,
    $emailBody
);

if (!$mailSent) {
    error_log(
        'APPOINTMENT MAIL FAILED | Email: ' . $email .
        ' | Appointment: ' . $appointment_code
    );
}


}

/* ===== SMS ===== */
$smsTpl = require $_SERVER['DOCUMENT_ROOT'] .
    '/communication/sms/sms_templates/appointment_confirmed.php';

$smsText = str_replace(
    array_keys($comm),
    array_values($comm),
    $smsTpl
);

/* ===== WHATSAPP ===== */
$waTpl = require $_SERVER['DOCUMENT_ROOT'] .
    '/communication/whatsapp/whatsapp_templates/appointment_confirmed.php';

$waText = str_replace(
    array_keys($comm),
    array_values($comm),
    $waTpl
);

/* WhatsApp API will be added later */
// file_put_contents('/tmp/wa_log.txt', $waText);

/* =========================
   SUCCESS
========================= */

if ($source === 'WEBSITE') {

    $departmentNameForThankYou = trim(
        $_POST['department_name'] ?? ''
    );

    $thankYouUrl =
        'https://www.domycheckup.com/appointment-thank-you.php?' .
        http_build_query([
            'appointment_code' => $appointment_code,
            'patient_name'     => $patient_name,
            'doctor_name'      => $entityName,
            'department'       => $departmentNameForThankYou,
            'appointment_date' => $appointment_date,
            'appointment_time' => $appointment_time,
            'token_no'         => $token_no,
            'amount'           => number_format(
                $consultation_fee,
                2,
                '.',
                ''
            ),
            'payment_mode'     => $payment_mode,
            'transaction_id'   => $payment_txnid ?? '',
            'mobile'           => $mobile
        ]);

    unset($_SESSION['PAYMENT_CONFIRMED']);

    echo "<script>
        window.top.location.href = " .
        json_encode($thankYouUrl) .
        ";
    </script>";

    exit;
}

/* =========================
   INTERNAL SUCCESS
========================= */

unset($_SESSION['PAYMENT_CONFIRMED']);

echo "<script>
    window.top.location.href = 'appointment_list.php?saved=1';
</script>";

exit;