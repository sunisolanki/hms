<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../../../../includes/db.php';

function responseJson($success, $message, $data = [])
{
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

function generateRequestId()
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

if (!isset($_SESSION['user_id'])) {
    responseJson(false, 'Unauthorized access.');
}

$hospitalId = isset($_GET['hospital_id']) ? (int)$_GET['hospital_id'] : 0;
$patientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
$careContextReference = isset($_GET['care_context_reference'])
    ? trim($_GET['care_context_reference'])
    : '';

if ($hospitalId <= 0) {
    responseJson(false, 'Hospital ID is required.');
}

if ($patientId <= 0) {
    responseJson(false, 'Patient ID is required.');
}

if ($careContextReference === '') {
    responseJson(false, 'Care context reference is required.');
}

/*
 * ---------------------------------------------------------
 * M3 SETTINGS
 * ---------------------------------------------------------
 */

$settingsStmt = $conn->prepare("
    SELECT *
    FROM abdm_m3_settings
    WHERE hospital_id = ?
      AND environment = 'Sandbox'
      AND status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if (!$settingsStmt) {
    responseJson(false, 'Database prepare failed: ' . $conn->error);
}

$settingsStmt->bind_param("i", $hospitalId);
$settingsStmt->execute();

$settingsResult = $settingsStmt->get_result();
$settings = $settingsResult ? $settingsResult->fetch_assoc() : null;

$settingsStmt->close();

if (!$settings) {
    responseJson(false, 'Active Sandbox M3 settings not found for this hospital.');
}

$hiuId = trim((string)$settings['hiu_id']);
$facilityId = trim((string)$settings['facility_id']);

if ($hiuId === '') {
    responseJson(false, 'HIU ID is missing in M3 settings.');
}

if ($facilityId === '') {
    responseJson(false, 'Facility ID is missing in M3 settings.');
}

/*
 * ---------------------------------------------------------
 * PATIENT
 * ---------------------------------------------------------
 */

$patientStmt = $conn->prepare("
    SELECT
        id,
        uhid,
        first_name,
        last_name
    FROM patient_master
    WHERE id = ?
    LIMIT 1
");

if (!$patientStmt) {
    responseJson(false, 'Patient query prepare failed: ' . $conn->error);
}

$patientStmt->bind_param("i", $patientId);
$patientStmt->execute();

$patientResult = $patientStmt->get_result();
$patient = $patientResult ? $patientResult->fetch_assoc() : null;

$patientStmt->close();

if (!$patient) {
    responseJson(false, 'Patient not found.');
}

/*
 * ---------------------------------------------------------
 * ABHA LINK
 * ---------------------------------------------------------
 */

$abhaStmt = $conn->prepare("
    SELECT
        abha_number,
        abha_address,
        verification_status
    FROM abdm_patient_links
    WHERE patient_id = ?
    LIMIT 1
");

if (!$abhaStmt) {
    responseJson(false, 'ABHA query prepare failed: ' . $conn->error);
}

$abhaStmt->bind_param("i", $patientId);
$abhaStmt->execute();

$abhaResult = $abhaStmt->get_result();
$abha = $abhaResult ? $abhaResult->fetch_assoc() : null;

$abhaStmt->close();

if (!$abha) {
    responseJson(false, 'ABDM patient link not found for this patient.');
}

$abhaAddress = trim((string)$abha['abha_address']);

if ($abhaAddress === '') {
    responseJson(false, 'ABHA address is missing for this patient.');
}

/*
 * ---------------------------------------------------------
 * CARE CONTEXT
 * ---------------------------------------------------------
 */

$careStmt = $conn->prepare("
    SELECT
        id,
        care_context_reference,
        care_context_display,
        care_context_type,
        link_status
    FROM abdm_care_contexts
    WHERE patient_id = ?
      AND care_context_reference = ?
    LIMIT 1
");

if (!$careStmt) {
    responseJson(false, 'Care context query prepare failed: ' . $conn->error);
}

$careStmt->bind_param("is", $patientId, $careContextReference);
$careStmt->execute();

$careResult = $careStmt->get_result();
$careContext = $careResult ? $careResult->fetch_assoc() : null;

$careStmt->close();

if (!$careContext) {
    responseJson(false, 'Care context not found for this patient.');
}

if ($careContext['link_status'] !== 'Linked') {
    responseJson(false, 'Care context is not in Linked status.');
}

/*
 * ---------------------------------------------------------
 * ACTIVE M3 SESSION
 * ---------------------------------------------------------
 */

$sessionStmt = $conn->prepare("
    SELECT
        id,
        access_token,
        token_type,
        expires_at
    FROM abdm_m3_sessions
    WHERE hospital_id = ?
      AND environment = 'Sandbox'
      AND status = 'Active'
      AND expires_at > NOW()
    ORDER BY id DESC
    LIMIT 1
");

if (!$sessionStmt) {
    responseJson(false, 'Session query prepare failed: ' . $conn->error);
}

$sessionStmt->bind_param("i", $hospitalId);
$sessionStmt->execute();

$sessionResult = $sessionStmt->get_result();
$sessionData = $sessionResult ? $sessionResult->fetch_assoc() : null;

$sessionStmt->close();

if (!$sessionData) {
    responseJson(false, 'No active ABDM M3 session found. Please authenticate with ABDM first.');
}

$accessToken = trim((string)$sessionData['access_token']);
$tokenType = trim((string)$sessionData['token_type']);

if ($accessToken === '') {
    responseJson(false, 'ABDM access token is missing.');
}

if ($tokenType === '') {
    $tokenType = 'bearer';
}

$authorization = ucfirst(strtolower($tokenType)) . ' ' . $accessToken;

/*
 * ---------------------------------------------------------
 * M3 CONSENT REQUEST VALUES
 * ---------------------------------------------------------
 *
 * These are Sandbox test values.
 *
 * CAREMGT = Care Management
 * OPConsultation = documented M3 HI type
 * VIEW = documented access mode
 *
 * The requester values below follow the example values
 * shown in the M3 documentation for the technical Sandbox
 * test. They should later be replaced with the actual
 * requester identity used by HMS.
 */

$purposeText = 'Care Management';
$purposeCode = 'CAREMGT';
$purposeRefUri = 'www.test.com';

$requesterName = 'Smith';
$requesterIdentifierType = 'REGN01';
$requesterIdentifierValue = 'MH1001';
$requesterIdentifierSystem = 'https://www.mciindia.org';

$careContextType = trim((string)($careContext['care_context_type'] ?? ''));

if ($careContextType === '') {
    responseJson(false, 'Care context type is missing.');
}

$careContextTypeMap = [
    'OPCONSULTATION' => 'OPConsultation',
    'PRESCRIPTION' => 'Prescription',
    'DIAGNOSTICREPORT' => 'DiagnosticReport',
    'DISCHARGESUMMARY' => 'DischargeSummary',
    'IMMUNIZATIONRECORD' => 'ImmunizationRecord',
    'HEALTHDOCUMENTRECORD' => 'HealthDocumentRecord',
    'WELLNESSRECORD' => 'WellnessRecord',
    'INVOICE' => 'Invoice'
];

$careContextTypeKey = strtoupper(
    str_replace([' ', '_', '-'], '', $careContextType)
);

$careContextType = $careContextTypeMap[$careContextTypeKey] ?? $careContextType;

$hiTypes = [
    $careContextType
];

$accessMode = 'VIEW';

/*
 * Use the actual HMS patient UHID as the patient reference.
 * Care context reference comes directly from
 * abdm_care_contexts.
 */

$patientReference = (string)$patient['uhid'];

$permissionDateFrom = gmdate('Y-m-d\TH:i:s.v\Z', strtotime('-60 days'));
$permissionDateTo = gmdate('Y-m-d\TH:i:s.v\Z');
$permissionDataEraseAt = gmdate('Y-m-d\TH:i:s.v\Z', strtotime('+90 days'));

/*
 * M3 documentation example uses:
 * unit = HOUR
 * value = 0
 * repeats = 0
 */

$frequencyUnit = 'HOUR';
$frequencyValue = 0;
$frequencyRepeats = 0;

/*
 * ---------------------------------------------------------
 * REQUEST ID / TIMESTAMP
 * ---------------------------------------------------------
 */

$requestId = generateRequestId();
$timestamp = gmdate('Y-m-d\TH:i:s.v\Z');

/*
 * ---------------------------------------------------------
 * M3 CONSENT REQUEST BODY
 * ---------------------------------------------------------
 *
 * Structure based on M3 v2.6 documentation:
 *
 * consent
 *   purpose
 *   patient
 *   hip
 *   careContexts
 *   hiu
 *   requester
 *   hiTypes
 *   permission
 */

$payload = [
    'consent' => [
        'purpose' => [
            'text' => $purposeText,
            'code' => $purposeCode,
            'refUri' => $purposeRefUri
        ],
        'patient' => [
            'id' => $abhaAddress
        ],
        'hip' => [
            'id' => $facilityId
        ],
        'careContexts' => [
            [
                'patientReference' => $patientReference,
                'careContextReference' => $careContextReference
            ]
        ],
        'hiu' => [
            'id' => $hiuId
        ],
        'requester' => [
            'name' => $requesterName,
            'identifier' => [
                'type' => $requesterIdentifierType,
                'value' => $requesterIdentifierValue,
                'system' => $requesterIdentifierSystem
            ]
        ],
        'hiTypes' => $hiTypes,
        'permission' => [
            'accessMode' => $accessMode,
            'dateRange' => [
                'from' => $permissionDateFrom,
                'to' => $permissionDateTo
            ],
            'dataEraseAt' => $permissionDataEraseAt,
            'frequency' => [
                'unit' => $frequencyUnit,
                'value' => $frequencyValue,
                'repeats' => $frequencyRepeats
            ]
        ]
    ]
];

$payloadJson = json_encode(
    $payload,
    JSON_UNESCAPED_SLASHES
);

if ($payloadJson === false) {
    responseJson(false, 'Unable to create ABDM consent request payload.');
}

/*
 * ---------------------------------------------------------
 * ABDM API
 * ---------------------------------------------------------
 */

$baseUrl = 'https://dev.abdm.gov.in';
$cmId = 'sbx';

$url = $baseUrl . '/api/hiecm/consent/v3/request/init';

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: ' . $authorization,
        'REQUEST-ID: ' . $requestId,
        'TIMESTAMP: ' . $timestamp,
        'X-CM-ID: ' . $cmId
    ],
    CURLOPT_POSTFIELDS => $payloadJson,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($response === false || $curlError !== '') {
    $responseForDb = json_encode([
        'curl_error' => $curlError
    ], JSON_UNESCAPED_SLASHES);

    $status = 'Failed';
    $createdBy = (int)$_SESSION['user_id'];
    $hiTypesJson = json_encode($hiTypes);

    $saveStmt = $conn->prepare("
        INSERT INTO abdm_m3_consent_requests
        (
            hospital_id,
            patient_id,
            abha_address,
            hip_id,
            hip_name,
            hiu_id,
            purpose_text,
            purpose_code,
            purpose_ref_uri,
            patient_reference,
            care_context_reference,
            requester_name,
            requester_identifier_type,
            requester_identifier_value,
            requester_identifier_system,
            hi_types,
            access_mode,
            permission_date_from,
            permission_date_to,
            permission_data_erase_at,
            frequency_value,
            frequency_repeats,
            frequency_unit,
            request_id,
            abdm_status_code,
            status,
            abdm_response,
            created_by
        )
        VALUES
        (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
    ");

    if ($saveStmt) {
        $saveStmt->bind_param(
            "iissssssssssssssssssiisssissi",
            $hospitalId,
            $patientId,
            $abhaAddress,
            $facilityId,
            $facilityId,
            $hiuId,
            $purposeText,
            $purposeCode,
            $purposeRefUri,
            $patientReference,
            $careContextReference,
            $requesterName,
            $requesterIdentifierType,
            $requesterIdentifierValue,
            $requesterIdentifierSystem,
            $hiTypesJson,
            $accessMode,
            $permissionDateFrom,
            $permissionDateTo,
            $permissionDataEraseAt,
            $frequencyValue,
            $frequencyRepeats,
            $frequencyUnit,
            $requestId,
            $httpCode,
            $status,
            $responseForDb,
            $createdBy
        );

        $saveStmt->execute();
        $saveStmt->close();
    }

    responseJson(false, 'ABDM connection failed.', [
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'request_id' => $requestId
    ]);
}

/*
 * ABDM may return an empty body with 202.
 */

$responseData = [];

if (trim($response) !== '') {
    $decodedResponse = json_decode($response, true);

    if (is_array($decodedResponse)) {
        $responseData = $decodedResponse;
    } else {
        $responseData = [
            'raw_response' => $response
        ];
    }
}

/*
 * ---------------------------------------------------------
 * DETERMINE STATUS
 * ---------------------------------------------------------
 */

if ($httpCode >= 200 && $httpCode < 300) {
    $status = 'Accepted';
} else {
    $status = 'Failed';
}

/*
 * Consent Request ID may be returned by ABDM or later
 * through the on-init callback.
 */

$consentRequestId = '';

if (isset($responseData['consentRequest']['id'])) {
    $consentRequestId = (string)$responseData['consentRequest']['id'];
} elseif (isset($responseData['consentRequestId'])) {
    $consentRequestId = (string)$responseData['consentRequestId'];
} elseif (isset($responseData['id'])) {
    $consentRequestId = (string)$responseData['id'];
}

$hiTypesJson = json_encode($hiTypes);

$abdmResponseJson = json_encode(
    $responseData,
    JSON_UNESCAPED_SLASHES
);

$createdBy = (int)$_SESSION['user_id'];

/*
 * ---------------------------------------------------------
 * SAVE CONSENT REQUEST
 * ---------------------------------------------------------
 */

$saveStmt = $conn->prepare("
    INSERT INTO abdm_m3_consent_requests
    (
        hospital_id,
        patient_id,
        abha_address,
        hip_id,
        hip_name,
        hiu_id,
        purpose_text,
        purpose_code,
        purpose_ref_uri,
        patient_reference,
        care_context_reference,
        requester_name,
        requester_identifier_type,
        requester_identifier_value,
        requester_identifier_system,
        hi_types,
        access_mode,
        permission_date_from,
        permission_date_to,
        permission_data_erase_at,
        frequency_value,
        frequency_repeats,
        frequency_unit,
        request_id,
        consent_request_id,
        abdm_status_code,
        status,
        abdm_response,
        created_by
    )
    VALUES
    (
        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
    )
");

if (!$saveStmt) {
    responseJson(false, 'Consent request database prepare failed: ' . $conn->error, [
        'request_id' => $requestId,
        'http_code' => $httpCode
    ]);
}

$saveStmt->bind_param(
    "iissssssssssssssssssiisssissi",
    $hospitalId,
    $patientId,
    $abhaAddress,
    $facilityId,
    $facilityId,
    $hiuId,
    $purposeText,
    $purposeCode,
    $purposeRefUri,
    $patientReference,
    $careContextReference,
    $requesterName,
    $requesterIdentifierType,
    $requesterIdentifierValue,
    $requesterIdentifierSystem,
    $hiTypesJson,
    $accessMode,
    $permissionDateFrom,
    $permissionDateTo,
    $permissionDataEraseAt,
    $frequencyValue,
    $frequencyRepeats,
    $frequencyUnit,
    $requestId,
    $consentRequestId,
    $httpCode,
    $status,
    $abdmResponseJson,
    $createdBy
);

if (!$saveStmt->execute()) {
    $saveError = $saveStmt->error;
    $saveStmt->close();

    responseJson(false, 'Consent request was sent to ABDM but could not be saved locally.', [
        'http_code' => $httpCode,
        'request_id' => $requestId,
        'save_error' => $saveError
    ]);
}

$localId = $saveStmt->insert_id;

$saveStmt->close();

/*
 * ---------------------------------------------------------
 * ABDM ERROR
 * ---------------------------------------------------------
 */

if ($httpCode < 200 || $httpCode >= 300) {
    responseJson(false, 'ABDM consent request failed.', [
        'local_id' => $localId,
        'http_code' => $httpCode,
        'request_id' => $requestId,
        'abdm_response' => $responseData
    ]);
}

/*
 * ---------------------------------------------------------
 * SUCCESS
 * ---------------------------------------------------------
 */

responseJson(true, 'ABDM consent request initiated successfully.', [
    'local_id' => $localId,
    'http_code' => $httpCode,
    'request_id' => $requestId,
    'consent_request_id' => $consentRequestId,
    'hospital_id' => $hospitalId,
    'patient_id' => $patientId,
    'abha_address' => $abhaAddress,
    'care_context_reference' => $careContextReference,
    'hiu_id' => $hiuId,
    'hip_id' => $facilityId,
    'environment' => 'Sandbox',
    'abdm_response' => $responseData
]);