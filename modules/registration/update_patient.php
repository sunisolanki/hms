<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if (!checkPermission('patient_registration', 'update')) {
    header('Location: /access_denied.php');
    exit;
}
if (empty($_SESSION['csrf']) || !isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    header('Location: /access_denied.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);

if($id <= 0){
    die("Invalid Patient ID");
}

/* =========================
   FETCH POST DATA
========================= */

$abha_id              = trim($_POST['abha_id'] ?? '');
$title                = trim($_POST['title'] ?? '');
$first_name           = trim($_POST['first_name'] ?? '');
$last_name            = trim($_POST['last_name'] ?? '');
$sex                  = trim($_POST['sex'] ?? '');
$marital_status       = trim($_POST['marital_status'] ?? '');
$guardian_name        = trim($_POST['guardian_name'] ?? '');
$aadhaar              = trim($_POST['aadhaar'] ?? '');
$mobile               = trim($_POST['mobile'] ?? '');
$phone                = trim($_POST['phone'] ?? '');
$email                = trim($_POST['email'] ?? '');
$dob                  = trim($_POST['dob'] ?? '');
$age_y                = (int)($_POST['age_y'] ?? 0);
$age_m                = (int)($_POST['age_m'] ?? 0);
$age_d                = (int)($_POST['age_d'] ?? 0);
$city_id              = (int)($_POST['city_id'] ?? 0);
$pincode              = trim($_POST['pincode'] ?? '');
$address              = trim($_POST['address'] ?? '');

/* =========================
   VALIDATION
========================= */

if($first_name == ''){
    http_response_code(400);
    die("First Name is required");
}

if($mobile == ''){
    http_response_code(400);
    die("Mobile Number is required");
}

if(!preg_match('/^[0-9]{10}$/', $mobile)){
    http_response_code(400);
    die("Mobile Number must be exactly 10 digits");
}

if($phone != '' && !preg_match('/^[0-9]{10}$/', $phone)){
    http_response_code(400);
    die("Phone Number must be exactly 10 digits");
}

if($aadhaar != '' && !preg_match('/^[0-9]{12}$/', $aadhaar)){
    http_response_code(400);
    die("Aadhaar Number must be exactly 12 digits");
}

if($email != '' && !filter_var($email, FILTER_VALIDATE_EMAIL)){
    http_response_code(400);
    die("Invalid Email Address");
}

if($city_id <= 0){
    http_response_code(400);
    die("City is required");
}

/* DOB OR AGE REQUIRED */

if(
    empty($dob)
    &&
    $age_y == 0
    &&
    $age_m == 0
    &&
    $age_d == 0
){
    http_response_code(400);
    die("DOB or Age is required");
}

/* =========================
   MOBILE DUPLICATE CHECK
========================= */

$stmt = $conn->prepare("
    SELECT id
    FROM patient_master
    WHERE mobile=?
    AND id<>?
    LIMIT 1
");

$stmt->bind_param("si",$mobile,$id);
$stmt->execute();
$stmt->store_result();

if($stmt->num_rows > 0){
    http_response_code(400);
    die("Mobile Number already exists");
}

$stmt->close();

/* =========================
   ABHA DATA INTEGRITY
   =========================

   This form has no explicit "unlink ABHA" action. A blank or missing
   abha_id therefore means "not supplied in this submission", never
   "remove the existing ABHA".

   Previously the blank value was written straight through, which allowed
   an unrelated profile edit (name, address, phone, ...) to erase
   patient_master.abha_id while any abdm_patient_links row survived -
   leaving the two tables inconsistent.

   A non-blank abha_id is still honoured exactly as before, so the
   existing validation and linking workflow is unchanged. No unlink path
   is introduced. */

if($abha_id === ''){

    $existingAbhaStmt = $conn->prepare("
        SELECT abha_id
        FROM patient_master
        WHERE id=?
        LIMIT 1
    ");

    $existingAbhaStmt->bind_param("i",$id);
    $existingAbhaStmt->execute();
    $existingAbhaRow = $existingAbhaStmt->get_result()->fetch_assoc();
    $existingAbhaStmt->close();

    if($existingAbhaRow && !empty($existingAbhaRow['abha_id'])){
        $abha_id = $existingAbhaRow['abha_id'];
    }
}

/* =========================
   ABDM ABHA OWNERSHIP
=========================

   save_patient_registration.php already refuses to create an
   abdm_patient_links row when the same ABHA number is already owned by
   another patient. The update path had no equivalent guard, so this form
   could write a second patient's ABHA number into patient_master without
   any ownership check. Mirror the registration guard here: the profile edit
   still proceeds, but the submitted ABHA value is discarded and the stored
   value is preserved.

   No schema change and no new permission slug are required. */

$abdmLinkWarning = null;

if($abha_id !== ''){

    $abdmOwnerStmt = $conn->prepare("
        SELECT patient_id
        FROM abdm_patient_links
        WHERE abha_number = ?
          AND patient_id <> ?
        LIMIT 1
    ");

    if(!$abdmOwnerStmt){
        http_response_code(500);
        die("Unable to verify ABHA ownership.");
    }

    $abdmOwnerStmt->bind_param("si", $abha_id, $id);
    $abdmOwnerStmt->execute();
    $abdmOwnerRow = $abdmOwnerStmt->get_result()->fetch_assoc();
    $abdmOwnerStmt->close();

    if($abdmOwnerRow){

        $storedAbhaStmt = $conn->prepare("
            SELECT abha_id
            FROM patient_master
            WHERE id=?
            LIMIT 1
        ");

        if(!$storedAbhaStmt){
            http_response_code(500);
            die("Unable to verify stored ABHA value.");
        }

        $storedAbhaStmt->bind_param("i", $id);
        $storedAbhaStmt->execute();
        $storedAbhaRow = $storedAbhaStmt->get_result()->fetch_assoc();
        $storedAbhaStmt->close();

        $abha_id = trim((string)($storedAbhaRow['abha_id'] ?? ''));

        $abdmLinkWarning =
            'Patient updated, but the submitted ABHA number is already '
            . 'linked to another patient and was not applied.';

        error_log(
            'ABDM LINK REFUSED ON UPDATE - ABHA already linked to another '
            . 'patient | Patient ID: ' . $id
        );
    }
}

/* =========================
   UPDATE PATIENT
========================= */

$stmt = $conn->prepare("
    UPDATE patient_master SET

        abha_id=?,
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
        address=?

    WHERE id=?
");

$stmt->bind_param(
    "ssssssssssssiiiissi",

    $abha_id,
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
    $id
);

if($stmt->execute()){

    $_SESSION['success_message'] = $abdmLinkWarning !== null
        ? $abdmLinkWarning
        : "Patient updated successfully.";

   echo "<script>
window.parent.location.href='registration_list.php';
</script>";
exit;
}
else{

    error_log('UPDATE PATIENT FAILED | ' . $stmt->error);
    http_response_code(500);
    die("Database error.");
}

$stmt->close();
?>