<?php
/**
 * ABDM M2 - HIP-initiated care-context linking via the v0.5 link API.
 *
 *   POST {ABDM_BASE_URL}/gateway/v0.5/links/link/add-contexts
 *
 * Purpose
 * -------
 * Pushes a newly created care context (a prescription, an OPD consultation) to
 * the Consent Manager under the patient's ABHA, using the CM linking token
 * captured by api/v0.5/users/auth/on-confirm/index.php. This is the step that
 * makes the record appear under the patient's "My Records" without any OTP being
 * generated, texted or verified by this installation.
 *
 * Authorization model
 * -------------------
 * TWO DISTINCT CREDENTIALS ARE INVOLVED, and conflating them is the single
 * easiest mistake to make here:
 *
 *   Authorization: Bearer <HIP session token>
 *       Identifies THIS FACILITY to the gateway. Obtained from
 *       POST {ABDM_BASE_URL}/gateway/v0.5/sessions with the installation's own
 *       client credentials - the same call modules/abdm/auth.php already makes.
 *       That is abdmGetAccessToken(), NOT abdmM2GetAccessToken(): the latter
 *       targets the v3 HiECM host layout, which is a different API family.
 *
 *   link.accessToken (request BODY)
 *       Identifies THE PATIENT's consent to link. Supplied by the Consent
 *       Manager after the patient authorised the request, stored in
 *       abdm_patient_links.cm_linking_token.
 *
 * Putting the linking token in the Authorization header does not work; putting
 * the session token in the body does not work.
 *
 * No token, no push, no fallback
 * -----------------------------
 * When the patient has no usable CM linking token this function REFUSES and
 * returns a skipped result. It deliberately does NOT fall back to the older
 * HiECM path (modules/abdm/m2_care_context.php), for two reasons:
 *
 *   1. A silent fallback makes it impossible to tell from the logs which
 *      mechanism actually linked a care context, which is the first question to
 *      ask when a record does not show up in the app.
 *   2. The HiECM path is already known to fail for PRESCRIPTION-type contexts
 *      while succeeding for OPCONSULTATION. Reintroducing it as a fallback would
 *      silently reintroduce that unresolved bug under a new name.
 *
 * Skipping is a normal, expected outcome: it means the patient has not yet
 * authorised linking. It is logged so the coverage gap is measurable rather than
 * invisible.
 *
 * Accept-vs-confirm semantics
 * ---------------------------
 * This is an ASYNCHRONOUS gateway call. A 2xx means the request was ACCEPTED,
 * not that the care context is linked. ABDM may also return an inline
 * acknowledgement, which is checked here; the authoritative confirmation is the
 * later callback to /v0.5/links/link/on-add-contexts, which is a separate
 * endpoint and is not part of this module.
 *
 * abdm_care_contexts.link_status is therefore set to 'Linked' on acceptance, so
 * that the "already linked, skip it" gate in modules/opd/save_prescription.php
 * does not re-push the same context on every save. This is optimistic and is the
 * same semantic the previous HiECM path used. link_method is set to
 * 'add-contexts' so the two mechanisms stay distinguishable.
 *
 * Architecture constraint: procedural PHP, mysqli only, no framework, no ORM.
 *
 * Load context
 * ------------
 * Loaded from staff-facing request handlers that DO have a session, so it may
 * require the ABDM auth modules. It must NOT be loaded from a public ABDM
 * callback - see api/v0.5/users/auth/on-confirm/index.php, which uses
 * m2_cm_token.php precisely because that file requires nothing.
 */

defined('ABDM_M2_ADD_CONTEXTS_LOADED') || define('ABDM_M2_ADD_CONTEXTS_LOADED', true);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/m2_auth.php';
require_once __DIR__ . '/m2_cm_token.php';

if (!defined('ABDM_M2_ADD_CONTEXTS_TIMEOUT')) {
    define('ABDM_M2_ADD_CONTEXTS_TIMEOUT', 30);
}

/**
 * Internal hiType to API spelling.
 *
 * The v0.5 link API uses the same hiType vocabulary as the consent and
 * health-information APIs. Kept local rather than reused from
 * m2_care_context.php so this module has no dependency on the HiECM path it is
 * meant to be independent of.
 *
 * @var array<string,string>
 */
if (!defined('ABDM_M2_ADD_CONTEXTS_HITYPE_MAP')) {
    define('ABDM_M2_ADD_CONTEXTS_HITYPE_MAP', [
        'PRESCRIPTION'         => 'Prescription',
        'DIAGNOSTIC_REPORT'    => 'DiagnosticReport',
        'OPCONSULTATION'       => 'OPConsultation',
        'DISCHARGESUMMARY'     => 'DischargeSummary',
        'IMMUNIZATIONRECORD'   => 'ImmunizationRecord',
        'HEALTHDOCUMENTRECORD' => 'HealthDocumentRecord',
        'WELLNESSRECORD'       => 'WellnessRecord',
        'INVOICE'              => 'Invoice',
    ]);
}

/**
 * HiTypes this module is willing to push.
 *
 * A newly added internal HIType must be reviewed for automatic disclosure
 * before it is pushable, so the allowlist is explicit rather than derived.
 *
 * @var string[]
 */
if (!defined('ABDM_M2_ADD_CONTEXTS_ALLOWED_HITYPES')) {
    define('ABDM_M2_ADD_CONTEXTS_ALLOWED_HITYPES', [
        'PRESCRIPTION',
        'OPCONSULTATION',
    ]);
}

/**
 * Record the intent to link a care context, before the gateway call.
 *
 * Keeps abdm_care_contexts in step with what this module is about to attempt.
 * The table has a UNIQUE key on (patient_id, care_context_reference) in
 * practice - it is written with ON DUPLICATE KEY UPDATE for the same reason
 * m2_link_token.php does - so re-attempting a previously failed context resets
 * it to Pending rather than accumulating rows.
 *
 * @param mysqli $conn
 * @param int    $patientId
 * @param string $reference
 * @param string $display
 * @param string $hiType
 * @return bool
 */
function abdmM2RecordCareContextIntent($conn, $patientId, $reference, $display, $hiType)
{
    $patientId = (int)$patientId;
    $status = 'Pending';
    $method = 'add-contexts';

    $stmt = $conn->prepare("
        INSERT INTO abdm_care_contexts
            (patient_id, care_context_reference, care_context_display,
             care_context_type, link_status, link_method)
        VALUES
            (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            care_context_display = VALUES(care_context_display),
            care_context_type   = VALUES(care_context_type),
            link_status         = VALUES(link_status),
            link_method         = VALUES(link_method),
            updated_at          = CURRENT_TIMESTAMP
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('isssss', $patientId, $reference, $display, $hiType, $status, $method);

    try {
        $ok = (bool)$stmt->execute();
    } catch (Throwable $e) {
        error_log('ABDM ADD-CONTEXTS | intent record threw');
        $ok = false;
    }

    $stmt->close();

    return $ok;
}

/**
 * Move a care context to a terminal state after the gateway call.
 *
 * @param mysqli $conn
 * @param int    $patientId
 * @param string $reference
 * @param bool   $accepted  True on acceptance, false on rejection.
 * @return void
 */
function abdmM2SetCareContextResult($conn, $patientId, $reference, $accepted)
{
    $patientId = (int)$patientId;

    if ($accepted) {
        $stmt = $conn->prepare("
            UPDATE abdm_care_contexts
            SET link_status = 'Linked',
                linked_at   = CURRENT_TIMESTAMP,
                updated_at  = CURRENT_TIMESTAMP
            WHERE patient_id = ?
              AND care_context_reference = ?
            LIMIT 1
        ");
    } else {
        $stmt = $conn->prepare("
            UPDATE abdm_care_contexts
            SET link_status = 'Failed',
                linked_at   = NULL,
                updated_at  = CURRENT_TIMESTAMP
            WHERE patient_id = ?
              AND care_context_reference = ?
            LIMIT 1
        ");
    }

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('is', $patientId, $reference);

    try {
        @$stmt->execute();
    } catch (Throwable $e) {
        error_log('ABDM ADD-CONTEXTS | result update threw');
    }

    @$stmt->close();
}

/**
 * Push one care context to the Consent Manager.
 *
 * @param int    $patientId
 * @param string $careContextReference
 * @param string $careContextDisplay
 * @param string $hiType  Internal uppercase name, e.g. PRESCRIPTION.
 * @return array
 */
function abdmM2AddCareContexts($patientId, $careContextReference, $careContextDisplay, $hiType = 'PRESCRIPTION')
{
    global $conn;

    /*
     * GLOBAL ABDM SWITCH
     *
     * Central refusal for every outbound v0.5 linking call. When the
     * installation has ABDM switched off, or the setting cannot be read, no
     * gateway request is made and the standard disabled shape is returned so
     * the calling prescription save simply carries on.
     */
    if (!abdmOutboundAllowed($conn)) {
        return abdmDisabledResponse();
    }

    $patientId = (int)$patientId;
    $careContextReference = trim((string)$careContextReference);
    $careContextDisplay = trim((string)$careContextDisplay);
    $hiType = strtoupper(trim((string)$hiType));

    if ($patientId <= 0) {
        return ['success' => false, 'message' => 'Valid patient_id is required.'];
    }

    if ($careContextReference === '') {
        return ['success' => false, 'message' => 'Care context reference is required.'];
    }

    if ($careContextDisplay === '') {
        return ['success' => false, 'message' => 'Care context display is required.'];
    }

    if (!in_array($hiType, ABDM_M2_ADD_CONTEXTS_ALLOWED_HITYPES, true)) {
        return ['success' => false, 'message' => 'Health information type is not pushable automatically.'];
    }

    $apiHiType = ABDM_M2_ADD_CONTEXTS_HITYPE_MAP[$hiType];

    /*
     * THE GATE.
     *
     * No usable CM linking token means the patient has not authorised linking,
     * so there is nothing to authorise the push with. Returning here rather than
     * proceeding keeps the "no fallback" decision in exactly one place.
     *
     * abdmM2GetCmLinkingToken() already enforces that the token is present,
     * purpose = LINK, and unexpired (NULL meaning perpetual).
     */
    $linkingToken = abdmM2GetCmLinkingToken($conn, $patientId);

    if ($linkingToken === null) {
        error_log(
            'ABDM ADD-CONTEXTS | push skipped | no_cm_linking_token' .
            ' | hiType=' . $hiType . ' | cc=' . $careContextReference
        );

        return [
            'success'     => false,
            'skipped'     => true,
            'skip_reason' => 'no_cm_linking_token',
        ];
    }

    $stmt = $conn->prepare("
        SELECT p.first_name, p.last_name, l.abha_address
        FROM patient_master p
        LEFT JOIN abdm_patient_links l ON l.patient_id = p.id
        WHERE p.id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return ['success' => false, 'message' => 'Unable to prepare patient query.'];
    }

    $stmt->bind_param('i', $patientId);

    try {
        $executed = $stmt->execute();
    } catch (Throwable $e) {
        error_log('ABDM ADD-CONTEXTS | patient lookup threw');
        @$stmt->close();

        return ['success' => false, 'message' => 'Patient lookup failed.'];
    }

    if (!$executed) {
        @$stmt->close();

        return ['success' => false, 'message' => 'Patient lookup failed.'];
    }

    $patient = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($patient)) {
        return ['success' => false, 'message' => 'Patient not found.'];
    }

    $abhaAddress = trim((string)($patient['abha_address'] ?? ''));

    if ($abhaAddress === '') {
        /*
         * add-contexts is ABHA-anchored. A patient with no ABHA on record cannot
         * be linked by this route or any other, so this is reported rather than
         * attempted and left to fail at the gateway.
         */
        error_log(
            'ABDM ADD-CONTEXTS | push skipped | skipped_no_abha' .
            ' | cc=' . $careContextReference
        );

        return [
            'success'     => false,
            'skipped'     => true,
            'skip_reason' => 'skipped_no_abha',
        ];
    }

    $patientDisplay = trim(
        (string)($patient['first_name'] ?? '') . ' ' . (string)($patient['last_name'] ?? '')
    );

    if ($patientDisplay === '') {
        $patientDisplay = (string)$patientId;
    }

    $auth = abdmGetAccessToken();

    if (empty($auth['success'])) {
        /*
         * The failure detail is not propagated: abdmGetAccessToken() carries the
         * gateway's raw error body on failure and, on an unexpected response
         * shape, could carry a token.
         */
        error_log('ABDM ADD-CONTEXTS | gateway authentication failed');

        return [
            'success' => false,
            'message' => 'Unable to authorise with the ABDM gateway.',
        ];
    }

    $settingsStmt = $conn->prepare("
        SELECT hip_id
        FROM abdm_m2_settings
        WHERE status = 'Active'
        ORDER BY id DESC
        LIMIT 1
    ");

    if (!$settingsStmt) {
        return ['success' => false, 'message' => 'Unable to prepare M2 settings query.'];
    }

    $settingsStmt->execute();
    $m2Settings = $settingsStmt->get_result()->fetch_assoc();
    $settingsStmt->close();

    $hipId = is_array($m2Settings) ? trim((string)($m2Settings['hip_id'] ?? '')) : '';

    if ($hipId === '') {
        return ['success' => false, 'message' => 'HIP ID is missing in M2 settings.'];
    }

    $requestId = abdmM2GenerateRequestId();
    $timestamp = gmdate('Y-m-d\TH:i:s.v\Z');

    /*
     * Sandbox FAQ: add-contexts raises code 1500 "referenceNumber attribute
     * required in the payload" if any patient or care-context attribute is null.
     * Every field below is therefore populated explicitly.
     */
    $payload = [
        'link' => [
            'accessToken' => $linkingToken,
            'patient'     => [
                'referenceNumber' => (string)$patientId,
                'display'         => $patientDisplay,
                'abhaAddress'     => $abhaAddress,
                'careContexts'    => [
                    [
                        'referenceNumber' => $careContextReference,
                        'display'         => $careContextDisplay,
                        'hiType'          => $apiHiType,
                    ],
                ],
            ],
        ],
    ];

    if (!abdmM2RecordCareContextIntent(
        $conn,
        $patientId,
        $careContextReference,
        $careContextDisplay,
        $hiType
    )) {
        error_log('ABDM ADD-CONTEXTS | could not record intent | cc=' . $careContextReference);
    }

    $url = ABDM_BASE_URL . '/gateway/v0.5/links/link/add-contexts';

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $auth['access_token'],
            'X-CM-ID: '    . ABDM_CM_ID,
            'X-HIP-ID: '   . $hipId,
            'REQUEST-ID: ' . $requestId,
            'TIMESTAMP: '  . $timestamp,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => ABDM_M2_ADD_CONTEXTS_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        abdmM2SetCareContextResult($conn, $patientId, $careContextReference, false);

        error_log(
            'ABDM ADD-CONTEXTS | transport error' .
            ' | hiType=' . $hiType . ' | cc=' . $careContextReference .
            ' | request_id=' . $requestId
        );

        return [
            'success'    => false,
            'http_code'  => $httpCode,
            'message'    => 'Transport error contacting the ABDM gateway.',
            'request_id' => $requestId,
        ];
    }

    $decoded = json_decode($response, true);
    $accepted = ($httpCode >= 200 && $httpCode < 300);

    /*
     * AN INLINE ACKNOWLEDGEMENT OVERRIDES THE TRANSPORT STATUS.
     *
     * A 2xx with acknowledgement.status of anything other than SUCCESS is an
     * accepted-then-rejected request, and must not be recorded as linked.
     */
    if ($accepted && is_array($decoded) && isset($decoded['acknowledgement']['status'])) {
        $ackStatus = strtoupper(trim((string)$decoded['acknowledgement']['status']));

        if ($ackStatus !== '' && $ackStatus !== 'SUCCESS') {
            $accepted = false;

            error_log(
                'ABDM ADD-CONTEXTS | acknowledgement rejected' .
                ' | ack_status=' . preg_replace('/[^A-Za-z0-9_.\-]/', '', $ackStatus) .
                ' | cc=' . $careContextReference
            );
        }
    }

    abdmM2SetCareContextResult($conn, $patientId, $careContextReference, $accepted);

    /*
     * THE RESPONSE BODY IS NEVER LOGGED. It can echo the linking token back.
     */
    error_log(
        'ABDM ADD-CONTEXTS | ' . ($accepted ? 'accepted' : 'rejected') .
        ' | hiType=' . $hiType .
        ' | cc=' . $careContextReference .
        ' | http_code=' . $httpCode .
        ' | request_id=' . $requestId
    );

    return [
        'success'    => $accepted,
        'http_code'  => $httpCode,
        'request_id' => $requestId,
        'message'    => $accepted
            ? 'Care context link accepted by the gateway.'
            : 'Care context link rejected by the gateway.',
        'response'   => is_array($decoded) ? $decoded : null,
    ];
}