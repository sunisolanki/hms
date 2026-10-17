<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE |
        JSON_PRETTY_PRINT
    );
    exit;
}

function failValidation(string $check, string $message, array $details = []): void
{
    jsonResponse([
        'success' => false,
        'result' => 'FAIL',
        'check' => $check,
        'message' => $message,
        'details' => $details
    ], 400);
}

function cleanString(mixed $value): string
{
    return trim((string)$value);
}

function resourceType(array $resource): string
{
    return cleanString($resource['resourceType'] ?? '');
}

function resourceId(array $resource): string
{
    return cleanString($resource['id'] ?? '');
}

function getProfileUrls(array $resource): array
{
    $profiles = $resource['meta']['profile'] ?? [];

    if (!is_array($profiles)) {
        return [];
    }

    return array_values(
        array_filter(
            array_map(
                static fn($value): string => trim((string)$value),
                $profiles
            ),
            static fn(string $value): bool => $value !== ''
        )
    );
}

function referenceId(string $reference): ?string
{
    $reference = trim($reference);

    if ($reference === '') {
        return null;
    }

    if (str_starts_with($reference, 'urn:uuid:')) {
        return substr($reference, 9);
    }

    if (preg_match('#^[A-Za-z]+/([^/]+)$#', $reference, $matches)) {
        return $matches[1];
    }

    return null;
}

function findResourceByFullUrl(array $resources, string $reference): ?array
{
    foreach ($resources as $item) {
        if (($item['fullUrl'] ?? '') === $reference) {
            return $item['resource'] ?? null;
        }
    }

    return null;
}

function findResourceById(array $resources, string $reference): ?array
{
    $id = referenceId($reference);

    if ($id === null) {
        return null;
    }

    foreach ($resources as $item) {
        $resource = $item['resource'] ?? null;

        if (!is_array($resource)) {
            continue;
        }

        if (resourceId($resource) === $id) {
            return $resource;
        }
    }

    return null;
}

function resolveReference(array $resources, string $reference): ?array
{
    $resource = findResourceByFullUrl($resources, $reference);

    if ($resource !== null) {
        return $resource;
    }

    return findResourceById($resources, $reference);
}

function findFirstResource(array $resources, string $type): ?array
{
    foreach ($resources as $item) {
        $resource = $item['resource'] ?? null;

        if (!is_array($resource)) {
            continue;
        }

        if (resourceType($resource) === $type) {
            return $resource;
        }
    }

    return null;
}

function findAllResources(array $resources, string $type): array
{
    $result = [];

    foreach ($resources as $item) {
        $resource = $item['resource'] ?? null;

        if (!is_array($resource)) {
            continue;
        }

        if (resourceType($resource) === $type) {
            $result[] = $resource;
        }
    }

    return $result;
}

function check(bool $condition, string $name, string $message, array &$checks): void
{
    $checks[] = [
        'name' => $name,
        'status' => $condition ? 'PASS' : 'FAIL',
        'message' => $message
    ];
}

function allChecksPassed(array $checks): bool
{
    foreach ($checks as $check) {
        if (($check['status'] ?? '') !== 'PASS') {
            return false;
        }
    }

    return true;
}

try {
    /*
     * -------------------------------------------------------------
     * INPUT
     * -------------------------------------------------------------
     */

    $appointmentId = (int)(
        $_GET['appointment_id'] ??
        $_POST['appointment_id'] ??
        0
    );

    if ($appointmentId <= 0) {
        failValidation(
            'INPUT',
            'Valid appointment_id is required.'
        );
    }

    /*
     * -------------------------------------------------------------
     * LOAD DYNAMIC FHIR BUNDLE
     * -------------------------------------------------------------
     *
     * We call the existing dynamic FHIR generator.
     * No database records are changed.
     */

    $generatorUrl =
        'https://' .
        ($_SERVER['HTTP_HOST'] ?? 'hms.websplend.com') .
        '/modules/abdm/m3/m3_opd_consultation_fhir.php' .
        '?appointment_id=' .
        rawurlencode((string)$appointmentId);

   $ch = curl_init($generatorUrl);

if ($ch === false) {
    failValidation(
        'FHIR_GENERATOR',
        'Unable to initialize FHIR generator request.'
    );
}
    if (empty($_SESSION['tenant_id'])) {
        failValidation(
            'SESSION',
            'HMS tenant session is not available. Please open this validator while logged in to the HMS.',
            [
                'session_name' => session_name(),
                'session_id_present' => session_id() !== '',
                'tenant_id_present' => false
            ]
        );
    }

    $sessionCookie = session_name() . '=' . session_id();

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    curl_setopt_array(
        $ch,
        [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Cookie: ' . $sessionCookie
            ]
        ]
    );

    $generatorResponse = curl_exec($ch);
    $generatorCurlError = curl_error($ch);
    $generatorHttpStatus = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    if (
        $generatorResponse === false ||
        $generatorCurlError !== ''
    ) {
        failValidation(
            'FHIR_GENERATOR',
            'Dynamic FHIR generator request failed.',
            [
                'curl_error' => $generatorCurlError,
                'http_status' => $generatorHttpStatus
            ]
        );
    }

    $generatorData = json_decode(
        $generatorResponse,
        true
    );

    if (!is_array($generatorData)) {
        failValidation(
            'FHIR_GENERATOR_JSON',
            'Dynamic FHIR generator did not return valid JSON.',
            [
                'http_status' => $generatorHttpStatus
            ]
        );
    }

    if (
        isset($generatorData['success']) &&
        $generatorData['success'] === false
    ) {
        failValidation(
            'FHIR_GENERATOR',
            'Dynamic FHIR generator returned an error.',
            [
                'generator_response' => $generatorData
            ]
        );
    }

    /*
     * -------------------------------------------------------------
     * LOCATE BUNDLE
     * -------------------------------------------------------------
     */

    $bundle = null;

    if (
        isset($generatorData['fhir_bundle']) &&
        is_array($generatorData['fhir_bundle'])
    ) {
        $bundle = $generatorData['fhir_bundle'];
    } elseif (
        isset($generatorData['bundle']) &&
        is_array($generatorData['bundle'])
    ) {
        $bundle = $generatorData['bundle'];
    } elseif (
        isset($generatorData['resourceType']) &&
        $generatorData['resourceType'] === 'Bundle'
    ) {
        $bundle = $generatorData;
    }

    if (!is_array($bundle)) {
        failValidation(
            'FHIR_BUNDLE',
            'Dynamic FHIR generator response does not contain a Bundle.',
            [
                'response_keys' => array_keys($generatorData)
            ]
        );
    }

    /*
     * -------------------------------------------------------------
     * BASIC BUNDLE VALIDATION
     * -------------------------------------------------------------
     */

    $checks = [];

    check(
        ($bundle['resourceType'] ?? '') === 'Bundle',
        'RESOURCE_TYPE',
        'FHIR resourceType must be Bundle.',
        $checks
    );

    check(
        ($bundle['type'] ?? '') === 'document',
        'BUNDLE_TYPE',
        'FHIR Bundle type must be document.',
        $checks
    );

    $entries = $bundle['entry'] ?? [];

    check(
        is_array($entries) && count($entries) > 0,
        'ENTRY_COUNT',
        'FHIR document Bundle must contain at least one entry.',
        $checks
    );

    if (!is_array($entries) || count($entries) === 0) {
        jsonResponse([
            'success' => false,
            'result' => 'FAIL',
            'appointment_id' => $appointmentId,
            'checks' => $checks
        ], 400);
    }

    /*
     * -------------------------------------------------------------
     * BUILD RESOURCE INDEX
     * -------------------------------------------------------------
     */

    $resources = [];

    foreach ($entries as $index => $entry) {
        if (!is_array($entry)) {
            check(
                false,
                'ENTRY_' . $index,
                'Bundle entry must be an object.',
                $checks
            );
            continue;
        }

        if (!isset($entry['resource']) || !is_array($entry['resource'])) {
            check(
                false,
                'ENTRY_RESOURCE_' . $index,
                'Bundle entry does not contain a valid resource.',
                $checks
            );
            continue;
        }

        $resources[] = $entry;
    }

    /*
     * -------------------------------------------------------------
     * REQUIRED DOCUMENT RESOURCES
     * -------------------------------------------------------------
     */

    $composition = findFirstResource(
        $resources,
        'Composition'
    );

    $patient = findFirstResource(
        $resources,
        'Patient'
    );

    $practitioner = findFirstResource(
        $resources,
        'Practitioner'
    );

    $organization = findFirstResource(
        $resources,
        'Organization'
    );

    $encounter = findFirstResource(
        $resources,
        'Encounter'
    );

    check(
        $composition !== null,
        'COMPOSITION',
        'Composition resource is present.',
        $checks
    );

    check(
        $patient !== null,
        'PATIENT',
        'Patient resource is present.',
        $checks
    );

    check(
        $practitioner !== null,
        'PRACTITIONER',
        'Practitioner resource is present.',
        $checks
    );

    check(
        $organization !== null,
        'ORGANIZATION',
        'Organization resource is present.',
        $checks
    );

    check(
        $encounter !== null,
        'ENCOUNTER',
        'Encounter resource is present.',
        $checks
    );

    /*
     * -------------------------------------------------------------
     * DOCUMENT BUNDLE PROFILE
     * -------------------------------------------------------------
     */

    $bundleProfiles = getProfileUrls($bundle);

    $hasDocumentBundleProfile = false;

    foreach ($bundleProfiles as $profile) {
        if (
            str_contains(
                strtolower($profile),
                'documentbundle'
            )
        ) {
            $hasDocumentBundleProfile = true;
            break;
        }
    }

    check(
        $hasDocumentBundleProfile,
        'DOCUMENT_BUNDLE_PROFILE',
        $hasDocumentBundleProfile
            ? 'DocumentBundle profile is present.'
            : 'DocumentBundle profile is missing.',
        $checks
    );

    /*
     * -------------------------------------------------------------
     * COMPOSITION PROFILE
     * -------------------------------------------------------------
     */

    if ($composition !== null) {
        $compositionProfiles = getProfileUrls(
            $composition
        );

        $hasOpConsultProfile = false;

        foreach ($compositionProfiles as $profile) {
            if (
                str_contains(
                    strtolower($profile),
                    'opconsultrecord'
                )
            ) {
                $hasOpConsultProfile = true;
                break;
            }
        }

        check(
            $hasOpConsultProfile,
            'OPCONSULT_RECORD_PROFILE',
            $hasOpConsultProfile
                ? 'OPConsultRecord profile is present.'
                : 'OPConsultRecord profile is missing.',
            $checks
        );
    }

    /*
     * -------------------------------------------------------------
     * COMPOSITION SUBJECT
     * -------------------------------------------------------------
     */

    if ($composition !== null) {
        $subjectReference =
            $composition['subject']['reference'] ?? '';

        $subjectResource = resolveReference(
            $resources,
            (string)$subjectReference
        );

        check(
            $subjectResource !== null &&
            resourceType($subjectResource) === 'Patient',
            'COMPOSITION_SUBJECT',
            'Composition subject resolves to Patient.',
            $checks
        );
    }

    /*
     * -------------------------------------------------------------
     * COMPOSITION AUTHOR
     * -------------------------------------------------------------
     */

    if ($composition !== null) {
        $authors =
            $composition['author'] ?? [];

        $authorPassed = false;

        if (is_array($authors)) {
            foreach ($authors as $author) {
                if (!is_array($author)) {
                    continue;
                }

                $reference =
                    $author['reference'] ?? '';

                $resource = resolveReference(
                    $resources,
                    (string)$reference
                );

                if (
                    $resource !== null &&
                    resourceType($resource) === 'Practitioner'
                ) {
                    $authorPassed = true;
                    break;
                }
            }
        }

        check(
            $authorPassed,
            'COMPOSITION_AUTHOR',
            $authorPassed
                ? 'Composition author resolves to Practitioner.'
                : 'Composition author does not resolve to Practitioner.',
            $checks
        );
    }

    /*
     * -------------------------------------------------------------
     * COMPOSITION ENCOUNTER
     * -------------------------------------------------------------
     */

    if ($composition !== null) {
        $encounterReference =
            $composition['encounter']['reference'] ?? '';

        $encounterResource = resolveReference(
            $resources,
            (string)$encounterReference
        );

        check(
            $encounterResource !== null &&
            resourceType($encounterResource) === 'Encounter',
            'COMPOSITION_ENCOUNTER',
            $encounterResource !== null
                ? 'Composition encounter resolves to Encounter.'
                : 'Composition encounter reference is broken.',
            $checks
        );
    }

    /*
     * -------------------------------------------------------------
     * PATIENT IDENTIFIER
     * -------------------------------------------------------------
     */

    if ($patient !== null) {
        $patientId = resourceId($patient);

        check(
            $patientId !== '',
            'PATIENT_ID',
            $patientId !== ''
                ? 'Patient has an id.'
                : 'Patient id is missing.',
            $checks
        );

        check(
            isset($patient['identifier']) &&
            is_array($patient['identifier']) &&
            count($patient['identifier']) > 0,
            'PATIENT_IDENTIFIER',
            'Patient has at least one identifier.',
            $checks
        );
    }

    /*
     * -------------------------------------------------------------
     * PRACTITIONER
     * -------------------------------------------------------------
     */

    if ($practitioner !== null) {
        check(
            resourceId($practitioner) !== '',
            'PRACTITIONER_ID',
            resourceId($practitioner) !== ''
                ? 'Practitioner has an id.'
                : 'Practitioner id is missing.',
            $checks
        );
    }

    /*
     * -------------------------------------------------------------
     * ORGANIZATION
     * -------------------------------------------------------------
     */

    if ($organization !== null) {
        check(
            resourceId($organization) !== '',
            'ORGANIZATION_ID',
            resourceId($organization) !== ''
                ? 'Organization has an id.'
                : 'Organization id is missing.',
            $checks
        );
    }

    /*
     * -------------------------------------------------------------
     * ENCOUNTER
     * -------------------------------------------------------------
     */

    if ($encounter !== null) {
        $encounterId = resourceId($encounter);

        check(
            $encounterId !== '',
            'ENCOUNTER_ID',
            $encounterId !== ''
                ? 'Encounter has an id.'
                : 'Encounter id is missing.',
            $checks
        );

        $encounterIdentifiers =
            $encounter['identifier'] ?? [];

        $encounterIdentifierValue = '';

        if (is_array($encounterIdentifiers)) {
            foreach ($encounterIdentifiers as $identifier) {
                if (!is_array($identifier)) {
                    continue;
                }

                $value = cleanString(
                    $identifier['value'] ?? ''
                );

                if ($value !== '') {
                    $encounterIdentifierValue = $value;
                    break;
                }
            }
        }

        check(
            $encounterIdentifierValue !== '',
            'ENCOUNTER_IDENTIFIER',
            $encounterIdentifierValue !== ''
                ? 'Encounter identifier is populated.'
                : 'Encounter identifier is empty.',
            $checks
        );

        $encounterSubject =
            $encounter['subject']['reference'] ?? '';

        $encounterPatient = resolveReference(
            $resources,
            (string)$encounterSubject
        );

        check(
            $encounterPatient !== null &&
            resourceType($encounterPatient) === 'Patient',
            'ENCOUNTER_PATIENT',
            $encounterPatient !== null
                ? 'Encounter subject resolves to Patient.'
                : 'Encounter subject reference is broken.',
            $checks
        );
    }

    /*
     * -------------------------------------------------------------
     * COMPOSITION SECTION REFERENCES
     * -------------------------------------------------------------
     */

    $sectionReferenceFailures = [];

    if ($composition !== null) {
        $sections =
            $composition['section'] ?? [];

        if (is_array($sections)) {
            foreach ($sections as $sectionIndex => $section) {
                if (!is_array($section)) {
                    continue;
                }

                $sectionEntries =
                    $section['entry'] ?? [];

                if (!is_array($sectionEntries)) {
                    continue;
                }

                foreach ($sectionEntries as $entryIndex => $entryReference) {
                    if (!is_array($entryReference)) {
                        continue;
                    }

                    $reference =
                        (string)($entryReference['reference'] ?? '');

                    if ($reference === '') {
                        $sectionReferenceFailures[] =
                            "section[$sectionIndex].entry[$entryIndex] reference missing";
                        continue;
                    }

                    $resolved = resolveReference(
                        $resources,
                        $reference
                    );

                    if ($resolved === null) {
                        $sectionReferenceFailures[] =
                            "section[$sectionIndex].entry[$entryIndex] -> {$reference}";
                    }
                }
            }
        }
    }

    check(
        count($sectionReferenceFailures) === 0,
        'SECTION_REFERENCES',
        count($sectionReferenceFailures) === 0
            ? 'All Composition section references resolve.'
            : 'One or more Composition section references are broken.',
        $checks
    );

    /*
     * -------------------------------------------------------------
     * CLINICAL RESOURCE REFERENCES
     * -------------------------------------------------------------
     */

    $clinicalResources = [];

    foreach (
        [
            'Condition',
            'Observation',
            'MedicationRequest',
            'ServiceRequest',
            'Procedure',
            'DiagnosticReport'
        ] as $type
    ) {
        foreach (
            findAllResources($resources, $type)
            as $resource
        ) {
            $clinicalResources[] = $resource;
        }
    }

    $clinicalReferenceFailures = [];

    foreach ($clinicalResources as $resource) {
        $type = resourceType($resource);
        $id = resourceId($resource);

        if (isset($resource['subject']['reference'])) {
            $subjectReference =
                (string)$resource['subject']['reference'];

            $subject = resolveReference(
                $resources,
                $subjectReference
            );

            if (
                $subject === null ||
                resourceType($subject) !== 'Patient'
            ) {
                $clinicalReferenceFailures[] =
                    "{$type}/{$id}: invalid Patient subject reference";
            }
        }

        if (isset($resource['encounter']['reference'])) {
            $encounterReference =
                (string)$resource['encounter']['reference'];

            $clinicalEncounter = resolveReference(
                $resources,
                $encounterReference
            );

            if (
                $clinicalEncounter === null ||
                resourceType($clinicalEncounter) !== 'Encounter'
            ) {
                $clinicalReferenceFailures[] =
                    "{$type}/{$id}: invalid Encounter reference";
            }
        }
    }

    check(
        count($clinicalReferenceFailures) === 0,
        'CLINICAL_REFERENCES',
        count($clinicalReferenceFailures) === 0
            ? 'Clinical resource references resolve correctly.'
            : 'One or more clinical resource references are invalid.',
        $checks
    );

    /*
     * -------------------------------------------------------------
     * ZERO-VALUE VITAL VALIDATION
     * -------------------------------------------------------------
     */

    $zeroVitalObservations = [];

    $observations = findAllResources(
        $resources,
        'Observation'
    );

    foreach ($observations as $observation) {
        $codeText = '';

        if (
            isset($observation['code']['text'])
        ) {
            $codeText = strtolower(
                cleanString(
                    $observation['code']['text']
                )
            );
        }

        if ($codeText === '') {
            continue;
        }

        $vitalNames = [
            'weight',
            'height',
            'bmi',
            'pain scale'
        ];

        $isVital = false;

        foreach ($vitalNames as $vitalName) {
            if (str_contains($codeText, $vitalName)) {
                $isVital = true;
                break;
            }
        }

        if (!$isVital) {
            continue;
        }

        $value =
            $observation['valueQuantity']['value'] ??
            null;

        if (
            $value !== null &&
            is_numeric($value) &&
            (float)$value <= 0
        ) {
            $zeroVitalObservations[] = [
                'id' => resourceId($observation),
                'code' => $codeText,
                'value' => $value
            ];
        }
    }

    check(
        count($zeroVitalObservations) === 0,
        'ZERO_VITALS',
        count($zeroVitalObservations) === 0
            ? 'No zero-valued vital observations were exported.'
            : 'Zero-valued vital observations were found.',
        $checks
    );

    /*
     * -------------------------------------------------------------
     * RESOURCE SUMMARY
     * -------------------------------------------------------------
     */

    $resourceSummary = [];

    foreach ($resources as $entry) {
        $resource = $entry['resource'] ?? [];

        if (!is_array($resource)) {
            continue;
        }

        $type = resourceType($resource);

        if ($type === '') {
            continue;
        }

        if (!isset($resourceSummary[$type])) {
            $resourceSummary[$type] = 0;
        }

        $resourceSummary[$type]++;
    }

    /*
     * -------------------------------------------------------------
     * FINAL RESULT
     * -------------------------------------------------------------
     */

    $passed = allChecksPassed($checks);

    jsonResponse([
        'success' => $passed,
        'result' => $passed ? 'PASS' : 'FAIL',
        'message' => $passed
            ? 'Dynamic OP Consultation FHIR Bundle validation passed.'
            : 'Dynamic OP Consultation FHIR Bundle validation failed.',
        'appointment_id' => $appointmentId,
        'generator_http_status' => $generatorHttpStatus,
        'bundle_id' => $bundle['id'] ?? null,
        'bundle_type' => $bundle['type'] ?? null,
        'entry_count' => count($entries),
        'resource_summary' => $resourceSummary,
        'patient_id' => $patient['id'] ?? null,
        'practitioner_id' => $practitioner['id'] ?? null,
        'organization_id' => $organization['id'] ?? null,
        'encounter_id' => $encounter['id'] ?? null,
        'encounter_identifier' =>
            $encounterIdentifierValue ?? '',
        'zero_vital_observations' =>
            $zeroVitalObservations,
        'section_reference_failures' =>
            $sectionReferenceFailures,
        'clinical_reference_failures' =>
            $clinicalReferenceFailures,
        'checks' => $checks
    ], $passed ? 200 : 422);

} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'result' => 'FAIL',
        'message' => 'FHIR validation failed unexpectedly.',
        'error' => $e->getMessage()
    ], 500);
}
?>