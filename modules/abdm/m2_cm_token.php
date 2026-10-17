<?php
/**
 * ABDM M2 - Consent Manager linking token storage (v0.5 link API).
 *
 * Purpose
 * -------
 * Holds the credential that authorises the v0.5 HIP-initiated linking call
 * POST {ABDM_BASE_URL}/gateway/v0.5/links/link/add-contexts.
 *
 * ABDM documents that this credential is the "linking token", issued by the
 * Consent Manager to the HIP through the callback
 * POST {ABDM_BASE_URL}/gateway/v0.5/users/auth/on-confirm.
 *
 * It is NOT the same credential as either of the two tokens already present in
 * this codebase, which is why it is stored separately:
 *
 *   1. The M1 ABHA account token (the X-token consumed transiently by
 *      modules/abdm/abha_verify_mobile_otp.php:478-514 to read the profile).
 *      That is issued to the ABHA client app for ABHA-account purposes. It is
 *      never received by this server for a patient the way a linking token is,
 *      it has no LINK purpose, and it is not accepted by add-contexts.
 *
 *   2. The HiECM linking token in abdm_patient_links.linking_token, produced by
 *      /hiecm/hip/v3/token/generate-token and consumed by
 *      modules/abdm/m2_care_context.php:223 as the X-LINK-TOKEN header of the
 *      v3 link/carecontext call. Different API family, different transport
 *      position. Overwriting it would break the existing path, so it is left
 *      untouched.
 *
 * Architecture constraint: procedural PHP, mysqli only, no framework, no ORM.
 *
 * Why this file requires nothing
 * ------------------------------
 * This module is loaded from BOTH entry points:
 *
 *   * modules/opd/save_prescription.php, which runs inside a staff session, and
 *   * api/v0.5/users/auth/on-confirm/index.php, which is a PUBLIC ABDM callback
 *     with no session at all.
 *
 * It therefore must not require m2_auth.php or m2_config.php. Both pull in
 * includes/db.php, which sets the global $conn to null when no session exists -
 * the exact trap that forced the reconnect in
 * api/v3/hip/patient/care-context/discover/index.php:279-298. This file takes
 * $conn as an explicit parameter on every function instead, so it cannot clobber
 * the connection of whichever caller loaded it.
 *
 * The same reasoning is recorded in modules/abdm/m2_jwt_verifier.php:731-734,
 * which deliberately declines to require m2_auth.php for the same reason.
 *
 * Logging discipline
 * ------------------
 * A linking token authorises the linking of care contexts. It is never written
 * to the PHP error log, never returned to a caller as part of an error payload,
 * and never echoed to an HTTP response body. Only fixed enum labels and
 * booleans are logged, matching the P0 redaction pattern already applied across
 * the api/v3 callbacks.
 */

defined('ABDM_M2_CM_TOKEN_LOADED') || define('ABDM_M2_CM_TOKEN_LOADED', true);

/**
 * Purposes ABDM supports for user authorisation.
 *
 * LINK           - for adding care-contexts. The only purpose that yields a
 *                  linking token.
 * KYC            - for basic user details. No linking token is issued.
 * KYC_AND_LINK   - for user details plus linking.
 *
 * @var string[]
 */
if (!defined('ABDM_M2_CM_PURPOSES')) {
    define('ABDM_M2_CM_PURPOSES', ['LINK', 'KYC', 'KYC_AND_LINK']);
}

/**
 * Auth modes ABDM supports for /users/auth/init.
 *
 * DIRECT   - patient approves a pending request in the app. ABDM issues a
 *            PERPETUAL access token, which is the only kind that is still valid
 *            at prescription-save time.
 * MEDIATED - OTP / Aadhaar OTP / demographics entered in the ABHA app. The
 *            resulting token is short-lived.
 *
 * @var string[]
 */
if (!defined('ABDM_M2_CM_AUTH_MODES')) {
    define('ABDM_M2_CM_AUTH_MODES', ['DIRECT', 'MEDIATED']);
}

/**
 * Statuses for abdm_auth_link_requests.
 *
 * @var string[]
 */
if (!defined('ABDM_M2_CM_LINK_STATUSES')) {
    define('ABDM_M2_CM_LINK_STATUSES', ['Pending', 'Confirmed', 'Failed', 'Expired']);
}

/**
 * Normalise a purpose string against the fixed allowlist.
 *
 * An unrecognised value returns null rather than being passed through, so a
 * typo can never be stored and later read back as though it were meaningful.
 *
 * @param mixed $value
 * @return string|null
 */
function abdmM2NormalizeCmPurpose($value)
{
    $normalized = strtoupper(trim((string)$value));

    if (!in_array($normalized, ABDM_M2_CM_PURPOSES, true)) {
        return null;
    }

    return $normalized;
}

/**
 * Normalise an auth-mode string against the fixed allowlist.
 *
 * @param mixed $value
 * @return string|null Null when empty or unrecognised.
 */
function abdmM2NormalizeCmAuthMode($value)
{
    $normalized = strtoupper(trim((string)$value));

    if ($normalized === '') {
        return null;
    }

    return in_array($normalized, ABDM_M2_CM_AUTH_MODES, true) ? $normalized : null;
}

/**
 * Normalise a link-request status against the fixed allowlist.
 *
 * @param mixed $value
 * @return string|null
 */
function abdmM2NormalizeCmLinkStatus($value)
{
    $normalized = trim((string)$value);

    if (!in_array($normalized, ABDM_M2_CM_LINK_STATUSES, true)) {
        return null;
    }

    return $normalized;
}

/**
 * Record an outbound linking-authorisation attempt.
 *
 * Written immediately before POST /users/auth/init is issued. The generated
 * requestId is the ONLY dependable correlation key available when the Consent
 * Manager later calls /users/auth/on-confirm, so it must be persisted before the
 * call goes out, not after it returns.
 *
 * @param mysqli  $conn
 * @param string  $requestId    UUID generated by the caller (abdmM2GenerateRequestId()).
 * @param int     $patientId
 * @param string  $purpose      LINK / KYC / KYC_AND_LINK.
 * @param string  $authMode     DIRECT / MEDIATED, or '' when unknown.
 * @param string  $abhaAddress  Patient ABHA address, '' when unknown.
 * @return array{ok:bool, id:int, reason:string}
 */
function abdmM2RecordAuthLinkRequest($conn, $requestId, $patientId, $purpose, $authMode = '', $abhaAddress = '')
{
    $fail = function ($reason) {
        return ['ok' => false, 'id' => 0, 'reason' => $reason];
    };

    if (!($conn instanceof mysqli)) {
        return $fail('no_connection');
    }

    $requestId = trim((string)$requestId);

    if ($requestId === '') {
        return $fail('request_id_required');
    }

    $patientId = (int)$patientId;

    if ($patientId <= 0) {
        return $fail('patient_id_required');
    }

    $purpose = abdmM2NormalizeCmPurpose($purpose);

    if ($purpose === null) {
        return $fail('invalid_purpose');
    }

    /*
     * ONE ROW PER ATTEMPT, NOT PER PATIENT.
     *
     * The unique key here is request_id, so a patient who re-authorises
     * accumulates one row per attempt. That is deliberate: each attempt is a
     * distinct thing the patient may or may not complete, and collapsing them
     * would destroy the record of which request the CM actually answered. The
     * ON DUPLICATE KEY branch therefore only fires if the SAME requestId is
     * replayed, where resetting to 'Pending' is the right repair.
     *
     * auth_mode and abha_address are cast to string before binding because
     * mysqli cannot bind a PHP null against an 's' parameter; NULLIF(?, '') in
     * the SQL converts the empty string back to a real NULL.
     */
    $abhaAddress = trim((string)$abhaAddress);
    $authMode = (string)abdmM2NormalizeCmAuthMode($authMode);
    $status = 'Pending';

    $stmt = $conn->prepare("
        INSERT INTO abdm_auth_link_requests
            (request_id, patient_id, purpose, auth_mode, abha_address, status)
        VALUES
            (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?)
        ON DUPLICATE KEY UPDATE
            patient_id    = VALUES(patient_id),
            purpose       = VALUES(purpose),
            auth_mode     = VALUES(auth_mode),
            abha_address  = VALUES(abha_address),
            status        = 'Pending',
            updated_at    = CURRENT_TIMESTAMP
    ");

    if (!$stmt) {
        return $fail('prepare_failed');
    }

    /*
     * TYPE STRING MUST MIRROR PARAMETER ORDER EXACTLY:
     *   s request_id    i patient_id    s purpose
     *   s auth_mode     s abha_address  s status
     *
     * request_id IS THE 's'. Binding it as 'i' does not raise an error - MySQL
     * silently coerces a non-numeric string to 0, so every row lands with
     * request_id = '0' and every later lookup by the real id finds nothing. That
     * failure is invisible at write time and only shows up as "the token never
     * arrives", so the leading 's' is load-bearing.
     */
    $stmt->bind_param('sissss', $requestId, $patientId, $purpose, $authMode, $abhaAddress, $status);

    try {
        $executed = $stmt->execute();
    } catch (Throwable $e) {
        /*
         * THROWABLE IS CAUGHT RATHER THAN ALLOWED TO ESCAPE.
         *
         * This runs from a public ABDM callback with no session. An uncaught
         * mysqli_sql_exception here would emit a stack trace that can contain
         * the connection string and column values. See the identical guard in
         * m2_auto_link.php:113-117.
         */
        error_log('ABDM CM-LINK | auth request insert threw');
        @$stmt->close();

        return $fail('execute_threw');
    }

    if (!$executed) {
        @$stmt->close();

        return $fail('execute_failed');
    }

    $id = (int)$conn->insert_id;
    $stmt->close();

    error_log(
        'ABDM CM-LINK | auth request recorded | purpose=' . $purpose .
        ' | auth_mode=' . ($authMode !== null ? $authMode : 'unknown') .
        ' | has_abha=' . ($abhaAddress !== '' ? 'YES' : 'NO')
    );

    return ['ok' => true, 'id' => $id, 'reason' => 'recorded'];
}

/**
 * Look up a pending authorisation request by the requestId we generated.
 *
 * @param mysqli $conn
 * @param string $requestId
 * @return array<string,mixed>|null Null when no row matches.
 */
function abdmM2ResolveAuthLinkRequest($conn, $requestId)
{
    if (!($conn instanceof mysqli)) {
        return null;
    }

    $requestId = trim((string)$requestId);

    if ($requestId === '') {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT id, request_id, patient_id, purpose, auth_mode,
               abha_address, status, created_at
        FROM abdm_auth_link_requests
        WHERE request_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $requestId);

    try {
        if (!$stmt->execute()) {
            @$stmt->close();

            return null;
        }
    } catch (Throwable $e) {
        error_log('ABDM CM-LINK | auth request lookup threw');
        @$stmt->close();

        return null;
    }

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return is_array($row) ? $row : null;
}

/**
 * Move a pending authorisation request to a terminal status.
 *
 * Idempotent: re-running a status change does not re-stamp updated_at beyond
 * what the write itself does, and an unknown status is refused rather than
 * written.
 *
 * @param mysqli  $conn
 * @param string  $requestId
 * @param string  $status  Confirmed / Failed / Expired / Pending.
 * @return bool
 */
function abdmM2MarkAuthLinkRequestStatus($conn, $requestId, $status)
{
    if (!($conn instanceof mysqli)) {
        return false;
    }

    $requestId = trim((string)$requestId);

    if ($requestId === '') {
        return false;
    }

    $status = abdmM2NormalizeCmLinkStatus($status);

    if ($status === null) {
        return false;
    }

    $stmt = $conn->prepare("
        UPDATE abdm_auth_link_requests
        SET status     = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE request_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $status, $requestId);

    try {
        $ok = (bool)$stmt->execute();
    } catch (Throwable $e) {
        error_log('ABDM CM-LINK | auth request status update threw');
        $ok = false;
    }

    $stmt->close();

    return $ok;
}

/**
 * Persist the CM linking token against a patient.
 *
 * Parameters are validated before anything is written, and the write is scoped
 * to patient_id only. The token VALUE is never logged.
 *
 * Returns true both for a fresh write and for an idempotent replay that rewrote
 * identical values. See the affected_rows note below.
 *
 * @param mysqli   $conn
 * @param int      $patientId
 * @param string   $linkToken  The linking token from /users/auth/on-confirm.
 * @param string   $purpose    LINK / KYC / KYC_AND_LINK.
 * @param string   $expiresAt  'Y-m-d H:i:s' in UTC, or '' for a perpetual token.
 * @return bool
 */
function abdmM2SaveCmLinkingToken($conn, $patientId, $linkToken, $purpose, $expiresAt = '')
{
    if (!($conn instanceof mysqli)) {
        return false;
    }

    $patientId = (int)$patientId;

    if ($patientId <= 0) {
        return false;
    }

    $linkToken = trim((string)$linkToken);

    if ($linkToken === '') {
        return false;
    }

    $purpose = abdmM2NormalizeCmPurpose($purpose);

    if ($purpose === null) {
        return false;
    }

    /*
     * ABDM ISSUES NO LINKING TOKEN FOR KYC-PURPOSE AUTH.
     *
     * This is a real, documented response shape rather than a defensive check:
     * a KYC request legitimately completes without ever producing a credential
     * that can link. Refusing to store one here keeps the column meaningful, and
     * leaves the previous token in place rather than blanking it.
     */
    if ($purpose !== 'LINK') {
        error_log('ABDM CM-LINK | token not stored | purpose=' . $purpose . ' | no_linking_token_issued');

        return false;
    }

    $expiresAt = trim((string)$expiresAt);

    $stmt = $conn->prepare("
        UPDATE abdm_patient_links
        SET cm_linking_token     = ?,
            cm_token_purpose     = ?,
            cm_token_expires_at  = NULLIF(?, ''),
            cm_token_received_at = CURRENT_TIMESTAMP,
            updated_at           = CURRENT_TIMESTAMP
        WHERE patient_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        error_log('ABDM CM-LINK | token save prepare failed');

        return false;
    }

    $stmt->bind_param('sssi', $linkToken, $purpose, $expiresAt, $patientId);

    try {
        $executed = $stmt->execute();
    } catch (Throwable $e) {
        error_log('ABDM CM-LINK | token save threw');
        @$stmt->close();

        return false;
    }

    if (!$executed) {
        @$stmt->close();

        return false;
    }

    $affected = (int)$conn->affected_rows;
    $stmt->close();

    /*
     * affected_rows === 0 DOES NOT MEAN FAILURE.
     *
     * MySQL reports zero affected rows when an UPDATE writes values identical to
     * those already present. The CM is allowed to retry a callback, and a retry
     * that arrives within the same second as the original writes byte-identical
     * values to all four columns, so affected_rows is 0 for a perfectly successful
     * re-store. Gating success on affected_rows would answer that retry with a
     * 500, which invites the CM to retry again - turning a harmless duplicate into
     * a failure loop.
     *
     * So zero is treated as "unknown" and resolved by reading the row back. The
     * distinguishing question is not "did the write change anything" but "is the
     * token now stored for this patient".
     */
    if ($affected > 0) {
        error_log(
            'ABDM CM-LINK | token stored | expired=' . ($expiresAt !== '' ? 'YES' : 'NO_PERPETUAL') .
            ' | row_updated=YES'
        );

        return true;
    }

    $stored = abdmM2GetCmLinkingToken($conn, $patientId);

    if ($stored !== null && hash_equals($stored, $linkToken)) {
        error_log(
            'ABDM CM-LINK | token already current | expired=' . ($expiresAt !== '' ? 'YES' : 'NO_PERPETUAL') .
            ' | row_updated=NO_IDEMPOTENT_REPLAY'
        );

        return true;
    }

    /*
     * The UPDATE matched no row, or the row is not in the required state. The
     * usual cause is a patient with no abdm_patient_links row at all, which has
     * no ABHA and therefore cannot be linked by any route.
     */
    error_log('ABDM CM-LINK | token not stored | row_updated=NO | probable_cause=no_link_row');

    return false;
}

/**
 * Read a usable CM linking token for a patient.
 *
 * This is the gate for the add-contexts push. It returns a token ONLY when all
 * of the following hold, and null otherwise:
 *
 *   * a cm_linking_token value is present and non-empty;
 *   * its recorded purpose is LINK (a KYC credential cannot link);
 *   * it is not expired, where NULL expiry means perpetual rather than unknown.
 *
 * The expiry comparison is done in SQL rather than in PHP so the decision uses
 * the database server's clock.
 *
 * UTC_TIMESTAMP() IS USED RATHER THAN NOW(), DELIBERATELY.
 *
 * The value in cm_token_expires_at is derived from a JWT `exp` claim, which is
 * defined as UTC seconds since the epoch, so it is written with gmdate(). This
 * installation's MariaDB server runs on SYSTEM time zone, which resolves to
 * +05:30, and every application connection sets time_zone = '+05:30' as well -
 * so NOW() would read five and a half hours AHEAD of the stored value and report
 * every token, including a freshly issued perpetual one, as already expired.
 *
 * UTC_TIMESTAMP() returns UTC unconditionally regardless of session time zone,
 * which is the same frame gmdate() wrote in. That makes the comparison correct
 * under any session time zone. Verified against the live server: with a stored
 * value of 2026-10-17 14:00:19 and an exp one hour in the future, the NOW() gate
 * evaluates false and the UTC_TIMESTAMP() gate evaluates true.
 *
 * @param mysqli $conn
 * @param int    $patientId
 * @return string|null
 */
function abdmM2GetCmLinkingToken($conn, $patientId)
{
    if (!($conn instanceof mysqli)) {
        return null;
    }

    $patientId = (int)$patientId;

    if ($patientId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT cm_linking_token, cm_token_purpose, cm_token_expires_at
        FROM abdm_patient_links
        WHERE patient_id = ?
          AND cm_linking_token IS NOT NULL
          AND cm_linking_token <> ''
          AND UPPER(COALESCE(cm_token_purpose, '')) = 'LINK'
          AND (cm_token_expires_at IS NULL OR cm_token_expires_at > UTC_TIMESTAMP())
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $patientId);

    try {
        if (!$stmt->execute()) {
            @$stmt->close();

            return null;
        }
    } catch (Throwable $e) {
        error_log('ABDM CM-LINK | token read threw');
        @$stmt->close();

        return null;
    }

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    $token = trim((string)($row['cm_linking_token'] ?? ''));

    if ($token === '') {
        return null;
    }

    return $token;
}

/**
 * Resolve a patient_id from an ABHA address.
 *
 * FALLBACK CORRELATION ONLY.
 *
 * The primary join for /users/auth/on-confirm is the requestId recorded by
 * abdmM2RecordAuthLinkRequest(). This is used only when the CM omits that
 * requestId, so that a token is still captured rather than silently dropped.
 *
 * abdm_patient_links is unique per patient (unique_patient), so at most one row
 * can match. The ABHA address is compared case-insensitively and with surrounding
 * whitespace trimmed, matching how it is written at enrolment time.
 *
 * @param mysqli $conn
 * @param string $abhaAddress
 * @return int 0 when no patient matches.
 */
function abdmM2ResolvePatientByAbhaAddress($conn, $abhaAddress)
{
    if (!($conn instanceof mysqli)) {
        return 0;
    }

    $abhaAddress = trim((string)$abhaAddress);

    if ($abhaAddress === '') {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT patient_id
        FROM abdm_patient_links
        WHERE LOWER(TRIM(abha_address)) = LOWER(?)
        LIMIT 1
    ");

    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('s', $abhaAddress);

    try {
        if (!$stmt->execute()) {
            @$stmt->close();

            return 0;
        }
    } catch (Throwable $e) {
        error_log('ABDM CM-LINK | abha fallback lookup threw');
        @$stmt->close();

        return 0;
    }

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($row)) {
        return 0;
    }

    return (int)($row['patient_id'] ?? 0);
}