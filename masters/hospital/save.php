<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/communication/send_mail.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/communication/sms/send_sms.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/communication/whatsapp/send_whatsapp.php';



ini_set('display_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Invalid request');
}

/* =====================
   COLLECT FORM DATA
===================== */
$id = (int)($_POST['id'] ?? 0);
$isNewHospital = ($id === 0);
$created_by = $_SESSION['user_id'];

$hospital_code = trim($_POST['hospital_code']);
$hospital_name = trim($_POST['hospital_name']);
$director_name = trim($_POST['director_name']);

$country_id  = (int)$_POST['country_id'];
$state_id    = (int)$_POST['state_id'];
$district_id = (int)$_POST['district_id'];
$city_id     = (int)$_POST['city_id'];

$landmark = trim($_POST['landmark']);
$address  = trim($_POST['address']);
$pincode  = trim($_POST['pincode']);

$mobile  = trim($_POST['mobile']);
$phone   = trim($_POST['phone']);
$email   = trim($_POST['email']);
$website = trim($_POST['website']);

$pan_no   = trim($_POST['pan_no']);
$tin_no   = trim($_POST['tin_no']);
$tin_date = !empty($_POST['tin_date']) ? $_POST['tin_date'] : null;
$gstin    = trim($_POST['gstin']);
$dl_no    = trim($_POST['dl_no']);

$detail_1 = trim($_POST['detail_1']);
$detail_2 = trim($_POST['detail_2']);
$detail_3 = trim($_POST['detail_3']);
$detail_4 = trim($_POST['detail_4']);

if ($hospital_code === '' || $hospital_name === '') {
    die('Hospital Code and Hospital Name are required');
}

/* =====================
   FETCH LOCATION NAMES
===================== */
function fetchName($conn, $table, $id) {
    if ($id <= 0) return '';
    $res = mysqli_query($conn, "SELECT name FROM `$table` WHERE id = $id");
    $row = mysqli_fetch_assoc($res);
    return $row ? $row['name'] : '';
}

$country  = fetchName($conn, 'countries', $country_id);
$state    = fetchName($conn, 'states', $state_id);
$district = fetchName($conn, 'districts', $district_id);
$city     = fetchName($conn, 'cities', $city_id);

/* =====================
   FILE UPLOAD
===================== */
$baseDir = __DIR__ . '/../../uploads/hospitals/' . $hospital_code . '/';
if (!is_dir($baseDir)) {
    mkdir($baseDir, 0755, true);
}

function uploadImage($key, $dir, $old = '')
{
    if (!isset($_FILES[$key]) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) {
        return $old;
    }
    $ext = strtolower(pathinfo($_FILES[$key]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
        return $old;
    }
    $name = $key . '.' . $ext;
    move_uploaded_file($_FILES[$key]['tmp_name'], $dir . $name);
    return 'uploads/hospitals/' . basename($dir) . '/' . $name;
}
$oldLogo = $oldSecondary = '';
if ($id > 0) {
    $q = mysqli_query($conn, "SELECT logo, secondary_logo FROM hospital_master WHERE id=$id");
    if ($r = mysqli_fetch_assoc($q)) {
        $oldLogo = $r['logo'];
        $oldSecondary = $r['secondary_logo'];
    }
}
$logo = uploadImage('logo', $baseDir, $oldLogo);
$secondary_logo = uploadImage('secondary_logo', $baseDir, $oldSecondary);
/* =====================
   SAVE TO DATABASE
===================== */
if ($id > 0) {
    $oldDataQry = mysqli_query(
    $conn,
    "SELECT * FROM hospital_master WHERE id = $id"
);
$oldData = mysqli_fetch_assoc($oldDataQry);
    // UPDATE
    $sql = "UPDATE hospital_master SET
        hospital_name   = '".mysqli_real_escape_string($conn,$hospital_name)."',
        director_name   = '".mysqli_real_escape_string($conn,$director_name)."',
        country         = '".mysqli_real_escape_string($conn,$country)."',
        state           = '".mysqli_real_escape_string($conn,$state)."',
        district        = '".mysqli_real_escape_string($conn,$district)."',
        city            = '".mysqli_real_escape_string($conn,$city)."',
        landmark        = '".mysqli_real_escape_string($conn,$landmark)."',
        address         = '".mysqli_real_escape_string($conn,$address)."',
        pincode         = '".mysqli_real_escape_string($conn,$pincode)."',
        mobile          = '".mysqli_real_escape_string($conn,$mobile)."',
        phone           = '".mysqli_real_escape_string($conn,$phone)."',
        email           = '".mysqli_real_escape_string($conn,$email)."',
        website         = '".mysqli_real_escape_string($conn,$website)."',
        pan_no          = '".mysqli_real_escape_string($conn,$pan_no)."',
        tin_no          = '".mysqli_real_escape_string($conn,$tin_no)."',
        tin_date        = ".($tin_date ? "'$tin_date'" : "NULL").",
        gstin           = '".mysqli_real_escape_string($conn,$gstin)."',
        dl_no           = '".mysqli_real_escape_string($conn,$dl_no)."',
        detail_1        = '".mysqli_real_escape_string($conn,$detail_1)."',
        detail_2        = '".mysqli_real_escape_string($conn,$detail_2)."',
        detail_3        = '".mysqli_real_escape_string($conn,$detail_3)."',
        detail_4        = '".mysqli_real_escape_string($conn,$detail_4)."',
        logo            = '".mysqli_real_escape_string($conn,$logo)."',
        secondary_logo  = '".mysqli_real_escape_string($conn,$secondary_logo)."'
        WHERE id = $id";
    mysqli_query($conn, $sql);
   $oldValues = [];
$newValues = [];

$fields = [
    'hospital_name'  => $hospital_name,
    'director_name'  => $director_name,
    'country'        => $country,
    'state'          => $state,
    'district'       => $district,
    'city'           => $city,
    'landmark'       => $landmark,
    'address'        => $address,
    'pincode'        => $pincode,
    'mobile'         => $mobile,
    'phone'          => $phone,
    'email'          => $email,
    'website'        => $website,
    'pan_no'         => $pan_no,
    'tin_no'         => $tin_no,
    'tin_date'       => $tin_date,
    'gstin'          => $gstin,
    'dl_no'          => $dl_no,
    'detail_1'       => $detail_1,
    'detail_2'       => $detail_2,
    'detail_3'       => $detail_3,
    'detail_4'       => $detail_4,
    'logo'           => $logo,
    'secondary_logo' => $secondary_logo
];

foreach($fields as $field => $newValue){

    $oldValue = $oldData[$field] ?? '';

    if((string)$oldValue !== (string)$newValue){

        $oldValues[$field] = $oldValue;
        $newValues[$field] = $newValue;
    }
}

addAuditLog(
    'Hospital Master',
    'UPDATE',
    'Hospital Updated : '.$hospital_name,
    $id,
    json_encode($oldValues),
    json_encode($newValues)
);
} else {

    // INSERT
    $sql = "INSERT INTO hospital_master SET
        hospital_code   = '".mysqli_real_escape_string($conn,$hospital_code)."',
        hospital_name   = '".mysqli_real_escape_string($conn,$hospital_name)."',
        director_name   = '".mysqli_real_escape_string($conn,$director_name)."',
        country         = '".mysqli_real_escape_string($conn,$country)."',
        state           = '".mysqli_real_escape_string($conn,$state)."',
        district        = '".mysqli_real_escape_string($conn,$district)."',
        city            = '".mysqli_real_escape_string($conn,$city)."',
        landmark        = '".mysqli_real_escape_string($conn,$landmark)."',
        address         = '".mysqli_real_escape_string($conn,$address)."',
        pincode         = '".mysqli_real_escape_string($conn,$pincode)."',
        mobile          = '".mysqli_real_escape_string($conn,$mobile)."',
        phone           = '".mysqli_real_escape_string($conn,$phone)."',
        email           = '".mysqli_real_escape_string($conn,$email)."',
        website         = '".mysqli_real_escape_string($conn,$website)."',
        pan_no          = '".mysqli_real_escape_string($conn,$pan_no)."',
        tin_no          = '".mysqli_real_escape_string($conn,$tin_no)."',
        tin_date        = ".($tin_date ? "'$tin_date'" : "NULL").",
        gstin           = '".mysqli_real_escape_string($conn,$gstin)."',
        dl_no           = '".mysqli_real_escape_string($conn,$dl_no)."',
        detail_1        = '".mysqli_real_escape_string($conn,$detail_1)."',
        detail_2        = '".mysqli_real_escape_string($conn,$detail_2)."',
        detail_3        = '".mysqli_real_escape_string($conn,$detail_3)."',
        detail_4        = '".mysqli_real_escape_string($conn,$detail_4)."',
        logo            = '".mysqli_real_escape_string($conn,$logo)."',
        secondary_logo  = '".mysqli_real_escape_string($conn,$secondary_logo)."',
        created_by      = '$created_by',
        status          = 1";
    if (mysqli_query($conn, $sql)) {
        $hospitalId = mysqli_insert_id($conn);
addAuditLog(
    'Hospital Master',
    'CREATE',
    'Hospital Created : '.$hospital_name,
    $hospitalId,
    '',
    json_encode([
        'hospital_code' => $hospital_code,
        'hospital_name' => $hospital_name,
        'city' => $city,
        'mobile' => $mobile,
        'email' => $email
    ])
);
        // =====================
// SEND SMS
// =====================
if ($isNewHospital && !empty($mobile)) {

    $smsTemplatePath = $_SERVER['DOCUMENT_ROOT']
        . '/communication/sms/sms_templates/hospital_welcome.php';

    if (file_exists($smsTemplatePath)) {
        $smsMessage = require $smsTemplatePath;
        sendSMS($mobile, $smsMessage);
    }
}

// =====================
// SEND WHATSAPP
// =====================
if ($isNewHospital && !empty($mobile)) {

    $waTemplatePath = $_SERVER['DOCUMENT_ROOT']
        . '/communication/whatsapp/whatsapp_templates/hospital_welcome.php';

    if (file_exists($waTemplatePath)) {
        $waMessage = require $waTemplatePath;
        sendWhatsApp($mobile, $waMessage);
    }
}
// =====================
// SEND Email
// =====================


    if ($isNewHospital && !empty($email)) {

        $templatePath = $_SERVER['DOCUMENT_ROOT']
            . '/communication/email_templates/hospital_welcome.php';

        if (file_exists($templatePath)) {
            $emailData = require $templatePath;

            sendDynamicMail(
                $email,
                $emailData['subject'],
                $emailData['message']
           );
            }
        }
    }
}



header("Location: hospital_list.php");
exit;
