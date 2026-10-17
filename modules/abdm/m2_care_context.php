<?php
require_once __DIR__ . '/m2_auth.php';

function abdmM2LinkCareContext($patientId, $careContextReference, $careContextDisplay, $hiType = 'PRESCRIPTION')
{
    global $conn;

    /*
     * GLOBAL ABDM SWITCH
     *
     * This is the automatic care-context link performed during prescription
     * save. When ABDM is off, or the setting cannot be read, it returns the
     * library's standard failure shape so that prescription saving continues
     * normally and simply performs no external ABDM operation.
     */
    if (!abdmOutboundAllowed($conn)) {
        return abdmDisabledResponse();
    }

    $patientId = (int)$patientId;
    $careContextReference = trim($careContextReference);
    $careContextDisplay = trim($careContextDisplay);
    $hiType = trim($hiType);
    if ($patientId <= 0) {
        return [
            'success' => false,
            'message' => 'Valid patient_id is required.'
        ];
    }
    if ($careContextReference === '') {
        return [
            'success' => false,
            'message' => 'Care context reference is required.'
        ];
    }
    if ($careContextDisplay === '') {
        return [
            'success' => false,
            'message' => 'Care context display is required.'
        ];
    }
    $allowedHiTypes = [
        'PRESCRIPTION',
        'DIAGNOSTIC_REPORT',
        'OPCONSULTATION',
        'DISCHARGESUMMARY',
        'IMMUNIZATIONRECORD',
        'HEALTHDOCUMENTRECORD',
        'WELLNESSRECORD'
    ];
    if (!in_array($hiType, $allowedHiTypes, true)) {
        return [
            'success' => false,
            'message' => 'Invalid health information type.'
        ];
    }
    $apiHiTypeMap = [
    'PRESCRIPTION' => 'Prescription',
    'DIAGNOSTIC_REPORT' => 'DiagnosticReport',
    'OPCONSULTATION' => 'OPConsultation',
    'DISCHARGESUMMARY' => 'DischargeSummary',
    'IMMUNIZATIONRECORD' => 'ImmunizationRecord',
    'HEALTHDOCUMENTRECORD' => 'HealthDocumentRecord',
    'WELLNESSRECORD' => 'WellnessRecord',
    'INVOICE' => 'Invoice'
];

$apiHiType = $apiHiTypeMap[$hiType] ?? $hiType;

    $stmt = $conn->prepare("
        SELECT
            p.id,
            p.first_name,
            p.last_name,
            l.abha_number,
            l.abha_address,
            l.linking_token
        FROM patient_master p
        LEFT JOIN abdm_patient_links l ON l.patient_id = p.id
        WHERE p.id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return [
            'success' => false,
            'message' => 'Unable to prepare patient query.'
        ];
    }
    $stmt->bind_param("i", $patientId);
    $stmt->execute();
    $result = $stmt->get_result();
    $patient = $result->fetch_assoc();
    $stmt->close();
    if (!$patient) {
        return [
            'success' => false,
            'message' => 'Patient not found.'
        ];
    }
    $abhaAddress = trim($patient['abha_address'] ?? '');
    $abhaNumber = preg_replace('/[^0-9]/', '', $patient['abha_number'] ?? '');
    $linkingToken = trim($patient['linking_token'] ?? '');
    if ($abhaAddress === '') {
        return [
            'success' => false,
            'message' => 'Patient ABHA Address is missing.'
        ];
    }
    if ($linkingToken === '') {
        return [
            'success' => false,
            'message' => 'Patient linking token is missing.'
        ];
    }
    $auth = abdmM2GetAccessToken();
    if (empty($auth['success'])) {
        return [
            'success' => false,
            'message' => 'M2 authentication failed.',
            'details' => $auth
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
        return [
            'success' => false,
            'message' => 'Unable to prepare M2 settings query.'
        ];
    }
    $settingsStmt->execute();
    $settingsResult = $settingsStmt->get_result();
    $m2Settings = $settingsResult->fetch_assoc();
    $settingsStmt->close();
    if (!$m2Settings || empty($m2Settings['hip_id'])) {
        return [
            'success' => false,
            'message' => 'HIP ID is missing in M2 settings.'
        ];
    }
    $hipId = trim($m2Settings['hip_id']);
    $stmt = $conn->prepare("
        INSERT INTO abdm_care_contexts
        (
            patient_id,
            care_context_reference,
            care_context_display,
            care_context_type,
            link_status
        )
        VALUES (?, ?, ?, ?, 'Pending')
        ON DUPLICATE KEY UPDATE
            care_context_display = VALUES(care_context_display),
            care_context_type = VALUES(care_context_type),
            link_status = 'Pending',
            updated_at = CURRENT_TIMESTAMP
    ");
    if (!$stmt) {
        return [
            'success' => false,
            'message' => 'Unable to prepare care context save.'
        ];
    }
    $stmt->bind_param(
        "isss",
        $patientId,
        $careContextReference,
        $careContextDisplay,
        $hiType
    );
    if (!$stmt->execute()) {
        $stmt->close();
        return [
            'success' => false,
            'message' => 'Failed to save care context.'
        ];
    }
    $stmt->close();
    $requestId = abdmM2GenerateRequestId();
    $timestamp = gmdate('Y-m-d\TH:i:s.v\Z');
    $patientDisplay = trim(
        ($patient['first_name'] ?? '') . ' ' .
        ($patient['last_name'] ?? '')
    );
    if ($patientDisplay === '') {
        $patientDisplay = (string)$patientId;
    }
    $payload = [
    'abhaAddress' => $abhaAddress,
        'patient' => [
            [
                'referenceNumber' => (string)$patientId,
                'display' => $patientDisplay,
                'careContexts' => [
                    [
                        'referenceNumber' => $careContextReference,
                        'display' => $careContextDisplay
                    ]
                ],
                'hiType' => $apiHiType,
                'count' => 1
            ]
        ]
    ];
    $url = ABDM_M2_BASE_URL . '/hiecm/hip/v3/link/carecontext';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $auth['access_token'],
            'REQUEST-ID: ' . $requestId,
            'TIMESTAMP: ' . $timestamp,
            'X-HIP-ID: ' . $hipId,
            'X-CM-ID: ' . ABDM_M2_CM_ID,
            'X-LINK-TOKEN: ' . $linkingToken
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
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
        $stmt = $conn->prepare("
            UPDATE abdm_care_contexts
            SET link_status = 'Failed',
                updated_at = CURRENT_TIMESTAMP
            WHERE patient_id = ?
              AND care_context_reference = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("is", $patientId, $careContextReference);
            $stmt->execute();
            $stmt->close();
        }
        return [
            'success' => false,
            'http_code' => $httpCode,
            'message' => 'cURL error.',
            'error' => $curlError,
            'request_id' => $requestId
        ];
    }
    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300) {
        $stmt = $conn->prepare("
            UPDATE abdm_care_contexts
            SET link_status = 'Linked',
                linked_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE patient_id = ?
              AND care_context_reference = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("is", $patientId, $careContextReference);
            $stmt->execute();
            $stmt->close();
        }
        return [
            'success' => true,
            'http_code' => $httpCode,
            'message' => 'Care context linked successfully.',
            'request_id' => $requestId,
            'response' => $data ?: $response
        ];
    }
    $stmt = $conn->prepare("
        UPDATE abdm_care_contexts
        SET link_status = 'Failed',
            updated_at = CURRENT_TIMESTAMP
        WHERE patient_id = ?
          AND care_context_reference = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("is", $patientId, $careContextReference);
        $stmt->execute();
        $stmt->close();
    }
    return [
        'success' => false,
        'http_code' => $httpCode,
        'message' => 'Care context linking failed.',
        'request_id' => $requestId,
        'debug' => [
            'url' => $url,
            'hip_id' => $hipId,
            'cm_id' => ABDM_M2_CM_ID,
            'payload' => $payload
        ],
        'response' => $data ?: $response
    ];
}
?>