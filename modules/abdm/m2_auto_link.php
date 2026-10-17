<?php
/**
 * ABDM M2 - automatic care-context push after discovery (no OTP).
 *
 * Purpose
 * -------
 * Replaces the manual OTP confirmation leg of the HiECM user-initiated linking
 * flow. When a discovery request resolves to a patient, this module pushes that
 * patient's care contexts to the gateway directly through the HIP care-context
 * link API, so the records become visible without the patient transcribing a
 * code out of an SMS and neither this system nor an SMS gateway being involved.
 *
 * Which API is used, and why
 * --------------------------
 * POST {ABDM_BASE_URL}/hiecm/hip/v3/link/carecontext
 *
 * This is the only HiECM endpoint that links care contexts without patient
 * interaction. The gateway-driven alternative - on-init, then an OTP, then
 * on-confirm - is not optional: the gateway calls our on-init endpoint
 * regardless of what we do, so "bypassing OTP" means choosing a different
 * mechanism rather than skipping a step. That is what this module does.
 *
 * Hard preconditions enforced by the gateway
 * ------------------------------------------
 * abdmM2LinkCareContext() requires BOTH of these on the patient row, and
 * refuses the call outright when either is absent (modules/abdm/m2_care_context.php:103-114):
 *
 *   abdm_patient_links.abha_address
 *   abdm_patient_links.linking_token
 *
 * The HiECM care-context link API is ABHA-anchored by design, so a patient who
 * has no ABHA on record CANNOT be auto-linked at all - not by this module, not
 * by any other route. Such patients are still discovered and still receive an
 * on-discover notification, but the gateway drives their linking leg. This is
 * reported as skipped_no_abha rather than failing quietly, so the gap is visible
 * in the logs instead of looking like a working path that silently does nothing.
 *
 * Assumption worth auditing
 * -------------------------
 * When the discovery request was resolved from demographics alone, this module
 * pushes under the ABHA address already stored against the patient. It cannot
 * confirm that the ABHA asking is the same one: the request carried no ABHA, so
 * there is nothing to compare. The push is HIP-to-gateway under the patient's own
 * genuine ABHA, so nothing is disclosed to the requester, and the gateway treats
 * it as idempotent. Every such push is logged with assumption=abha_unconfirmed so
 * it can be reviewed, and matches carrying a matching ABHA anchor are logged with
 * assumption=abha_confirmed.
 *
 * Bounds
 * ------
 * Each pushed context is a separate outbound HTTP call, and
 * abdmM2LinkCareContext() mints a fresh gateway session token for every call, so
 * an unbounded push would mean one network round-trip per historical visit and
 * could outlast the background execution window. The list is therefore capped at
 * ABDM_M2_AUTOLINK_MAX_CONTEXTS and truncation is logged rather than hidden.
 *
 * Architecture constraint: procedural PHP, mysqli only, no framework, no ORM.
 */

defined('ABDM_M2_AUTO_LINK_LOADED') || define('ABDM_M2_AUTO_LINK_LOADED', true);

require_once __DIR__ . '/m2_care_context.php';

/**
 * Maximum number of care contexts pushed in a single discovery.
 *
 * Raise only with awareness of the per-context gateway session round-trip cost
 * described above.
 */
if (!defined('ABDM_M2_AUTOLINK_MAX_CONTEXTS')) {
    define('ABDM_M2_AUTOLINK_MAX_CONTEXTS', 20);
}

/**
 * HiTypes this module is willing to push automatically.
 *
 * abdmM2LinkCareContext() maintains its own allowlist and maps the internal
 * uppercase value onto the API spelling. This list exists so a newly added
 * internal HIType cannot be pushed automatically before it has been reviewed
 * for automatic disclosure.
 */
if (!defined('ABDM_M2_AUTOLINK_ALLOWED_HITYPES')) {
    define('ABDM_M2_AUTOLINK_ALLOWED_HITYPES', [
        'PRESCRIPTION',
        'OPCONSULTATION',
    ]);
}

/**
 * Read the auto-link preconditions for a patient.
 *
 * @param mysqli $conn
 * @param int    $patientId
 * @return array{ready:bool, reason:string, abha_address:string, has_token:bool}
 */
function abdmM2AutoLinkPreflight($conn, $patientId)
{
    $sql = '
        SELECT abha_address, linking_token
        FROM abdm_patient_links
        WHERE patient_id = ?
        LIMIT 1
    ';

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return ['ready' => false, 'reason' => 'preflight_query_failed', 'abha_address' => '', 'has_token' => false];
    }

    $stmt->bind_param('i', $patientId);

    try {
        $executed = $stmt->execute();
    } catch (Throwable $e) {
        error_log('ABDM AUTOLINK | preflight execute threw | ' . $e->getMessage());
        @$stmt->close();

        return ['ready' => false, 'reason' => 'preflight_query_failed', 'abha_address' => '', 'has_token' => false];
    }

    if (!$executed) {
        @$stmt->close();

        return ['ready' => false, 'reason' => 'preflight_query_failed', 'abha_address' => '', 'has_token' => false];
    }

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($row)) {
        return ['ready' => false, 'reason' => 'skipped_no_abha', 'abha_address' => '', 'has_token' => false];
    }

    $abhaAddress = trim((string)($row['abha_address'] ?? ''));
    $hasToken = trim((string)($row['linking_token'] ?? '')) !== '';

    if ($abhaAddress === '') {
        return ['ready' => false, 'reason' => 'skipped_no_abha', 'abha_address' => '', 'has_token' => $hasToken];
    }

    if (!$hasToken) {
        return ['ready' => false, 'reason' => 'skipped_no_link_token', 'abha_address' => $abhaAddress, 'has_token' => false];
    }

    return ['ready' => true, 'reason' => '', 'abha_address' => $abhaAddress, 'has_token' => true];
}

/**
 * Collect the care-context references already marked Linked for a patient.
 *
 * Used only to avoid redundant pushes. It deliberately does NOT restrict the
 * discovery response: the set of care contexts offered to the gateway on
 * discovery remains "all of them", because re-offering an already-linked context
 * is harmless and narrowing the offer would hide history the patient expects to
 * see.
 *
 * @param mysqli $conn
 * @param int    $patientId
 * @return array<string,bool> Keyed by care_context_reference.
 */
function abdmM2AutoLinkAlreadyLinked($conn, $patientId)
{
    $linked = [];

    $stmt = $conn->prepare('
        SELECT care_context_reference
        FROM abdm_care_contexts
        WHERE patient_id = ?
          AND link_status = \'Linked\'
    ');

    if (!$stmt) {
        return $linked;
    }

    $stmt->bind_param('i', $patientId);

    try {
        if ($stmt->execute()) {
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $reference = trim((string)($row['care_context_reference'] ?? ''));

                if ($reference !== '') {
                    $linked[$reference] = true;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('ABDM AUTOLINK | already-linked lookup threw | ' . $e->getMessage());
    }

    @$stmt->close();

    return $linked;
}

/**
 * Push a discovered patient's care contexts to the gateway without patient OTP.
 *
 * Designed to run after the discovery callback has already released its HTTP
 * response, so a slow or failing push cannot delay or fail the callback the
 * gateway is waiting on.
 *
 * @param int    $patientId
 * @param array  $careContexts Flat list from abdmM2GetPatientCareContexts().
 * @param string $matchMethod  'abha' or 'demographics', for logging only.
 * @return array Summary safe to persist in a longtext diagnostic column.
 */
function abdmM2AutoLinkCareContexts($patientId, $careContexts, $matchMethod = 'unknown')
{
    global $conn;

    $summary = [
        'attempted'        => 0,
        'linked'           => 0,
        'failed'           => 0,
        'skipped_already'  => 0,
        'skipped_hitype'   => 0,
        'skipped'          => true,
        'skip_reason'      => '',
        'truncated'        => false,
        'assumption'       => $matchMethod === 'abha' ? 'abha_confirmed' : 'abha_unconfirmed',
        'results'          => [],
    ];

    if (!($conn instanceof mysqli)) {
        $summary['skip_reason'] = 'no_connection';

        return $summary;
    }

    if (!is_array($careContexts) || !$careContexts) {
        $summary['skip_reason'] = 'no_care_contexts';

        return $summary;
    }

    $preflight = abdmM2AutoLinkPreflight($conn, (int)$patientId);

    if (empty($preflight['ready'])) {
        $summary['skip_reason'] = $preflight['reason'];

        /*
         * Not an error. The HiECM link API cannot accept a patient with no ABHA
         * on record, so this is the expected outcome for the majority of purely
         * demographic matches and is logged so the coverage gap is measurable.
         */
        error_log(
            'ABDM AUTOLINK | push skipped | reason=' . $preflight['reason'] .
            ' | match_method=' . $matchMethod
        );

        return $summary;
    }

    $summary['skipped'] = false;

    $alreadyLinked = abdmM2AutoLinkAlreadyLinked($conn, (int)$patientId);

    $pushed = 0;

    foreach ($careContexts as $context) {
        if ($pushed >= ABDM_M2_AUTOLINK_MAX_CONTEXTS) {
            $summary['truncated'] = true;
            break;
        }

        if (!is_array($context)) {
            continue;
        }

        $reference = trim((string)($context['referenceNumber'] ?? ''));
        $display = trim((string)($context['display'] ?? ''));
        $hiType = strtoupper(trim((string)($context['hiType'] ?? '')));

        if ($reference === '') {
            continue;
        }

        if (!in_array($hiType, ABDM_M2_AUTOLINK_ALLOWED_HITYPES, true)) {
            $summary['skipped_hitype']++;

            continue;
        }

        if (isset($alreadyLinked[$reference])) {
            $summary['skipped_already']++;

            continue;
        }

        $summary['attempted']++;
        $pushed++;

        $result = abdmM2LinkCareContext(
            (int)$patientId,
            $reference,
            $display,
            $hiType
        );

        $succeeded = !empty($result['success']);

        if ($succeeded) {
            $summary['linked']++;
        } else {
            $summary['failed']++;

            /*
             * The failure reason is logged. The response body is not, because it
             * may echo identifiers supplied by the gateway.
             */
            error_log(
                'ABDM AUTOLINK | push failed | hiType=' . $hiType .
                ' | http_code=' . (int)($result['http_code'] ?? 0) .
                ' | message=' . preg_replace('/[^A-Za-z0-9 ._\/-]/', '', (string)($result['message'] ?? 'unknown'))
            );
        }

        $summary['results'][] = [
            'referenceNumber' => $reference,
            'hiType'          => $hiType,
            'success'         => $succeeded,
            'http_code'       => (int)($result['http_code'] ?? 0),
        ];
    }

    error_log(
        'ABDM AUTOLINK | push complete | attempted=' . $summary['attempted'] .
        ' | linked=' . $summary['linked'] .
        ' | failed=' . $summary['failed'] .
        ' | skipped_already=' . $summary['skipped_already'] .
        ' | skipped_hitype=' . $summary['skipped_hitype'] .
        ' | truncated=' . ($summary['truncated'] ? 'YES' : 'NO') .
        ' | match_method=' . $matchMethod .
        ' | assumption=' . $summary['assumption']
    );

    return $summary;
}