<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/communication/send_mail.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
date_default_timezone_set('Asia/Kolkata');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    die("Invalid request method.");
}
if (!checkPermission('patient_registration', 'create')) {
    header('Location: /access_denied.php');
    exit;
}
if (empty($_SESSION['csrf']) || !isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    header('Location: /access_denied.php');
    exit;
}
$conn->begin_transaction();
$current_appointment_id = (int)($_POST['appointment_id'] ?? 0);
try {
    /* ===============================
       BASIC DATA
    =============================== */
    $type              = $_POST['type'] ?? '';
$consultation_mode = $_POST['consultation_mode'] ?? 'PHYSICAL';
$ipd_no = '';

if ($type !== 'doctor') {
    $consultation_mode = 'PHYSICAL';
}

if (!in_array($consultation_mode, ['PHYSICAL', 'TELEMEDICINE'], true)) {
    $consultation_mode = 'PHYSICAL';
}
    $department_id     = $_POST['department_id'] ?? null;
    $doctor_id         = $_POST['entity_id'] ?? null;
    $appointment_date  = $_POST['appointment_date'] ?? '';
    $appointment_time  = $_POST['appointment_time'] ?? '';
    $is_revisit        = $_POST['is_revisit'] ?? 0;
    $is_emergency = $_POST['is_emergency'] ?? 0;

// if ($type !== 'doctor') {
//     throw new Exception("Only OPD (doctor) supported now.");
// }

    if ($type == 'doctor') {

    if (!$doctor_id || !$appointment_date || !$appointment_time) {
        throw new Exception("Missing appointment details.");
    }

}

if ($type == 'ipd') {

    if (!$doctor_id || !$appointment_date) {
        throw new Exception("Missing admission details.");
    }

}

    /* ===============================
       PATIENT BASIC DATA
    =============================== */
    $patient_id    = $_POST['patient_id'] ?? null;
    $title         = $_POST['title'] ?? '';
    $first_name    = trim($_POST['first_name'] ?? '');
    $last_name     = trim($_POST['last_name'] ?? '');
    $sex           = $_POST['sex'] ?? '';
    $marital       = $_POST['marital_status'] ?? '';
    $guardian      = trim($_POST['guardian_name'] ?? '');
    $aadhaar       = trim($_POST['aadhaar_no'] ?? '');
    $mobile        = trim($_POST['mobile'] ?? '');
    $abha_id = trim($_POST['abha_id'] ?? '');
    // Captured BEFORE any existing-value preservation below restores $abha_id,
    // so it records what the user actually submitted. The ABDM link upsert is
    // gated on this: a blank/missing ABHA must not rewrite the existing
    // link row's verification_status / verified_at.
    $abha_supplied = ($abha_id !== '');
    $abha_verified = (int)($_POST['abha_verified'] ?? 0);
    $phone         = trim($_POST['phone'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $dob           = $_POST['dob'] ?? null;
    $age_y         = $_POST['age_y'] ?? null;
    $age_m         = $_POST['age_m'] ?? null;
    $age_d         = $_POST['age_d'] ?? null;
    $city_id       = $_POST['city'] ?? null;
    $pincode       = $_POST['pincode'] ?? '';
    $address       = trim($_POST['address'] ?? '');
    $remarks       = trim($_POST['remarks'] ?? '');
    if (!$first_name || !$mobile) {
        throw new Exception("Patient name and mobile required.");
    }
    $patient_username = $mobile;
$patient_plain_password = ucfirst(strtolower($first_name)) . '@' . substr($mobile, -4);
$patient_password_hash = password_hash($patient_plain_password, PASSWORD_DEFAULT);
$new_patient_created = false;
    /* ===============================
       HANDLE PATIENT
    =============================== */
    if ($is_revisit == 1 && $patient_id) {
        // Revisit → use existing patient
        $stmt = $conn->prepare("SELECT id, uhid FROM patient_master WHERE id = ?");
        $stmt->bind_param("i", $patient_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if (!$res->num_rows) {
            throw new Exception("Invalid revisit patient.");
        }
        $patientData = $res->fetch_assoc();
        $patient_id  = $patientData['id'];
        $uhid        = $patientData['uhid'];
        $stmt->close();
// ✅ UPDATE ABHA ID FOR REVISIT PATIENT
if (!empty($abha_id)) {
    $u = $conn->prepare("
        UPDATE patient_master
        SET abha_id = ?
        WHERE id = ?
    ");
    $u->bind_param("si", $abha_id, $patient_id);
    $u->execute();
    $u->close();
}
    } else {
        /*
         * IDENTITY RESOLUTION BEFORE ANY WRITE
         * -----------------------------------
         *
         * This block used to look the patient up by Aadhaar and fall back to
         * mobile only when Aadhaar was blank. Because a completed Aadhaar OTP
         * verification always supplies an Aadhaar, the mobile lookup was
         * unreachable in exactly the case it was needed: an existing patient
         * whose stored Aadhaar is blank. The lookup then returned nothing, the
         * code fell through to the INSERT, and the database rejected it on
         * UNIQUE KEY `mobile`. That exception is not a server fault, but it was
         * not in the validation allowlist either, so it surfaced as a bare
         * HTTP 500 "Internal server error." (observed 2026-10-15 17:19:39 and
         * 17:21:01).
         *
         * Aadhaar and mobile are now resolved independently and compared
         * before anything is written. Nothing below this point runs until the
         * identity of the patient is unambiguous.
         *
         * ABHA SAFETY: a newly verified ABHA is NEVER attached to a patient
         * that was matched on mobile alone. A mobile match on its own is not
         * proof of identity - numbers are reassigned, shared and mistyped - so
         * that case is refused for an operator to resolve, and no ABHA value
         * is written on that path.
         */
        $matchByAadhaar = null;
        $matchByMobile = null;
        $matchByUsername = null;

        if (!empty($aadhaar)) {
            $resolveStmt = $conn->prepare("SELECT id, uhid, mobile, abha_id FROM patient_master WHERE aadhaar = ? LIMIT 1");
            $resolveStmt->bind_param("s", $aadhaar);
            $resolveStmt->execute();
            $matchByAadhaar = $resolveStmt->get_result()->fetch_assoc();
            $resolveStmt->close();
        }

        if (!empty($mobile)) {
            $resolveStmt = $conn->prepare("SELECT id, uhid, aadhaar, abha_id FROM patient_master WHERE mobile = ? LIMIT 1");
            $resolveStmt->bind_param("s", $mobile);
            $resolveStmt->execute();
            $matchByMobile = $resolveStmt->get_result()->fetch_assoc();
            $resolveStmt->close();
        }

        /*
         * The portal username is derived from the mobile number, but historical
         * rows may not follow that rule, so it is resolved independently. A
         * username belonging to a different patient than the one the other
         * identifiers selected is a genuine conflict and is reported as one.
         */
        if (!empty($patient_username)) {
            $resolveStmt = $conn->prepare("SELECT id, uhid FROM patient_master WHERE username = ? LIMIT 1");
            $resolveStmt->bind_param("s", $patient_username);
            $resolveStmt->execute();
            $matchByUsername = $resolveStmt->get_result()->fetch_assoc();
            $resolveStmt->close();
        }

        $resolvedExisting = null;

        if ($matchByAadhaar && $matchByMobile) {
            if ((int)$matchByAadhaar['id'] !== (int)$matchByMobile['id']) {
                throw new Exception("REGCONFLICT_TWO_PATIENTS");
            }
            $resolvedExisting = $matchByAadhaar;
        } elseif ($matchByAadhaar) {
            // Aadhaar is the verified identifier. The patient is only accepted
            // when the mobile on file agrees with the submitted mobile.
            $storedMobile = trim((string)($matchByAadhaar['mobile'] ?? ''));
            if ($storedMobile !== '' && $storedMobile !== trim((string)$mobile)) {
                throw new Exception("REGCONFLICT_MOBILE_MISMATCH");
            }
            $resolvedExisting = $matchByAadhaar;
        } elseif ($matchByMobile) {
            // Mobile matches but the verified Aadhaar matches nobody. This is
            // NOT sufficient to establish identity, and it is explicitly NOT
            // sufficient to attach a newly verified ABHA. Refused, no write.
            throw new Exception("REGCONFLICT_MOBILE_ONLY_MATCH");
        }

        if ($matchByUsername
            && $resolvedExisting
            && (int)$matchByUsername['id'] !== (int)$resolvedExisting['id']) {
            throw new Exception("REGCONFLICT_DUPLICATE_USERNAME");
        }

        if ($matchByUsername && !$resolvedExisting) {
            throw new Exception("REGCONFLICT_DUPLICATE_USERNAME");
        }

        if ($resolvedExisting) {
            $patientData = $resolvedExisting;
            $patient_id  = $patientData['id'];
            $uhid        = $patientData['uhid'];
            // ABHA preservation — mirror update_patient.php logic
            if ($abha_id === '') {
                $existingAbhaStmt = $conn->prepare("SELECT abha_id FROM patient_master WHERE id=? LIMIT 1");
                $existingAbhaStmt->bind_param("i", $patient_id);
                $existingAbhaStmt->execute();
                $existingAbhaRow = $existingAbhaStmt->get_result()->fetch_assoc();
                $existingAbhaStmt->close();
                if ($existingAbhaRow && !empty($existingAbhaRow['abha_id'])) {
                    $abha_id = $existingAbhaRow['abha_id'];
                }
            }
$updateStmt = $conn->prepare("
UPDATE patient_master SET
title=?,
first_name=?,
last_name=?,
sex=?,
marital_status=?,
guardian_name=?,
aadhaar=?,
abha_id=?,
mobile=?,
phone=?,
email=?,
dob=?,
age_y=?,
age_m=?,
age_d=?,
city_id=?,
pincode=?,
address=?
WHERE id=?
");
$updateStmt->bind_param(
"sssssssssssssiiiisi",
$title,
$first_name,
$last_name,
$sex,
$marital,
$guardian,
$aadhaar,
$abha_id,
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
$patient_id
);
if(!$updateStmt->execute()){
    throw new Exception($updateStmt->error);
}
$updateStmt->close();
        } else {
            // Generate new UHID
$res2 = $conn->query("SELECT MAX(uhid) AS last_uhid FROM patient_master");
$row2 = $res2->fetch_assoc();
$last_uhid = $row2['last_uhid'] ?? 0;
$uhid = $last_uhid + 1;

// Generate patient login credentials
$patient_username = $mobile;
$patient_plain_password = ucfirst(strtolower($first_name)) . '@' . substr($mobile, -4);
$patient_password_hash = password_hash($patient_plain_password, PASSWORD_DEFAULT);

            // Insert new patient
          $stmt2 = $conn->prepare("
    INSERT INTO patient_master (
    uhid,
    title,
    first_name,
    last_name,
    sex,
    marital_status,
    guardian_name,
    aadhaar,
    abha_id,
    mobile,
    phone,
    email,
    dob,
    age_y,
    age_m,
    age_d,
    city_id,
    pincode,
    address,
    username,
    password
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");
$abhaIdForBind = $abha_id !== '' ? $abha_id : null;
$stmt2->bind_param(
    "issssssssssssiiiissss",
    $uhid,
    $title,
    $first_name,
    $last_name,
    $sex,
    $marital,
    $guardian,
    $aadhaar,
    $abhaIdForBind,
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
    $patient_username,
    $patient_password_hash
);
            if (!$stmt2->execute()) {
                throw new Exception($stmt2->error);
            }
            $new_patient_created = true;
            $patient_id = $stmt2->insert_id;
            $stmt2->close();
        }
    }

/* ===============================
   ABDM PATIENT LINK
   =============================== */
$abdm_link_warning = null;

if ($abha_supplied && !empty($abha_id) && !empty($patient_id)) {
    try {
        /*
         * 1. ONE ABHA NUMBER BELONGS TO ONE PATIENT.
         *
         * abdm_patient_links carries UNIQUE KEY unique_patient (patient_id) but
         * only a NON-UNIQUE KEY idx_abha_number (abha_number). ON DUPLICATE KEY
         * UPDATE can therefore only ever conflict on patient_id, so linking the
         * same ABHA number to a second patient never raised an error and the
         * number ended up pointing at more than one record. Nothing checked
         * ownership before, so check it here.
         *
         * The patient record itself is never rejected over this: when the ABHA
         * is already owned by somebody else the link is refused and the
         * operator is warned after the commit.
         */
        $abdmOwnerStmt = $conn->prepare("
            SELECT patient_id
            FROM abdm_patient_links
            WHERE abha_number = ?
              AND patient_id <> ?
            LIMIT 1
        ");
        $abdmOwnerStmt->bind_param("ii", $abha_id, $patient_id);
        $abdmOwnerStmt->execute();
        $abdmOwnerRow = $abdmOwnerStmt->get_result()->fetch_assoc();
        $abdmOwnerStmt->close();

        if ($abdmOwnerRow) {
            $abdm_link_warning = 'This ABHA number is already linked to another patient. '
                . 'The patient was saved, but the ABHA link was not created.';
            error_log(
                'ABDM LINK REFUSED - ABHA already linked to another patient | Patient ID: ' .
                $patient_id
            );
        } else {
            /*
             * 2. PRESERVE VERIFICATION STATE FOR AN UNCHANGED ABHA NUMBER.
             *
             * $abha_verified is a hidden form field that only the OTP success
             * handlers ever set to 1; on a revisit or a re-registration it is
             * still 0. Writing it straight through used to reset an already
             * Verified link back to Pending and null verified_at, losing a
             * verification that had genuinely succeeded earlier.
             */
            $abdmExistingStmt = $conn->prepare("
                SELECT abha_number, verification_status, verified_at
                FROM abdm_patient_links
                WHERE patient_id = ?
                LIMIT 1
            ");
            $abdmExistingStmt->bind_param("i", $patient_id);
            $abdmExistingStmt->execute();
            $abdmExistingRow = $abdmExistingStmt->get_result()->fetch_assoc();
            $abdmExistingStmt->close();

            if ($abdmExistingRow === null) {
                /* First link for this patient - unchanged behaviour. */
                $verificationStatus = ($abha_verified === 1) ? 'Verified' : 'Pending';
                $verifiedAt = ($abha_verified === 1) ? date('Y-m-d H:i:s') : null;
                $abdmUpsert = "
                    INSERT INTO abdm_patient_links (
                        patient_id,
                        abha_number,
                        verification_status,
                        verified_at
                    ) VALUES (?, ?, ?, ?)
                ";
            } elseif ($abha_verified === 1) {
                /* Freshly verified just now - promote regardless of prior state. */
                $verificationStatus = 'Verified';
                $verifiedAt = date('Y-m-d H:i:s');
                $abdmUpsert = "
                    INSERT INTO abdm_patient_links (
                        patient_id,
                        abha_number,
                        verification_status,
                        verified_at
                    ) VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        abha_number = VALUES(abha_number),
                        verification_status = VALUES(verification_status),
                        verified_at = VALUES(verified_at),
                        updated_at = CURRENT_TIMESTAMP
                ";
            } elseif ((string)$abdmExistingRow['abha_number'] === (string)$abha_id) {
                /*
                 * Same ABHA number, not re-verified this time. Leave
                 * verification_status and verified_at exactly as they are.
                 */
                error_log(
                    'ABDM LINK UNCHANGED - same ABHA resubmitted without re-verification | Patient ID: ' .
                    $patient_id
                );
                $abdmUpsert = null;
            } else {
                /* ABHA number changed - the new number has to be verified again. */
                $verificationStatus = 'Pending';
                $verifiedAt = null;
                $abdmUpsert = "
                    INSERT INTO abdm_patient_links (
                        patient_id,
                        abha_number,
                        verification_status,
                        verified_at
                    ) VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        abha_number = VALUES(abha_number),
                        verification_status = VALUES(verification_status),
                        verified_at = VALUES(verified_at),
                        updated_at = CURRENT_TIMESTAMP
                ";
            }

            if ($abdmUpsert !== null) {
                $abdmLinkStmt = $conn->prepare($abdmUpsert);

                $abdmLinkStmt->bind_param(
                    "isss",
                    $patient_id,
                    $abha_id,
                    $verificationStatus,
                    $verifiedAt
                );

                $abdmLinkStmt->execute();
                $abdmLinkStmt->close();
            }
        }

    } catch (Throwable $e) {
        error_log(
            'ABDM PATIENT LINK SAVE FAILED | Patient ID: ' .
            $patient_id .
            ' | Error: ' .
            $e->getMessage()
        );
    }
}
    
    if ($type == 'doctor') {
/* ===============================
   PREVENT DOUBLE SLOT BOOKING
=============================== */
$stmt = $conn->prepare("
SELECT id
FROM appointments
WHERE entity_id = ?
AND appointment_date = ?
AND appointment_time = ?
AND status != 'CANCELLED'
AND id != ?
");
$stmt->bind_param(
"issi",
$doctor_id,
$appointment_date,
$appointment_time,
$current_appointment_id
);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows > 0) {
    throw new Exception("This slot is already booked.");
}
$stmt->close();
}
if ($type == 'doctor') {
/* ===============================
   GENERATE OPD NUMBER
=============================== */
if ($current_appointment_id == 0) {
$resSeq = $conn->query("
    SELECT MAX(appointment_seq) AS last_seq 
    FROM appointments 
    WHERE type = 'doctor'
    FOR UPDATE
");
$rowSeq = $resSeq->fetch_assoc();
$last_seq = $rowSeq['last_seq'] ?? 0;
$new_seq = $last_seq + 1;
/* ===============================
   GENERATE TOKEN (Daily Per Doctor)
=============================== */
$stmtToken = $conn->prepare("
    SELECT MAX(token_no) AS last_token
    FROM appointments
    WHERE entity_id = ?
    AND appointment_date = ?
    AND type = 'doctor'
    FOR UPDATE
");
$stmtToken->bind_param("is", $doctor_id, $appointment_date);
$stmtToken->execute();
$resToken = $stmtToken->get_result();
$rowToken = $resToken->fetch_assoc();
$last_token = $rowToken['last_token'] ?? 0;
$new_token = $last_token + 1;

$stmtToken->close();
/* ===============================
   GENERATE OPD NUMBER (Doctor-wise Daily)
=============================== */
$doctorCode = str_pad($doctor_id, 3, '0', STR_PAD_LEFT);
$opdSerial  = str_pad($new_seq, 2, '0', STR_PAD_LEFT);
$opd_no = $doctorCode . '-' . $opdSerial;
}
else{
    $stmtOld = $conn->prepare("
    SELECT appointment_code,
           token_no,
           appointment_seq,
           opd_no
    FROM appointments
    WHERE id=?
    ");
    $stmtOld->bind_param("i",$current_appointment_id);
    $stmtOld->execute();
    $old = $stmtOld->get_result()->fetch_assoc();
    $appointment_code = $old['appointment_code'];
    $new_token        = $old['token_no'];
    $new_seq          = $old['appointment_seq'];
    $opd_no           = $old['opd_no'];
    
    /* Generate OPD No. only if not already generated */
if (empty($opd_no)) {

    // Generate next token for this doctor & date
    $stmtToken = $conn->prepare("
        SELECT COALESCE(MAX(token_no),0) AS last_token
FROM appointments
WHERE entity_id = ?
AND appointment_date = ?
AND type = 'doctor'
FOR UPDATE
    ");
    $stmtToken->bind_param("is", $doctor_id, $appointment_date);
    $stmtToken->execute();
    $resToken = $stmtToken->get_result();
    $rowToken = $resToken->fetch_assoc();
    $last_token = $rowToken['last_token'] ?? 0;
    $stmtToken->close();

    $new_token = $last_token + 1;

    // Generate OPD No.
    $doctorCode = str_pad($doctor_id, 3, '0', STR_PAD_LEFT);
    $opdSerial  = str_pad($new_token, 2, '0', STR_PAD_LEFT);
    $opd_no = $doctorCode . '-' . $opdSerial;
}

    $stmtOld->close();
}
}
if ($type == 'ipd') {

    if ($current_appointment_id == 0) {

        $appointment_code = NULL;

        $new_token = 0;
        $new_seq   = 0;
        $opd_no    = '';

        /* Generate IPD Number */
       $resSeq = $conn->query("
    SELECT COALESCE(MAX(CAST(SUBSTRING(ipd_no,5) AS UNSIGNED)),0) AS last_ipd
    FROM appointments
    WHERE type='ipd'
    FOR UPDATE
");

$rowSeq = $resSeq->fetch_assoc();
$ipd_seq = $rowSeq['last_ipd'] + 1;

$new_seq = 0;

$ipd_no = 'IPD-' . str_pad($ipd_seq,6,'0',STR_PAD_LEFT);

    } else {

        /* Existing IPD */
        $stmtOld = $conn->prepare("
            SELECT
                appointment_code,
                appointment_seq,
                ipd_no
            FROM appointments
            WHERE id=?
        ");

        $stmtOld->bind_param("i",$current_appointment_id);
        $stmtOld->execute();

        $old = $stmtOld->get_result()->fetch_assoc();

        $appointment_code = $old['appointment_code'];
        $new_seq = 0;
        $ipd_no           = $old['ipd_no'];

        $new_token = 0;
        $opd_no    = '';

        $stmtOld->close();
    }

}

/* ===============================
   PAYMENT DATA
=============================== */
/* ===============================
   PAYMENT DATA
=============================== */

$payment_txnid = trim($_POST['payment_txnid'] ?? '');

$visit_type = ($is_revisit == 1)
    ? 'REVISIT'
    : 'NORMAL';

/*
   If this registration is coming from an
   existing appointment, preserve the
   appointment payment information.

   Example:
   Appointment:
   Fee     = 1600
   Paid    = 1600
   Balance = 0

   Registration must NOT reset these values.
*/

if ($current_appointment_id > 0) {

    $paymentStmt = $conn->prepare("
        SELECT
    consultation_fee,
    payment_mode,
    paid_amount,
    balance_amount,
    payment_txnid,
    discount_type,
    discount_percent,
    discount_amount,
    net_payable,
    consultation_mode
FROM appointments
        WHERE id = ?
        LIMIT 1
    ");

    $paymentStmt->bind_param(
        "i",
        $current_appointment_id
    );

    $paymentStmt->execute();

    $paymentResult =
        $paymentStmt->get_result();

    $existingPayment =
        $paymentResult->fetch_assoc();

    $paymentStmt->close();

    if (!$existingPayment) {
        throw new Exception(
            "Appointment payment record not found."
        );
    }

    /*
       Preserve existing appointment payment.
    */

    $consultation_fee =
        $existingPayment['consultation_fee'];

    $payment_mode =
        $existingPayment['payment_mode'];

    $paid_amount =
        $existingPayment['paid_amount'];

    $balance_amount =
        $existingPayment['balance_amount'];

    if ($payment_txnid === '') {
        $payment_txnid =
            $existingPayment['payment_txnid'] ?? '';
    }

} else {

    /*
       New registration without appointment.
       Use payment values submitted by form.
    */

    $consultation_fee =
        $_POST['fee'] ?? 0;

    $payment_mode =
        $_POST['payment_mode'] ?? 'Cash';

    $paid_amount =
        $_POST['paid_amount'] ?? 0;

    $balance_amount =
        $_POST['balance'] ?? 0;
}
/* ===============================
   REFERRAL + DISCOUNT DATA
=============================== */

$referral_type =
    $_POST['referral_type'] ?? null;

$internal_referral_id =
    $_POST['internal_referral_id'] ?? null;

$external_referral_id =
    $_POST['external_referral_id'] ?? null;

$empanelment_id =
    $_POST['empanelment_id'] ?? null;

if ($current_appointment_id > 0) {

    /*
       Preserve appointment discount/payment
       calculation.
    */

    $discount_type =
        $existingPayment['discount_type'];

    $discount_percent =
        $existingPayment['discount_percent'];

    $discount_amount =
        $existingPayment['discount_amount'];

    $net_payable =
        $existingPayment['net_payable'];
    
    $consultation_mode =
    $existingPayment['consultation_mode'] ?? 'PHYSICAL';

} else {

    $discount_type =
        $_POST['discount_id'] ?? null;

    $discount_percent =
        $_POST['discount_percent'] ?? 0;

    $discount_amount =
        $_POST['discount_amount'] ?? 0;

    $net_payable =
        $_POST['fee'] ?? 0;
}

$camp =
    isset($_POST['is_camp'])
        ? 1
        : 0;
/* ===============================
   SAVE APPOINTMENT
=============================== */
if ($current_appointment_id > 0) {
/* ===============================
   UPDATE EXISTING APPOINTMENT
=============================== */
$stmtUpdate = $conn->prepare("
UPDATE appointments SET
patient_id=?,
appointment_code=?,
opd_no=?,
ipd_no=?,
token_no=?,
appointment_seq=?,
visit_type=?,
is_emergency=?,
type=?,
consultation_mode=?,
department_id=?,
entity_id=?,
appointment_date=?,
appointment_time=?,
title=?,
first_name=?,
last_name=?,
sex=?,
marital_status=?,
guardian_name=?,
aadhaar=?,
mobile=?,
phone=?,
email=?,
dob=?,
age_y=?,
age_m=?,
age_d=?,
city_id=?,
pincode=?,
address=?,
remarks=?,
consultation_fee=?,
payment_mode=?,
paid_amount=?,
balance_amount=?,
referral_type=?,
internal_referral_id=?,
external_referral_id=?,
empanelment_id=?,
discount_type=?,
discount_percent=?,
discount_amount=?,
camp=?,
net_payable=?,
updated_at=NOW(),
status='Registered'
WHERE id=?
");
$stmtUpdate->bind_param(
"isssiisissiisssssssssssssiiiisssdsddsiiisddidi",
$patient_id,
$appointment_code,
$opd_no,
$ipd_no,
$new_token,
$new_seq,
$visit_type,
$is_emergency,
$type,
$consultation_mode,
$department_id,
$doctor_id,
$appointment_date,
$appointment_time,
$title,
$first_name,
$last_name,
$sex,
$marital,
$guardian,
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
$referral_type,
$internal_referral_id,
$external_referral_id,
$empanelment_id,
$discount_type,
$discount_percent,
$discount_amount,
$camp,
$net_payable,
$current_appointment_id
);
if(!$stmtUpdate->execute()){
    throw new Exception($stmtUpdate->error);
}
$stmtUpdate->close();
$appointment_id = $current_appointment_id;
}
else{

    $stmtInsert = $conn->prepare("
INSERT INTO appointments(
patient_id,
visit_type,
is_emergency,
appointment_code,
token_no,
appointment_seq,
type,
consultation_mode,
department_id,
entity_id,
appointment_date,
appointment_time,
title,
first_name,
last_name,
sex,
marital_status,
guardian_name,
aadhaar,
mobile,
phone,
email,
dob,
age_y,
age_m,
age_d,
city_id,
pincode,
address,
remarks,
consultation_fee,
payment_mode,
paid_amount,
balance_amount,
created_by,
status,
referral_type,
internal_referral_id,
external_referral_id,
empanelment_id,
discount_type,
discount_percent,
discount_amount,
camp,
net_payable,
opd_no,
ipd_no
)
VALUES(
?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,
?,?,?,?,?,?,?,?,?,?,?,?,?,?
)
");
if ($stmtInsert === false) {
    die("INSERT Prepare Error : ".$conn->error);
}
$created_by = $_SESSION['user_id'] ?? 0;
$status = 'Registered';





$stmtInsert->bind_param(
"isisiissiisssssssssssssiiiisssdsddissiiiiddidss",
$patient_id,
$visit_type,
$is_emergency,
$appointment_code,
$new_token,
$new_seq,
$type,
$consultation_mode,
$department_id,
$doctor_id,
$appointment_date,
$appointment_time,
$title,
$first_name,
$last_name,
$sex,
$marital,
$guardian,
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
$created_by,
$status,
$referral_type,
$internal_referral_id,
$external_referral_id,
$empanelment_id,
$discount_type,
$discount_percent,
$discount_amount,
$camp,
$net_payable,
$opd_no,
$ipd_no
);
if(!$stmtInsert->execute()){
    throw new Exception($stmtInsert->error);
}



$appointment_id = $stmtInsert->insert_id;
$stmtInsert->close();
}
/* ===============================
   BARCODE GENERATION
=============================== */
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/barcode_helper.php';
$deptQry = $conn->prepare("
    SELECT hospital_id, branch_id
    FROM department_master
    WHERE id = ?
    LIMIT 1
");
$deptQry->bind_param("i", $department_id);
$deptQry->execute();
$deptData = $deptQry->get_result()->fetch_assoc();
$hospital_id = $deptData['hospital_id'] ?? 0;
$branch_id   = $deptData['branch_id'] ?? 0;
if($hospital_id > 0){
    $barcodeType = ($type == 'ipd') ? 'IPD' : 'OPD';

$barcode_no = generateBarcode(
    $conn,
    $hospital_id,
    $branch_id,
    0,
    $barcodeType,
    $appointment_id
);
    if($barcode_no){
        $upd = $conn->prepare("
            UPDATE appointments
            SET barcode_no = ?
            WHERE id = ?
        ");
        $upd->bind_param(
            "si",
            $barcode_no,
            $appointment_id
        );
        $upd->execute();
        $upd->close();
    }
}
$deptQry->close();
/* ===============================
   SUCCESS
=============================== */
$conn->commit();
/* ===============================
   PATIENT PORTAL CREDENTIAL EMAIL
=============================== */
if ($new_patient_created === true && is_string($email) && trim($email) !== '') {
    try {
        $emailTpl = require $_SERVER['DOCUMENT_ROOT'] .
            '/communication/email_templates/patient_portal_credentials.php';

        $emailBody = str_replace(
            [
                '{{PATIENT_NAME}}',
                '{{HOSPITAL_ID}}',
                '{{USERNAME}}',
                '{{PASSWORD}}'
            ],
            [
                trim($first_name . ' ' . $last_name),
                $_SESSION['hospital_id'] ?? '',
                $patient_username,
                $patient_plain_password
            ],
            $emailTpl
        );

        $mailSent = sendSMTPMail(
            $email,
            'Patient Portal Login Credentials',
            $emailBody
        );

        if (!$mailSent) {
            error_log(
                'PATIENT PORTAL MAIL FAILED | Email: ' . $email .
                ' | Patient ID: ' . $patient_id
            );
        }
    } catch (Throwable $mailError) {
        error_log(
            'PATIENT PORTAL MAIL ERROR | Email: ' . $email .
            ' | Error: ' . $mailError->getMessage()
        );
    }
}
/* ===============================
   REDIRECT TO SUCCESS PAGE
   =============================== */
?>
<?php if ($abdm_link_warning !== null) { ?>
<script>
alert(<?= json_encode($abdm_link_warning) ?>);
</script>
<?php } ?>
<?php if($type == 'ipd'){ ?>
<script>
window.location.href =
'/modules/ipd/admission.php?appointment_id=<?= $appointment_id ?>';
</script>
<?php } else { ?>
<script>
window.open(
'print_registration_slip.php?uhid=<?= urlencode($uhid) ?>&opd=<?= urlencode($opd_no) ?>&token=<?= urlencode($new_token) ?>',
'_blank'
);
window.location.href =
'registration_success.php?appointment_id=<?= $appointment_id ?>';
</script>
<?php } ?>
<?php
exit;
}
catch (Throwable $e) {
    $conn->rollback();
    $msg = $e->getMessage();
    $isValidation = false;
    $safeMessage = '';
    $validationMessages = [
        'Missing appointment details.' => 'Missing appointment details.',
        'Missing admission details.' => 'Missing admission details.',
        'Patient name and mobile required.' => 'Patient name and mobile required.',
        'Invalid revisit patient.' => 'Invalid revisit patient.',
        'This slot is already booked.' => 'This slot is already booked.',
        'Appointment payment record not found.' => 'Appointment payment record not found.',
    ];
    foreach ($validationMessages as $vm => $safe) {
        if (strpos($msg, $vm) !== false) {
            $isValidation = true;
            $safeMessage = $safe;
            break;
        }
    }
    if ($isValidation) {
        http_response_code(400);
        die($safeMessage);
    }

    /*
     * IDENTITY CONFLICTS
     * -------------------
     * These are ordinary registration conflicts, not server faults. Returning
     * 500 for them told the operator nothing and hid the real cause. Each is
     * now answered with 409 and a plain, safe, operator-readable message that
     * contains no patient identifier.
     *
     * The raw database message is still written to the error log for the
     * operator and the support trail; only the HTTP body is sanitised.
     */
    $conflictMessages = [
        'REGCONFLICT_MOBILE_ONLY_MATCH' => 'This mobile number already belongs to an existing patient, but the verified Aadhaar does not match that patient. The record was not saved. Register the existing patient as a revisit, or correct the mobile number.',
        'REGCONFLICT_MOBILE_MISMATCH' => 'The Aadhaar matches an existing patient whose registered mobile number is different. The record was not saved. Use a revisit registration, or correct the Aadhaar.',
        'REGCONFLICT_TWO_PATIENTS' => 'This Aadhaar and this mobile number belong to two different existing patients. The record was not saved. Register as a revisit or correct the details.',
        'REGCONFLICT_DUPLICATE_USERNAME' => 'This mobile number is already used as a portal login for another patient. The record was not saved. Register as a revisit, or correct the mobile number.',
    ];
    foreach ($conflictMessages as $code => $safe) {
        if (strpos($msg, $code) !== false) {
            error_log('REGISTRATION CONFLICT | ' . $code . ' | ' . $msg);
            http_response_code(409);
            die($safe);
        }
    }

    /*
     * A duplicate-key rejection that still reaches here means a uniqueness
     * constraint was violated by a path this handler did not pre-check. That
     * is a real defect in the pre-check, not a transient fault, so it is
     * answered with 409 and logged distinctly rather than as a 500.
     */
    if (stripos($msg, 'Duplicate entry') !== false
        && stripos($msg, "'mobile'") !== false) {
        error_log('REGISTRATION CONFLICT | UNCHECKED_DUPLICATE_MOBILE | ' . $msg);
        http_response_code(409);
        die($conflictMessages['REGCONFLICT_MOBILE_ONLY_MATCH']);
    }

    error_log('REGISTRATION SAVE FAILED | ' . $msg);
    http_response_code(500);
    die("Internal server error.");
}
?>