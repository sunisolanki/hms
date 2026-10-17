<?php
/*
 * Duplicate-patient check by Aadhaar.
 *
 * Used by modules/registration/register.php on the Aadhaar field's blur event to
 * detect that an Aadhaar already belongs to a registered patient, so the page
 * can switch to revisit mode and pre-fill the existing demographics.
 *
 * SECURITY CHANGES (Phase 3 / M1 remediation)
 * -------------------------------------------
 * Previously this endpoint required includes/public_tenant.php, which sets
 * $_SESSION['tenant_id'] from a caller-supplied GET or POST parameter
 * (public_tenant.php:33). An unauthenticated caller could therefore supply any
 * tenant id, satisfy the includes/db.php tenant gate, and have the whole
 * patient_master row returned as JSON - including the password hash and
 * username - by guessing or enumerating an Aadhaar number.
 *
 * Now:
 *   - POST only; the Aadhaar travels in the request body, never in a URL, so it
 *     no longer lands in Apache access logs or browser history.
 *   - an authenticated staff session is required;
 *   - the patient_registration 'view' permission is required, matching the gate
 *     register.php already applies to the page that calls this;
 *   - a session-bound CSRF token is required;
 *   - the tenant is resolved from the session, exactly as includes/db.php does
 *     for every authenticated page. includes/public_tenant.php is deliberately
 *     NOT used here: it exists for unauthenticated public lookups and would
 *     re-introduce caller-controlled tenant selection. It is left untouched
 *     because twenty other endpoints depend on it.
 *   - only the columns the registration auto-fill actually consumes are
 *     selected and returned.
 *
 * RESPONSE CONTRACT (unchanged shape)
 *   { "found": true|false, "patient": { ... } }
 * On success 'patient' contains exactly: id, title, first_name, last_name, sex,
 * guardian_name, mobile, phone, email, dob, city_id, pincode, address.
 */

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/permissions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function aadhaarCheckRefuse($httpCode, $message)
{
    http_response_code($httpCode);
    echo json_encode(['found' => false, 'message' => $message]);
    exit;
}

/* 1. Method */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    aadhaarCheckRefuse(405, 'Invalid request method.');
}

/* 2. Authenticated session */
if (empty($_SESSION['user_id'])) {
    aadhaarCheckRefuse(401, 'Authentication required.');
}

/* 3. Permission - read operation, same permission register.php itself requires */
if (!checkPermission('patient_registration', 'view')) {
    aadhaarCheckRefuse(403, 'Access denied.');
}

/* 4. CSRF */
if (
    empty($_SESSION['csrf'])
    || !isset($_POST['csrf'])
    || !is_string($_POST['csrf'])
    || !hash_equals($_SESSION['csrf'], $_POST['csrf'])
) {
    aadhaarCheckRefuse(403, 'Security token missing or invalid.');
}

/* Tenant + connection come from the session via includes/db.php.
   Caller-supplied tenant_id is deliberately ignored. */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';

if (!($conn instanceof mysqli)) {
    aadhaarCheckRefuse(403, 'Tenant context unavailable.');
}

/* 5. Input type and shape */
if (!isset($_POST['aadhaar']) || !is_string($_POST['aadhaar'])) {
    aadhaarCheckRefuse(400, 'Invalid input.');
}

$aadhaar = trim($_POST['aadhaar']);

if ($aadhaar === '') {
    echo json_encode(['found' => false]);
    exit;
}

/* patient_master.aadhaar is varchar(20); reject anything that cannot match */
if (strlen($aadhaar) > 20) {
    aadhaarCheckRefuse(400, 'Invalid input.');
}

/* 6. Explicit column list - never SELECT * */
$stmt = $conn->prepare("
    SELECT
        id,
        title,
        first_name,
        last_name,
        sex,
        guardian_name,
        mobile,
        phone,
        email,
        dob,
        city_id,
        pincode,
        address
    FROM patient_master
    WHERE aadhaar = ?
    LIMIT 1
");

if (!$stmt) {
    error_log('AADHAAR DUPLICATE CHECK | prepare failed');
    aadhaarCheckRefuse(500, 'Lookup unavailable.');
}

$stmt->bind_param("s", $aadhaar);

if (!$stmt->execute()) {
    $stmt->close();
    error_log('AADHAAR DUPLICATE CHECK | execute failed');
    aadhaarCheckRefuse(500, 'Lookup unavailable.');
}

$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['found' => false]);
    exit;
}

echo json_encode(['found' => true, 'patient' => $row]);