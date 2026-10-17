<?php
/**
 * ABDM M2 - user authorisation handshake (v0.5), outbound leg.
 *
 * Purpose
 * -------
 * Drives the HIP-initiated linking handshake that produces the CM linking token
 * which api/v0.5/users/auth/on-confirm/index.php then stores:
 *
 *   1. POST {ABDM_BASE_URL}/gateway/v0.5/users/auth/fetch-modes
 *      Ask which authentication modes the Consent Manager supports for this
 *      patient and this purpose.
 *
 *   2. POST {ABDM_BASE_URL}/gateway/v0.5/users/auth/init
 *      Open the authorisation request. The patient completes it in the ABHA
 *      Sandbox app, not on this system.
 *
 *   3. POST {ABDM_BASE_URL}/gateway/v0.5/users/auth/on-confirm
 *      Inbound callback (separate file). Carries the linking token.
 *
 *   4. POST {ABDM_BASE_URL}/gateway/v0.5/links/link/add-contexts
 *      Consume the token. See m2_add_contexts.php.
 *
 * Where the OTP actually lives
 * ----------------------------
 * There is still a patient authentication step - ABDM does not offer an
 * unattended linking mode. What changes by moving to this flow is WHERE the OTP
 * is validated: in the ABHA Sandbox app and the Consent Manager, instead of in
 * this installation's own SMS gateway. No OTP is generated, stored, texted or
 * verified by this codebase any more, which is what removes the MSG91 dependency
 * from linking.
 *
 * DIRECT vs MEDIATED
 * ------------------
 * ABDM issues a PERPETUAL access token for DIRECT auth mode and a short-lived
 * one for mediated modes. The token is only useful at prescription-save time, so
 * DIRECT is strongly preferred: a mediated token is typically expired long
 * before the doctor saves the prescription. abdmM2AuthLinkInit() therefore
 * defaults to DIRECT and records which mode was actually used.
 *
 * Architecture constraint: procedural PHP, mysqli only, no framework, no ORM.
 *
 * Load context
 * ------------
 * Unlike m2_cm_token.php, this file DOES require m2_auth.php and auth.php and is
 * therefore only safe to load from a session-bearing entry point (an admin page
 * or a staff action). It must NOT be loaded from the public on-confirm callback.
 */

defined('ABDM_M2_AUTH_LINK_LOADED') || define('ABDM_M2_AUTH_LINK_LOADED', true);

require_once __DIR__ . '/m2_auth.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/m2_cm_token.php';

/**
 * Auth modes the CM may report from /users/auth/fetch-modes.
 *
 * @var string[]
 */
if (!defined('ABDM_M2_CM_REPORTED_MODES')) {
    define('ABDM_M2_CM_REPORTED_MODES', [
        'DEMOGRAPHICS',
        'AADHAAR_OTP',
        'MOBILE_OTP',
        'DIRECT',
    ]);
}

if (!defined('ABDM_M2_AUTH_LINK_TIMEOUT')) {
    define('ABDM_M2_AUTH_LINK_TIMEOUT', 30);
}

/**
 * The v0.5 gateway base URL for the /users/auth family.
 *
 * Built from ABDM_BASE_URL (modules/abdm/config.php), which resolves to
 *   Sandbox    https://dev.abdm.gov.in
 *   Production https://apis.abdm.gov.in
 * and the /gateway/v0.5 prefix the ABDM API flow documentation uses.
 *
 * This is deliberately NOT ABDM_M2_BASE_URL. That constant carries the /api
 * suffix and belongs to the v3 HiECM family, which is a different host layout.
 */
if (!function_exists('abdmM2AuthLinkBaseUrl')) {
    function abdmM2AuthLinkBaseUrl()
    {
        return ABDM_BASE_URL . '/gateway/v0.5';
    }
}

/**
 * The session token for the v0.5 gateway.
 *
 * abdmGetAccessToken() (modules/abdm/auth.php:76) already performs exactly the
 * call ABDM documents for this API family:
 *
 *   POST {ABDM_BASE_URL}/gateway/v0.5/sessions   {clientId, clientSecret}
 *
 * It reads its credentials from abdm_m1_settings. That is the same credential
 * store abdmM2GetAccessToken() reads from (m2_auth.php:74-90), so using it here
 * introduces no new credential source and honours the global ABDM switch through
 * abdmOutboundAllowed().
 *
 * @param mysqli $conn
 * @return array
 */
function abdmM2AuthLinkSession($conn)
{
    if (!abdmOutboundAllowed($conn)) {
        return abdmDisabledResponse();
    }

    $auth = abdmGetAccessToken();

    if (empty($auth['success'])) {
        return [
            'success'    => false,
            'http_code'  => (int)($auth['http_code'] ?? 0),
            'message'    => 'Unable to authorise with the ABDM gateway.',
            'reason'     => 'session_failed',
        ];
    }

    return [
        'success'    => true,
        'http_code'  => (int)($auth['http_code'] ?? 200),
        'token'      => $auth['access_token'],
        'expires_in' => $auth['expires_in'] ?? null,
    ];
}

/**
 * Resolve the ABDM-relevant identity of a patient for the auth handshake.
 *
 * The Consent Manager identifies the patient by an identifier the patient
 * already has on file there. This installation has no explicit choice recorded,
 * so verified mobile is preferred and ABHA address is used as the secondary
 * identifier.
 *
 * @param mysqli $conn
 * @param int    $patientId
 * @return array{patient_id:int, abha_address:string, mobile:string, display:string}|null
 */
function abdmM2AuthLinkResolvePatient($conn, $patientId)
{
    if (!($conn instanceof mysqli)) {
        return null;
    }

    $patientId = (int)$patientId;

    if ($patientId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            p.id,
            p.first_name,
            p.last_name,
            p.mobile,
            l.abha_address
        FROM patient_master p
        LEFT JOIN abdm_patient_links l ON l.patient_id = p.id
        WHERE p.id = ?
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
        error_log('ABDM CM-LINK | patient lookup threw');
        @$stmt->close();

        return null;
    }

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    $mobile = preg_replace('/\D/', '', trim((string)($row['mobile'] ?? '')));

    $display = trim(
        (string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')
    );

    return [
        'patient_id'   => (int)($row['id'] ?? 0),
        'abha_address' => trim((string)($row['abha_address'] ?? '')),
        'mobile'       => strlen($mobile) === 10 ? $mobile : '',
        'display'      => $display !== '' ? $display : (string)$patientId,
    ];
}

/**
 * Issue one authenticated POST against the v0.5 gateway.
 *
 * The response BODY is deliberately not logged. It can echo the identifier or
 * the issued token. Only transport status and fixed labels are recorded.
 *
 * @param mysqli $conn
 * @param string $path      Path relative to abdmM2AuthLinkBaseUrl().
 * @param array  $payload
 * @param string $hipId
 * @param string $requestId
 * @return array{success:bool, http_code:int, body:array|string|null, reason:string}
 */
function abdmM2AuthLinkPost($conn, $path, array $payload, $hipId, $requestId)
{
    $session = abdmM2AuthLinkSession($conn);

    if (empty($session['success'])) {
        return [
            'success'   => false,
            'http_code' => (int)($session['http_code'] ?? 0),
            'body'      => null,
            'reason'    => (string)($session['reason'] ?? 'session_failed'),
        ];
    }

    $url = abdmM2AuthLinkBaseUrl() . $path;
    $timestamp = gmdate('Y-m-d\TH:i:s.v\Z');

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $session['token'],
            'X-CM-ID: '       . ABDM_CM_ID,
            'X-HIP-ID: '      . $hipId,
            'REQUEST-ID: '    . $requestId,
            'TIMESTAMP: '     . $timestamp,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => ABDM_M2_AUTH_LINK_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        error_log('ABDM CM-LINK | transport error on ' . $path . ' | curl_error_present');

        return [
            'success'   => false,
            'http_code' => $httpCode,
            'body'      => null,
            'reason'    => 'transport_error',
        ];
    }

    $decoded = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        /*
         * Error codes are logged because they are a fixed enum and are the
         * fastest way to distinguish "no such patient at the CM" from "bad
         * credentials". The message text is not, because sandbox revisions vary
         * it and it can carry the submitted identifier.
         */
        error_log(
            'ABDM CM-LINK | ' . $path . ' rejected | http_code=' . $httpCode .
            ' | code=' . preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($decoded['error']['code'] ?? 'unknown'))
        );

        return [
            'success'   => false,
            'http_code' => $httpCode,
            'body'      => is_array($decoded) ? $decoded : $response,
            'reason'    => 'http_error',
        ];
    }

    return [
        'success'   => true,
        'http_code' => $httpCode,
        'body'      => is_array($decoded) ? $decoded : null,
        'reason'    => 'ok',
    ];
}

/**
 * Read the active HIP ID.
 *
 * @param mysqli $conn
 * @return string
 */
function abdmM2AuthLinkHipId($conn)
{
    if (!($conn instanceof mysqli)) {
        return '';
    }

    $stmt = $conn->prepare("
        SELECT hip_id
        FROM abdm_m2_settings
        WHERE status = 'Active'
        ORDER BY id DESC
        LIMIT 1
    ");

    if (!$stmt) {
        return '';
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return is_array($row) ? trim((string)($row['hip_id'] ?? '')) : '';
}

/**
 * Ask the CM which authentication modes this patient supports for a purpose.
 *
 * ABDM requires this before /users/auth/init so the HIP does not request a mode
 * the patient cannot satisfy.
 *
 * @param mysqli $conn
 * @param array  $patient  Result of abdmM2AuthLinkResolvePatient().
 * @param string $purpose  LINK / KYC / KYC_AND_LINK.
 * @return array{success:bool, modes:string[], http_code:int, reason:string}
 */
function abdmM2AuthLinkFetchModes($conn, array $patient, $purpose = 'LINK')
{
    $purpose = (string)abdmM2NormalizeCmPurpose($purpose);

    if ($purpose === '') {
        $purpose = 'LINK';
    }

    $hipId = abdmM2AuthLinkHipId($conn);

    if ($hipId === '') {
        return ['success' => false, 'modes' => [], 'http_code' => 0, 'reason' => 'hip_id_missing'];
    }

    $identifier = [];

    if ($patient['abha_address'] !== '') {
        $identifier = ['authType' => 'ABHA', 'authValue' => $patient['abha_address']];
    } elseif ($patient['mobile'] !== '') {
        $identifier = ['authType' => 'MOBILE', 'authValue' => $patient['mobile']];
    } else {
        return ['success' => false, 'modes' => [], 'http_code' => 0, 'reason' => 'no_identifier'];
    }

    $requestId = abdmM2GenerateRequestId();

    $response = abdmM2AuthLinkPost($conn, '/users/auth/fetch-modes', [
        'requestId'  => $requestId,
        'timestamp'  => gmdate('Y-m-d\TH:i:s.v\Z'),
        'purpose'    => $purpose,
        'identifier' => (object)$identifier,
        'hipId'      => $hipId,
    ], $hipId, $requestId);

    if (empty($response['success'])) {
        return [
            'success'   => false,
            'modes'     => [],
            'http_code' => (int)$response['http_code'],
            'reason'    => (string)$response['reason'],
        ];
    }

    /*
     * The response nests the supported modes differently between ABDM revisions
     * (auth.mode as an array in some, auth.authModes in others). Both are read
     * and normalised to uppercase so the caller has one shape to reason about.
     */
    $modes = [];
    $auth = $response['body']['auth'] ?? null;

    if (is_array($auth)) {
        foreach (['mode', 'authModes', 'modes'] as $field) {
            if (isset($auth[$field]) && is_array($auth[$field])) {
                foreach ($auth[$field] as $entry) {
                    $value = is_array($entry) ? ($entry['authMode'] ?? $entry['mode'] ?? '') : $entry;
                    $value = strtoupper(trim((string)$value));

                    if ($value !== '' && in_array($value, ABDM_M2_CM_REPORTED_MODES, true)) {
                        $modes[$value] = true;
                    }
                }
            }
        }
    }

    $modes = array_keys($modes);
    sort($modes);

    error_log(
        'ABDM CM-LINK | fetch-modes ok | purpose=' . $purpose .
        ' | modes=' . ($modes ? implode('+', $modes) : 'NONE')
    );

    return [
        'success'   => true,
        'modes'     => $modes,
        'http_code' => (int)$response['http_code'],
        'reason'    => 'ok',
    ];
}

/**
 * Open a linking authorisation request.
 *
 * This is the OUTBOUND half of HIP-initiated linking. The patient completes the
 * authorisation in the ABHA Sandbox app; this system receives nothing further
 * until the CM calls api/v0.5/users/auth/on-confirm/index.php.
 *
 * The pending row is written BEFORE the call is issued, so the requestId the CM
 * will echo back is already durable. If the process were to die between the two,
 * an un-callback-able token would be a silent, unrecoverable loss.
 *
 * @param mysqli  $conn
 * @param int     $patientId
 * @param string  $purpose
 * @param string  $authMode  DIRECT / DEMOGRAPHICS / AADHAAR_OTP / MOBILE_OTP.
 * @return array
 */
function abdmM2AuthLinkInit($conn, $patientId, $purpose = 'LINK', $authMode = 'DIRECT')
{
    if (!abdmOutboundAllowed($conn)) {
        return abdmDisabledResponse();
    }

    $patientId = (int)$patientId;

    if ($patientId <= 0) {
        return ['success' => false, 'message' => 'Valid patient_id is required.'];
    }

    $purpose = (string)abdmM2NormalizeCmPurpose($purpose);

    if ($purpose === '') {
        $purpose = 'LINK';
    }

    $patient = abdmM2AuthLinkResolvePatient($conn, $patientId);

    if ($patient === null) {
        return ['success' => false, 'message' => 'Patient not found.'];
    }

    if ($patient['abha_address'] === '') {
        /*
         * Not a recoverable request. add-contexts is ABHA-anchored, so a patient
         * with no ABHA on record cannot be linked by any route. Reported
         * explicitly so it is visible in the log instead of looking like a
         * working path that silently does nothing.
         */
        error_log('ABDM CM-LINK | init refused | skipped_no_abha');

        return ['success' => false, 'skipped' => true, 'skip_reason' => 'skipped_no_abha'];
    }

    $hipId = abdmM2AuthLinkHipId($conn);

    if ($hipId === '') {
        return ['success' => false, 'message' => 'HIP ID is missing in M2 settings.'];
    }

    $authMode = strtoupper(trim((string)$authMode));

    if ($authMode === '') {
        $authMode = 'DIRECT';
    }

    if (!in_array($authMode, ABDM_M2_CM_REPORTED_MODES, true)) {
        return ['success' => false, 'message' => 'Unsupported authentication mode.'];
    }

    $requestId = abdmM2GenerateRequestId();

    $recorded = abdmM2RecordAuthLinkRequest(
        $conn,
        $requestId,
        $patientId,
        $purpose,
        $authMode,
        $patient['abha_address']
    );

    if (empty($recorded['ok'])) {
        return [
            'success' => false,
            'message' => 'Unable to record the pending authorisation request.',
            'reason'  => (string)$recorded['reason'],
        ];
    }

    $response = abdmM2AuthLinkPost($conn, '/users/auth/init', [
        'requestId'  => $requestId,
        'timestamp'  => gmdate('Y-m-d\TH:i:s.v\Z'),
        'auth'       => [
            'mode'    => $authMode,
            'purpose' => $purpose,
        ],
        'identifier' => [
            'authType'  => 'ABHA',
            'authValue' => $patient['abha_address'],
        ],
        'hipId'      => $hipId,
    ], $hipId, $requestId);

    if (empty($response['success'])) {
        abdmM2MarkAuthLinkRequestStatus($conn, $requestId, 'Failed');

        return [
            'success'   => false,
            'http_code' => (int)$response['http_code'],
            'message'   => 'Unable to open the linking authorisation request.',
            'reason'    => (string)$response['reason'],
            'request_id' => $requestId,
        ];
    }

    error_log(
        'ABDM CM-LINK | init issued | mode=' . $authMode .
        ' | purpose=' . $purpose .
        ' | has_transaction=' . (isset($response['body']['transactionId']) ? 'YES' : 'NO')
    );

    return [
        'success'    => true,
        'http_code'  => (int)$response['http_code'],
        'request_id' => $requestId,
        'mode'       => $authMode,
        'purpose'    => $purpose,
        /*
         * The transactionId is what the patient sees as a pending authorisation
         * request in the app. It is returned to the calling page, not logged.
         */
        'transaction_id' => (string)($response['body']['transactionId'] ?? ''),
    ];
}