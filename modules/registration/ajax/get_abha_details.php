<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/abdm_helper.php';
header('Content-Type: application/json');
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/permissions.php';

/*
 * SESSION AUTHENTICATION AND PERMISSION
 *
 * This endpoint returned the full patient_master row -- including Aadhaar,
 * ABHA, mobile, phone, email, date of birth and full address -- to any caller
 * who supplied an ABHA id, because the only gate was the Global ABDM switch.
 * The switch is a feature switch, not access control, so while ABDM was ON the
 * endpoint was reachable with no session and no permission, and the distinct
 * "found" / "No patient found" responses made it usable to enumerate ABHA ids.
 *
 * It sits outside the modules/abdm/abha_*.php group, which is why it was not
 * covered by the shared M1 request guard.
 *
 * Now the same two checks the M1 guard applies, in the same order, using the
 * same patient_registration 'view' permission as
 * modules/appointments/ajax/check_patient_by_aadhaar.php. Authentication is
 * tested before the switch so an unauthenticated caller can no longer
 * distinguish ABDM being ON from being OFF.
 *
 * The response shape is unchanged. Nothing about the caller or the workflow
 * that legitimate registration staff use changes: the method stays GET, since
 * modules/registration/register.php calls this endpoint that way.
 */
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'status' => false,
        'message' => 'Authentication required.'
    ]);
    exit;
}

if (!checkPermission('patient_registration', 'view')) {
    http_response_code(403);
    echo json_encode([
        'status' => false,
        'message' => 'Access denied.'
    ]);
    exit;
}

/*
 * GLOBAL ABDM SWITCH
 *
 * This endpoint looks a patient up by ABHA ID and returns that patient's
 * demographics, so it is an ABDM-specific action rather than ordinary patient
 * search. It is refused, in this file's existing status/message JSON shape,
 * before the ABHA parameter is read and before any query is run, whenever the
 * installation has ABDM switched off or the setting cannot be read.
 */
if (!abdmOutboundAllowed($conn)) {
    http_response_code(403);
    echo json_encode([
        'status' => false,
        'message' => abdmGetGlobalSwitch($conn)['state'] === 'error'
            ? 'ABDM is unavailable because the Global ABDM setting could not be read.'
            : 'ABDM is disabled for this installation.',
        'abdm_enabled' => false
    ]);
    exit;
}

$abha = trim($_GET['abha_id'] ?? '');

if ($abha === '') {
    echo json_encode([
        'status' => false,
        'message' => 'Please enter ABHA ID.'
    ]);
    exit;
}

$stmt = $conn->prepare("
    SELECT
        id,
        abha_id,
        title,
        first_name,
        last_name,
        marital_status,
        sex,
        guardian_name,
        aadhaar,
        mobile,
        phone,
        email,
        dob,
        city_id,
        pincode,
        address
    FROM patient_master
    WHERE abha_id = ?
    LIMIT 1
");

if (!$stmt) {
    echo json_encode([
        'status' => false,
        'message' => 'Database query preparation failed.'
    ]);
    exit;
}

$stmt->bind_param("s", $abha);
$stmt->execute();

$result = $stmt->get_result();

if (!$row = $result->fetch_assoc()) {
    echo json_encode([
        'status' => false,
        'message' => 'No patient found with this ABHA ID.'
    ]);
    exit;
}

echo json_encode([
    'status' => true,
    'message' => 'Patient found.',
    'patient' => [
        'id' => $row['id'],
        'abha_id' => $row['abha_id'],
        'title' => $row['title'],
        'first_name' => $row['first_name'],
        'last_name' => $row['last_name'],
        'marital_status' => $row['marital_status'],
        'sex' => $row['sex'],
        'guardian_name' => $row['guardian_name'],
        'aadhaar' => $row['aadhaar'],
        'mobile' => $row['mobile'],
        'phone' => $row['phone'],
        'email' => $row['email'],
        'dob' => $row['dob'],
        'city_id' => $row['city_id'],
        'pincode' => $row['pincode'],
        'address' => $row['address']
    ]
]);

$stmt->close();