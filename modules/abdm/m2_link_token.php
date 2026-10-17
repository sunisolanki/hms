<?php
require_once __DIR__ . '/m2_auth.php';

function abdmM2GenerateLinkToken($patientId)
{
    global $conn;

    /*
     * GLOBAL ABDM SWITCH
     *
     * No link token is generated, and no gateway request is made, while ABDM is
     * off or while the setting cannot be read.
     */
    if (!abdmOutboundAllowed($conn)) {
        return abdmDisabledResponse();
    }

    $patientId = (int)$patientId;

    if ($patientId <= 0) {
        return [
            'success' => false,
            'message' => 'Valid patient_id is required.'
        ];
    }

    $stmt = $conn->prepare("
        SELECT
            p.id,
            p.first_name,
            p.last_name,
            p.sex,
            p.dob,
            p.abha_id,
            l.abha_number,
            l.abha_address
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
    $abhaNumber = preg_replace('/[^0-9]/', '', trim($patient['abha_number'] ?? ''));

    if ($abhaAddress === '' && $abhaNumber === '') {
        $abhaId = trim($patient['abha_id'] ?? '');

        if ($abhaId !== '') {
            if (strpos($abhaId, '@') !== false) {
                $abhaAddress = $abhaId;
            } else {
                $abhaNumber = preg_replace('/[^0-9]/', '', $abhaId);
            }
        }
    }

    if ($abhaAddress === '' && $abhaNumber === '') {
        return [
            'success' => false,
            'message' => 'Patient does not have an ABHA Address or ABHA Number.'
        ];
    }

    $name = trim(
        ($patient['first_name'] ?? '') . ' ' .
        ($patient['last_name'] ?? '')
    );

    if ($name === '') {
        return [
            'success' => false,
            'message' => 'Patient name is missing.'
        ];
    }

    $sex = strtoupper(trim($patient['sex'] ?? ''));

    if ($sex === 'MALE' || $sex === 'M') {
        $gender = 'M';
    } elseif ($sex === 'FEMALE' || $sex === 'F') {
        $gender = 'F';
    } else {
        $gender = 'O';
    }

    if (empty($patient['dob'])) {
        return [
            'success' => false,
            'message' => 'Patient date of birth is missing.'
        ];
    }

    $yearOfBirth = date('Y', strtotime($patient['dob']));

    $auth = abdmM2GetAccessToken();

    if (!$auth['success']) {
        return [
            'success' => false,
            'message' => 'M2 authentication failed.',
            'details' => $auth
        ];
    }

    $settingsStmt = $conn->prepare("
        SELECT hip_id, bridge_id
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

    if (!$m2Settings) {
        return [
            'success' => false,
            'message' => 'Active M2 settings not found.'
        ];
    }

    $hipId = trim($m2Settings['hip_id'] ?? '');

    if ($hipId === '') {
        return [
            'success' => false,
            'message' => 'HIP ID is missing in M2 settings.'
        ];
    }

    $requestId = abdmM2GenerateRequestId();
    $timestamp = gmdate('Y-m-d\TH:i:s.v\Z');

    $payload = [
        'name' => $name,
        'gender' => $gender,
        'yearOfBirth' => $yearOfBirth
    ];

    if ($abhaAddress !== '') {
        $payload['abhaAddress'] = $abhaAddress;
    } else {
        $payload['abhaNumber'] = $abhaNumber;
    }

    $url = ABDM_M2_BASE_URL . '/hiecm/v3/token/generate-token';

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
            'X-CM-ID: ' . ABDM_M2_CM_ID
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
        return [
            'success' => false,
            'http_code' => $httpCode,
            'message' => 'cURL error.',
            'error' => $curlError
        ];
    }

    $data = json_decode($response, true);

return [
    'success' => $httpCode >= 200 && $httpCode < 300,
    'http_code' => $httpCode,
    'request_id' => $requestId,
    'debug' => [
        'url' => $url,
        'payload' => $payload,
        'hip_id' => $hipId,
        'cm_id' => ABDM_M2_CM_ID
    ],
    'response' => $data ?: $response
];
}
?>