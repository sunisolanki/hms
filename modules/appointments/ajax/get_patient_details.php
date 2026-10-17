<?php
/*
 * PATIENT DETAILS LOOKUP (existing-patient selection)
 *
 * Returns the demographics of one patient for the existing-patient pickers in
 * modules/registration/register.php and
 * modules/appointments/appointment_form_internal.php.
 *
 * WHY THIS FILE WAS HARDENED
 * -------------------------
 * It previously imported includes/public_tenant.php, which writes a
 * caller-supplied tenant_id straight into $_SESSION['tenant_id']
 * (public_tenant.php:33). It performed no session check and no permission
 * check. Any unauthenticated caller could therefore pass any tenant id, satisfy
 * the includes/db.php tenant gate, and receive 14 fields of patient PII -
 * aadhaar, mobile, phone, email, date of birth and full address - for any
 * sequential patient id.
 *
 * This is the same defect class as the two endpoints already fixed in the M1
 * closure (modules/registration/ajax/get_abha_details.php and
 * get_patient_full_details.php), which sit outside the modules/abdm/abha_*.php
 * group the original M1 guard covered.
 *
 * WHAT CHANGED, AND WHAT DID NOT
 * ------------------------------
 * Added: an authenticated staff session, the patient_registration 'view'
 * permission, and tenant resolution from the session only.
 *
 * includes/public_tenant.php is NO LONGER imported here, so a caller-supplied
 * tenant_id is ignored and the tenant comes from $_SESSION['tenant_id'] via
 * includes/db.php. That file itself is untouched - it still has 23 other
 * callers.
 *
 * The permission is patient_registration 'view', the narrowest existing
 * permission covering a patient read. It is the same permission
 * register.php requires and the same permission the sibling endpoint
 * modules/appointments/ajax/check_patient_by_aadhaar.php requires for an
 * identical patient-PII read. No new permission name is introduced.
 *
 * includes/db.php is loaded BEFORE the permission check on purpose:
 * checkPermission() resolves can_view through the global $conn, so it must be
 * able to run its lookup for non-super-admin roles.
 *
 * NO CSRF TOKEN IS REQUIRED. This endpoint performs a single SELECT and writes
 * nothing, so it is a read-only request and cannot itself cause a state change.
 * That follows the project convention for authenticated read-only AJAX - see
 * modules/registration/ajax/get_abha_details.php and
 * get_patient_full_details.php. A token requirement was deliberately NOT added
 * because neither caller sends one, and adding one would break both without
 * buying any protection against a read-only endpoint.
 *
 * ABHA SOURCE OF TRUTH. The SELECT is the original 14 columns plus
 * patient_master.abha_id, which the registration existing-patient picker
 * consumes. abha_id is read from patient_master because that is the established
 * source of truth in this codebase: every UI and display path reads
 * patient_master.abha_id, whereas abdm_patient_links.abha_number is read only
 * by the ABDM gateway endpoints and carries link metadata
 * (verification_status, verified_at, linking_token). The two tables CAN
 * diverge, because modules/registration/update_patient.php writes
 * patient_master.abha_id without synchronising abdm_patient_links - its own
 * comment at update_patient.php:131 records that risk. abdm_patient_links
 * can therefore be stale and must not be used as the display source.
 *
 * The 14 original fields, their names and their order are unchanged; the bare
 * JSON object simply carries one additional key.
 *
 * The HTTP method stays GET and the success payload is unchanged: a bare JSON
 * object of the selected columns, or [] when there is no match. Refusals use
 * the {success:false,message:...} envelope that this file already returned via
 * public_tenant.php, so existing callers handle it unchanged. Refusal messages
 * are generic and never disclose patient data, SQL, credentials or stack traces.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/permissions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Emit a refusal and stop. The reason is deliberately coarse so a caller
 * cannot use it to probe for valid sessions, roles or tenants.
 */
function patientDetailsRefuse($httpCode, $message)
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => $message
    ]);
    exit;
}

/* 1. Authenticated staff session */
if (empty($_SESSION['user_id'])) {
    patientDetailsRefuse(401, 'Authentication required.');
}

/* 2. Tenant + connection come from the session via includes/db.php only.
      A caller-supplied tenant_id is deliberately ignored. */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';

if (!($conn instanceof mysqli)) {
    patientDetailsRefuse(403, 'Tenant context unavailable.');
}

/* 3. Permission - read operation, same permission register.php itself
      requires and the same permission check_patient_by_aadhaar.php requires
      for an identical patient read. */
if (!checkPermission('patient_registration', 'view')) {
    patientDetailsRefuse(403, 'Access denied.');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode([]);
    exit;
}

$stmt = $conn->prepare("
    SELECT
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
        address,
        abha_id
    FROM patient_master
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();

echo json_encode($res->fetch_assoc() ?: []);