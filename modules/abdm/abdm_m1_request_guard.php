<?php
/*
 * ABDM M1 REQUEST GUARD
 * ---------------------
 * Shared request guard for every M1 ABDM endpoint (modules/abdm/abha_*.php).
 *
 * Why this exists: the M1 endpoints previously performed no session, permission
 * or CSRF validation. The only gate was the Global ABDM switch, which is a
 * feature switch and not an access control. Any logged-in staff session of any
 * role could therefore trigger outbound ABDM gateway operations (OTP requests,
 * ABHA verification, profile fetches) to attacker-supplied identifiers.
 *
 * This guard runs BEFORE any gateway interaction. Nothing in an endpoint that
 * requires this file can reach abdmGetAccessToken(), a certificate fetch, an OTP
 * request, an ABHA verification, or a profile fetch until all four checks pass.
 *
 * Order is deliberate and matches the convention already established in
 * modules/registration/save_patient_registration.php (method -> permission ->
 * CSRF -> work):
 *
 *   1. HTTP method must be POST.
 *   2. An authenticated staff session must exist.
 *   3. The session must hold the patient_registration 'create' permission --
 *      the same permission the registration save handler itself requires, and
 *      the narrowest existing permission that covers an outbound identity
 *      operation performed while completing a registration. No new permission
 *      model is introduced.
 *   4. A session-bound CSRF token must be present and must be a string.
 *
 * Responses use the same {success, message} JSON shape the M1 endpoints already
 * return, so existing callers keep working.
 */

if (!defined('ABDM_M1_GUARD_LOADED')) {
    define('ABDM_M1_GUARD_LOADED', true);

    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/permissions.php';

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    /**
     * Emit a refusal and stop. Never discloses a reason that would help a
     * caller distinguish "no session" from "wrong permission".
     */
    function abdmM1GuardRefuse($httpCode, $message)
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => $message,
            'abdm_state' => 'refused'
        ]);
        exit;
    }

    /* 1. Method */
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        abdmM1GuardRefuse(405, 'Invalid request method.');
    }

    /* 2. Authenticated session */
    if (empty($_SESSION['user_id'])) {
        abdmM1GuardRefuse(401, 'Authentication required.');
    }

    /* 3. Permission */
    if (!checkPermission('patient_registration', 'create')) {
        abdmM1GuardRefuse(403, 'Access denied.');
    }

    /* 4. CSRF */
    if (
        empty($_SESSION['csrf'])
        || !isset($_POST['csrf'])
        || !is_string($_POST['csrf'])
        || !hash_equals($_SESSION['csrf'], $_POST['csrf'])
    ) {
        abdmM1GuardRefuse(403, 'Security token missing or invalid.');
    }
}