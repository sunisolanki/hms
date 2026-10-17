<?php
require_once __DIR__ . '/m2_auth.php';

function abdmM2SendUserLinkingOnDiscover(
    $transactionId,
    $patientRecords,
    $matchedBy,
    $requestId
) {
    $transactionId = trim($transactionId);
    $requestId = trim($requestId);

    if ($transactionId === '') {
        return [
            'success' => false,
            'message' => 'Transaction ID is required.'
        ];
    }

    if (!is_array($patientRecords)) {
        return [
            'success' => false,
            'message' => 'Patient records must be an array.'
        ];
    }

    if (!is_array($matchedBy)) {
        $matchedBy = [];
    }

    if ($requestId === '') {
        return [
            'success' => false,
            'message' => 'Original discovery request ID is required.'
        ];
    }

    /*
     * Convert internal HIType values to ABDM API HIType values.
     * Internal value PRESCRIPTION must be sent to ABDM as Prescription.
     */
    $hiTypeMap = [
        'PRESCRIPTION' => 'Prescription',
        'DIAGNOSTICREPORT' => 'DiagnosticReport',
        'OPCONSULTATION' => 'OPConsultation',
        'DISCHARGESUMMARY' => 'DischargeSummary',
        'IMMUNIZATIONRECORD' => 'ImmunizationRecord',
        'HEALTHDOCUMENTRECORD' => 'HealthDocumentRecord',
        'WELLNESSRECORD' => 'WellnessRecord',
        'INVOICE' => 'Invoice'
    ];

    $outboundPatientRecords = [];

    foreach ($patientRecords as $patientRecord) {
        if (!is_array($patientRecord)) {
            continue;
        }

        $outboundPatientRecord = $patientRecord;

        $hiType = strtoupper(trim((string)($patientRecord['hiType'] ?? '')));

        if ($hiType !== '' && isset($hiTypeMap[$hiType])) {
            $outboundPatientRecord['hiType'] = $hiTypeMap[$hiType];
        }

        $outboundPatientRecords[] = $outboundPatientRecord;
    }

    $auth = abdmM2GetAccessToken();

    if (empty($auth['success'])) {
        error_log(
            'ABDM ON-DISCOVER | outbound aborted before dispatch | reason=m2_auth_failed' .
            ' | http_code=' . (int)($auth['http_code'] ?? 0)
        );

        return [
            'success' => false,
            'message' => 'M2 authentication failed.',
            'details' => $auth
        ];
    }

    $timestamp = gmdate('Y-m-d\TH:i:s.v\Z');
    $apiRequestId = abdmM2GenerateRequestId();

    /*
     * ABDM v3 on-discover body.
     *
     * Shape notes, because several of these are easy to get wrong and the
     * gateway rejects the whole call if they are:
     *
     *   - The correlation key is `response`.requestId, NOT `resp`. `resp` is
     *     silently ignored, leaving the gateway unable to correlate the
     *     notification with the discovery that triggered it.
     *   - `patient` is an ARRAY, one entry per HIType group, not a single
     *     object. abdmM2BuildDiscoveryResponse() groups care contexts by HIType
     *     and emits one record per group, each carrying its own `hiType` and
     *     `count`.
     *   - `matchedBy` is REQUIRED and must list the identifiers that actually
     *     resolved the patient. Omitting it is rejected. Values come from the
     *     matcher's matched_identifiers, so a demographic match reports MOBILE
     *     rather than falsely claiming an ABHA match.
     *   - requestId and timestamp are sent in BOTH the headers (REQUEST-ID,
     *     TIMESTAMP) and the body. The gateway reads the headers; the body
     *     copies make the notification self-describing for our own audit trail
     *     and are ignored by the gateway.
     */
    $payload = [
        'requestId'    => $apiRequestId,
        'timestamp'    => $timestamp,
        'transactionId' => $transactionId,
        'patient' => $outboundPatientRecords,
        'matchedBy' => $matchedBy,
        'response' => [
            'requestId' => $requestId
        ]
    ];

    if (!$outboundPatientRecords) {
        /*
         * Guarded rather than dispatched. An empty patient array is what a
         * "no match" looks like on the wire, and sending it would tell the
         * gateway a patient exists with no records, which is different from
         * saying nothing was found.
         */
        error_log('ABDM ON-DISCOVER | outbound aborted | reason=no_patient_records');

        return [
            'success' => false,
            'message' => 'No patient care contexts available to announce.'
        ];
    }

    $url = ABDM_M2_BASE_URL . '/hiecm/user-initiated-linking/v3/patient/care-context/on-discover';

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $auth['access_token'],
            'REQUEST-ID: ' . $apiRequestId,
            'TIMESTAMP: ' . $timestamp,
            'X-CM-ID: ' . ABDM_M2_CM_ID
        ],
        CURLOPT_POSTFIELDS => json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
        CURLOPT_TIMEOUT => ABDM_M2_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($response === false || $curlError !== '') {
        error_log(
            'ABDM ON-DISCOVER | transport failure | request_id=' . $apiRequestId .
            ' | curl_error=' . preg_replace('/[^A-Za-z0-9 ._\/-]/', '', $curlError)
        );

        return [
            'success' => false,
            'http_code' => $httpCode,
            'message' => 'cURL error.',
            'error' => $curlError,
            'request_id' => $apiRequestId
        ];
    }

    $data = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        error_log(
            'ABDM ON-DISCOVER | accepted | request_id=' . $apiRequestId .
            ' | http_code=' . $httpCode .
            ' | patient_groups=' . count($outboundPatientRecords) .
            ' | matched_by=' . ($matchedBy === [] ? 'NONE' : implode('+', $matchedBy))
        );

        return [
            'success' => true,
            'http_code' => $httpCode,
            'message' => 'User-initiated discovery response sent successfully.',
            'request_id' => $apiRequestId,
            'response' => $data ?: $response
        ];
    }

    /*
     * Gateway rejected the notification. The status and the gateway's own error
     * text are logged because they are the only diagnostic for a rejected
     * notification, and neither contains patient data.
     *
     * The outbound payload is NOT returned. The previous version attached
     * debug.payload here, and the caller stores this whole structure in
     * abdm_user_linking_transactions.response_payload (a longtext column), so
     * every rejected discovery retained the patient's UHID, full name and every
     * care-context reference indefinitely. That is the same retention defect the
     * P0 pass removed from the inbound body. It is still available for
     * development behind the diagnostic flag, which defaults off.
     */
    error_log(
        'ABDM ON-DISCOVER | rejected | request_id=' . $apiRequestId .
        ' | http_code=' . $httpCode .
        ' | gateway_error=' . preg_replace('/[^A-Za-z0-9 ._\/-]/', '',
            (string)($data['error'] ?? $data['message'] ?? 'none')) .
        ' | cm_id=' . ABDM_M2_CM_ID
    );

    $failure = [
        'success' => false,
        'http_code' => $httpCode,
        'message' => 'User-initiated discovery response failed.',
        'request_id' => $apiRequestId,
        'response' => $data ?: $response
    ];

    if (ABDM_DIAGNOSTIC_LOG_ENABLED) {
        $failure['debug'] = [
            'url' => $url,
            'cm_id' => ABDM_M2_CM_ID,
            'payload' => $payload
        ];
    }

    return $failure;
}
?>