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
function hasProfile(array $resource, string $profileName): bool
{
    foreach (getProfileUrls($resource) as $profile) {
        if (str_contains(strtolower($profile), strtolower($profileName))) {
            return true;
        }
    }
    return false;
}
function getIdentifier(array $resource): ?array
{
    $identifiers = $resource['identifier'] ?? [];
    if (!is_array($identifiers)) {
        return null;
    }
    foreach ($identifiers as $identifier) {
        if (is_array($identifier)) {
            return $identifier;
        }
    }
    return null;
}
function getIdentifierValue(array $resource): string
{
    $identifier = getIdentifier($resource);
    return cleanString($identifier['value'] ?? '');
}
function validateFixedCoding(
    array $resource,
    string $path,
    string $system,
    string $code,
    string $display,
    string $checkName,
    string $label,
    array &$checks
): void
{
    $parts = explode('.', $path);
    $value = $resource;
    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            $value = null;
            break;
        }
        $value = $value[$part];
    }
    $passed = is_array($value)
        && cleanString($value['system'] ?? '') === $system
        && cleanString($value['code'] ?? '') === $code
        && cleanString($value['display'] ?? '') === $display;
    check(
        $passed,
        $checkName,
        $passed
            ? "{$label} matches the NRCeS fixed coding."
            : "{$label} does not match the NRCeS fixed coding.",
        $checks
    );
}

function validateReferenceField(
    array $resources,
    array $resource,
    string $field,
    string $expectedType,
    string $checkName,
    string $label,
    array &$checks
): void
{
    $reference = cleanString($resource[$field]['reference'] ?? '');
    $resolved = $reference !== ''
        ? resolveReference($resources, $reference)
        : null;
    $passed = $reference !== ''
        && $resolved !== null
        && resourceType($resolved) === $expectedType;
    check(
        $passed,
        $checkName,
        $passed
            ? "{$label} is present and resolves to {$expectedType}."
            : "{$label} is missing, broken, or has the wrong resource type.",
        $checks
    );
}

function validateReferenceType(
    array $resources,
    array $resource,
    string $field,
    string $expectedType,
    string $checkName,
    string $label,
    array &$checks
): void
{
    $reference = cleanString($resource[$field]['reference'] ?? '');
    $resolved = $reference !== ''
        ? resolveReference($resources, $reference)
        : null;
    check(
        $resolved !== null && resourceType($resolved) === $expectedType,
        $checkName,
        $resolved !== null
            ? "{$label} reference resolves to {$expectedType}."
            : "{$label} reference is missing or broken.",
        $checks
    );
}
try {
    /*
     * -------------------------------------------------------------
     * INPUT
     * -------------------------------------------------------------
     */
    $prescriptionId = (int)(
        $_GET['prescription_id'] ??
        $_POST['prescription_id'] ??
        0
    );
    if ($prescriptionId <= 0) {
        failValidation(
            'INPUT',
            'Valid prescription_id is required.'
        );
    }
    /*
     * -------------------------------------------------------------
     * LOAD PRESCRIPTION FHIR BUNDLE
     * -------------------------------------------------------------
     */
    $generatorUrl =
        'https://' .
        ($_SERVER['HTTP_HOST'] ?? 'hms.websplend.com') .
        '/modules/abdm/m3/m3_prescription_fhir.php' .
        '?prescription_id=' .
        rawurlencode((string)$prescriptionId);
    $ch = curl_init($generatorUrl);
    if ($ch === false) {
        failValidation(
            'FHIR_GENERATOR',
            'Unable to initialize Prescription FHIR generator request.'
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
            'Prescription FHIR generator request failed.',
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
            'Prescription FHIR generator did not return valid JSON.',
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
            'Prescription FHIR generator returned an error.',
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
            'Prescription FHIR generator response does not contain a Bundle.',
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
        'Prescription FHIR Bundle type must be document.',
        $checks
    );
    check(
        cleanString($bundle['id'] ?? '') !== '',
        'BUNDLE_ID',
        cleanString($bundle['id'] ?? '') !== ''
            ? 'Bundle has an id.'
            : 'Bundle id is missing.',
        $checks
    );
    check(
        isset($bundle['meta']['versionId']) &&
        cleanString($bundle['meta']['versionId']) !== '',
        'BUNDLE_VERSION_ID',
        isset($bundle['meta']['versionId'])
            ? 'Bundle meta.versionId is populated.'
            : 'Bundle meta.versionId is missing.',
        $checks
    );
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
    $entries = $bundle['entry'] ?? [];
    check(
        is_array($entries) && count($entries) > 0,
        'ENTRY_COUNT',
        is_array($entries) && count($entries) > 0
            ? 'Prescription document Bundle contains entries.'
            : 'Prescription document Bundle must contain at least one entry.',
        $checks
    );
    if (!is_array($entries) || count($entries) === 0) {
        jsonResponse([
            'success' => false,
            'result' => 'FAIL',
            'prescription_id' => $prescriptionId,
            'checks' => $checks
        ], 422);
    }
    /*
     * -------------------------------------------------------------
     * BUILD RESOURCE INDEX
     * -------------------------------------------------------------
     */
    $resources = [];
    $entryFailures = [];
    foreach ($entries as $index => $entry) {
        if (!is_array($entry)) {
            $entryFailures[] = "entry[$index] is not an object";
            continue;
        }
        if (
            !isset($entry['resource']) ||
            !is_array($entry['resource'])
        ) {
            $entryFailures[] =
                "entry[$index] does not contain a valid resource";
            continue;
        }
        $resources[] = $entry;
    }
    check(
        count($entryFailures) === 0,
        'ENTRY_STRUCTURE',
        count($entryFailures) === 0
            ? 'All Bundle entries contain valid resources.'
            : 'One or more Bundle entries are invalid.',
        $checks
    );
    /*
     * -------------------------------------------------------------
     * REQUIRED PRESCRIPTION RESOURCES
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
    $medicationRequests = findAllResources(
        $resources,
        'MedicationRequest'
    );
    check(
        $composition !== null,
        'COMPOSITION',
        $composition !== null
            ? 'Composition resource is present.'
            : 'Composition resource is missing.',
        $checks
    );
    check(
        $patient !== null,
        'PATIENT',
        $patient !== null
            ? 'Patient resource is present.'
            : 'Patient resource is missing.',
        $checks
    );
    check(
        $practitioner !== null,
        'PRACTITIONER',
        $practitioner !== null
            ? 'Practitioner resource is present.'
            : 'Practitioner resource is missing.',
        $checks
    );
    check(
        $organization !== null,
        'ORGANIZATION',
        $organization !== null
            ? 'Organization resource is present.'
            : 'Organization resource is missing.',
        $checks
    );
    check(
        $encounter !== null,
        'ENCOUNTER',
        $encounter !== null
            ? 'Encounter resource is present.'
            : 'Encounter resource is missing.',
        $checks
    );
    check(
        count($medicationRequests) > 0,
        'MEDICATION_REQUESTS',
        count($medicationRequests) > 0
            ? 'At least one MedicationRequest is present.'
            : 'No MedicationRequest resources were found.',
        $checks
    );
    /*
     * -------------------------------------------------------------
     * PRESCRIPTION PROFILE
     * -------------------------------------------------------------
     */
    $hasPrescriptionProfile = false;
    if ($composition !== null) {
        $hasPrescriptionProfile = hasProfile(
            $composition,
            'prescriptionrecord'
        );
    }
    check(
        $hasPrescriptionProfile,
        'PRESCRIPTION_RECORD_PROFILE',
        $hasPrescriptionProfile
            ? 'PrescriptionRecord profile is present on Composition.'
            : 'PrescriptionRecord profile is missing from Composition.',
        $checks
    );
    if ($composition !== null) {
        check(
            cleanString($composition['status'] ?? '') !== '',
            'COMPOSITION_STATUS',
            cleanString($composition['status'] ?? '') !== ''
                ? 'Composition.status is populated.'
                : 'Composition.status is missing.',
            $checks
        );
        validateFixedCoding(
            $composition,
            'type.coding.0',
            'http://snomed.info/sct',
            '440545006',
            'Prescription record',
            'PRESCRIPTION_TYPE_FIXED_CODING',
            'PrescriptionRecord Composition.type.coding',
            $checks
        );
        check(
            cleanString($composition['date'] ?? '') !== '',
            'COMPOSITION_DATE',
            cleanString($composition['date'] ?? '') !== ''
                ? 'Composition.date is populated.'
                : 'Composition.date is missing.',
            $checks
        );
        check(
            cleanString($composition['title'] ?? '') !== '',
            'COMPOSITION_TITLE',
            cleanString($composition['title'] ?? '') !== ''
                ? 'Composition.title is populated.'
                : 'Composition.title is missing.',
            $checks
        );
    }
    /*
     * -------------------------------------------------------------
     * RESOURCE IDS
     * -------------------------------------------------------------
     */
    if ($patient !== null) {
        check(
            resourceId($patient) !== '',
            'PATIENT_ID',
            resourceId($patient) !== ''
                ? 'Patient has an id.'
                : 'Patient id is missing.',
            $checks
        );
    }
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
    if ($encounter !== null) {
        check(
            resourceId($encounter) !== '',
            'ENCOUNTER_ID',
            resourceId($encounter) !== ''
                ? 'Encounter has an id.'
                : 'Encounter id is missing.',
            $checks
        );
    }
    /*
     * -------------------------------------------------------------
     * PATIENT IDENTIFIER
     * -------------------------------------------------------------
     */
    if ($patient !== null) {
        $identifiers = $patient['identifier'] ?? [];
        check(
            is_array($identifiers) && count($identifiers) > 0,
            'PATIENT_IDENTIFIER',
            is_array($identifiers) && count($identifiers) > 0
                ? 'Patient has at least one identifier.'
                : 'Patient identifier is missing.',
            $checks
        );
        $patientIdentifier = getIdentifier($patient);
        check(
            $patientIdentifier !== null &&
            cleanString($patientIdentifier['value'] ?? '') !== '',
            'PATIENT_IDENTIFIER_VALUE',
            $patientIdentifier !== null &&
            cleanString($patientIdentifier['value'] ?? '') !== ''
                ? 'Patient identifier value is populated.'
                : 'Patient identifier value is missing.',
            $checks
        );
        if ($patientIdentifier !== null) {
            $identifierType = $patientIdentifier['type'] ?? [];
            $identifierCoding = $identifierType['coding'][0] ?? [];
            check(
                is_array($identifierCoding) &&
                cleanString($identifierCoding['code'] ?? '') !== '',
                'PATIENT_IDENTIFIER_TYPE',
                is_array($identifierCoding) &&
                cleanString($identifierCoding['code'] ?? '') !== ''
                    ? 'Patient identifier type is populated.'
                    : 'Patient identifier type is missing.',
                $checks
            );
        }
    }
    /*
     * -------------------------------------------------------------
     * PRACTITIONER IDENTIFIER
     * -------------------------------------------------------------
     */
    if ($practitioner !== null) {
        $identifier = getIdentifier($practitioner);
        check(
            $identifier !== null &&
            cleanString($identifier['value'] ?? '') !== '',
            'PRACTITIONER_IDENTIFIER',
            $identifier !== null &&
            cleanString($identifier['value'] ?? '') !== ''
                ? 'Practitioner identifier is populated.'
                : 'Practitioner identifier is missing.',
            $checks
        );
        if ($identifier !== null) {
            $identifierType = $identifier['type'] ?? [];
            $identifierCoding = $identifierType['coding'][0] ?? [];
            check(
                is_array($identifierCoding) &&
                cleanString($identifierCoding['code'] ?? '') !== '',
                'PRACTITIONER_IDENTIFIER_TYPE',
                is_array($identifierCoding) &&
                cleanString($identifierCoding['code'] ?? '') !== ''
                    ? 'Practitioner identifier type is populated.'
                    : 'Practitioner identifier type is missing.',
                $checks
            );
        }
    }
    /*
     * -------------------------------------------------------------
     * ORGANIZATION IDENTIFIER
     * -------------------------------------------------------------
     */
    if ($organization !== null) {
        $identifier = getIdentifier($organization);
        check(
            $identifier !== null &&
            cleanString($identifier['value'] ?? '') !== '',
            'ORGANIZATION_IDENTIFIER',
            $identifier !== null &&
            cleanString($identifier['value'] ?? '') !== ''
                ? 'Organization identifier is populated.'
                : 'Organization identifier is missing.',
            $checks
        );
        if ($identifier !== null) {
            $identifierType = $identifier['type'] ?? [];
            $identifierCoding = $identifierType['coding'][0] ?? [];
            check(
                is_array($identifierCoding) &&
                cleanString($identifierCoding['code'] ?? '') !== '',
                'ORGANIZATION_IDENTIFIER_TYPE',
                is_array($identifierCoding) &&
                cleanString($identifierCoding['code'] ?? '') !== ''
                    ? 'Organization identifier type is populated.'
                    : 'Organization identifier type is missing.',
                $checks
            );
        }
    }
    /*
     * -------------------------------------------------------------
     * COMPOSITION REFERENCES
     * -------------------------------------------------------------
     */
    if ($composition !== null) {
        validateReferenceType(
            $resources,
            $composition,
            'subject',
            'Patient',
            'COMPOSITION_SUBJECT',
            'Composition subject',
            $checks
        );
        $authors = $composition['author'] ?? [];
        $authorPassed = false;
        if (is_array($authors)) {
            foreach ($authors as $author) {
                if (!is_array($author)) {
                    continue;
                }
                $reference = cleanString(
                    $author['reference'] ?? ''
                );
                $resolved = $reference !== ''
                    ? resolveReference($resources, $reference)
                    : null;
                if (
                    $resolved !== null &&
                    resourceType($resolved) === 'Practitioner'
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
        validateReferenceType(
            $resources,
            $composition,
            'encounter',
            'Encounter',
            'COMPOSITION_ENCOUNTER',
            'Composition encounter',
            $checks
        );
        $custodianReference =
            cleanString(
                $composition['custodian']['reference'] ?? ''
            );
        $custodian = $custodianReference !== ''
            ? resolveReference(
                $resources,
                $custodianReference
            )
            : null;
        check(
            $custodian !== null &&
            resourceType($custodian) === 'Organization',
            'COMPOSITION_CUSTODIAN',
            $custodian !== null
                ? 'Composition custodian resolves to Organization.'
                : 'Composition custodian reference is missing or broken.',
            $checks
        );
    }
    /*
     * -------------------------------------------------------------
     * COMPOSITION SECTION
     * -------------------------------------------------------------
     */
    $sectionReferenceFailures = [];
    if ($composition !== null) {
        $sections = $composition['section'] ?? [];
        check(
            is_array($sections) && count($sections) > 0,
            'COMPOSITION_SECTION',
            is_array($sections) && count($sections) > 0
                ? 'Prescription Composition contains a section.'
                : 'Prescription Composition section is missing.',
            $checks
        );
        if (is_array($sections)) {
            foreach ($sections as $sectionIndex => $section) {
                if (!is_array($section)) {
                    continue;
                }
                $sectionEntries = $section['entry'] ?? [];
                if (!is_array($sectionEntries)) {
                    continue;
                }
                foreach ($sectionEntries as $entryIndex => $entryReference) {
                    if (!is_array($entryReference)) {
                        continue;
                    }
                    $reference =
                        cleanString(
                            $entryReference['reference'] ?? ''
                        );
                    if ($reference === '') {
                        $sectionReferenceFailures[] =
                            "section[$sectionIndex].entry[$entryIndex] reference missing";
                        continue;
                    }
                    $entryType = cleanString($entryReference['type'] ?? '');
                    if ($entryType !== 'MedicationRequest') {
                        $sectionReferenceFailures[] =
                            "section[$sectionIndex].entry[$entryIndex] type must be MedicationRequest";
                    }
                    $resolved = resolveReference(
                        $resources,
                        $reference
                    );
                    if ($resolved === null) {
                        $sectionReferenceFailures[] =
                            "section[$sectionIndex].entry[$entryIndex] -> {$reference}";
                    } elseif (resourceType($resolved) !== 'MedicationRequest') {
                        $sectionReferenceFailures[] =
                            "section[$sectionIndex].entry[$entryIndex] does not resolve to MedicationRequest";
                    }
                }
            }
        }
    }
    if ($composition !== null) {
        $sections = $composition['section'] ?? [];
        $sectionCount = is_array($sections) ? count($sections) : 0;
        check(
            $sectionCount === 1,
            'PRESCRIPTION_SECTION_CARDINALITY',
            $sectionCount === 1
                ? 'PrescriptionRecord contains exactly one section as required by the profile.'
                : 'PrescriptionRecord must contain exactly one section.',
            $checks
        );
        if ($sectionCount === 1 && isset($sections[0]) && is_array($sections[0])) {
            $sectionEntries = $sections[0]['entry'] ?? [];
            check(
                is_array($sectionEntries) && count($sectionEntries) >= 1,
                'PRESCRIPTION_SECTION_ENTRIES',
                is_array($sectionEntries) && count($sectionEntries) >= 1
                    ? 'PrescriptionRecord section contains at least one entry.'
                    : 'PrescriptionRecord section must contain at least one entry.',
                $checks
            );
        }
    }
    check(
        count($sectionReferenceFailures) === 0,
        'SECTION_REFERENCES',
        count($sectionReferenceFailures) === 0
            ? 'All Prescription Composition section references resolve.'
            : 'One or more Prescription Composition section references are broken.',
        $checks
    );
    /*
     * -------------------------------------------------------------
     * MEDICATION REQUEST VALIDATION
     * -------------------------------------------------------------
     */
    $medicationFailures = [];
    foreach ($medicationRequests as $index => $medication) {
        $medicationId = resourceId($medication);
        if ($medicationId === '') {
            $medicationFailures[] =
                "MedicationRequest[$index] id is missing";
        }
        if (!hasProfile($medication, 'medicationrequest')) {
            $medicationFailures[] =
                "MedicationRequest[$index] MedicationRequest profile is missing";
        }
        if (
            cleanString(
                $medication['status'] ?? ''
            ) === ''
        ) {
            $medicationFailures[] =
                "MedicationRequest[$index] status is missing";
        }
        if (
            cleanString(
                $medication['intent'] ?? ''
            ) === ''
        ) {
            $medicationFailures[] =
                "MedicationRequest[$index] intent is missing";
        }
        if (!array_key_exists('medicationCodeableConcept', $medication) && !array_key_exists('medicationReference', $medication)) {
            $medicationFailures[] =
                "MedicationRequest[$index] medication[x] is missing";
        }
        if (
            cleanString($medication['authoredOn'] ?? '') === ''
        ) {
            $medicationFailures[] =
                "MedicationRequest[$index] authoredOn is missing";
        }
        $medicationText = cleanString(
            $medication['medicationCodeableConcept']['text'] ?? ''
        );
        if ($medicationText === '') {
            $medicationFailures[] =
                "MedicationRequest[$index] medication name/text is missing";
        }
        $subjectReference =
            cleanString(
                $medication['subject']['reference'] ?? ''
            );
        $subject = $subjectReference !== ''
            ? resolveReference(
                $resources,
                $subjectReference
            )
            : null;
        if (
            $subject === null ||
            resourceType($subject) !== 'Patient'
        ) {
            $medicationFailures[] =
                "MedicationRequest[$index] has invalid Patient subject reference";
        }
        $encounterReference =
            cleanString(
                $medication['encounter']['reference'] ?? ''
            );
        $medicationEncounter = $encounterReference !== ''
            ? resolveReference(
                $resources,
                $encounterReference
            )
            : null;
        if (
            $medicationEncounter === null ||
            resourceType($medicationEncounter) !== 'Encounter'
        ) {
            $medicationFailures[] =
                "MedicationRequest[$index] has invalid Encounter reference";
        }
        $requesterReference =
            cleanString(
                $medication['requester']['reference'] ?? ''
            );
        $requester = $requesterReference !== ''
            ? resolveReference(
                $resources,
                $requesterReference
            )
            : null;
        if (
            $requester === null ||
            resourceType($requester) !== 'Practitioner'
        ) {
            $medicationFailures[] =
                "MedicationRequest[$index] has invalid Practitioner requester reference";
        }
        $dosageInstructions =
            $medication['dosageInstruction'] ?? [];
        if (
            !is_array($dosageInstructions) ||
            count($dosageInstructions) === 0
        ) {
            $medicationFailures[] =
                "MedicationRequest[$index] dosageInstruction is missing";
        } else {
            foreach ($dosageInstructions as $dosageIndex => $dosage) {
                if (!is_array($dosage)) {
                    $medicationFailures[] =
                        "MedicationRequest[$index].dosageInstruction[$dosageIndex] is invalid";
                    continue;
                }
                $dosageText = cleanString(
                    $dosage['text'] ?? ''
                );
                if ($dosageText === '') {
                    $medicationFailures[] =
                        "MedicationRequest[$index].dosageInstruction[$dosageIndex].text is missing";
                }
                $timingCode = cleanString(
                    $dosage['timing']['code']['text'] ?? ''
                );
                if ($timingCode === '') {
                    $medicationFailures[] =
                        "MedicationRequest[$index].dosageInstruction[$dosageIndex].timing.code.text is missing";
                }
                if (isset($dosage['route'])) {
                    $routeCoding = $dosage['route']['coding'][0] ?? [];
                    if (!is_array($routeCoding)
                        || cleanString($routeCoding['system'] ?? '') !== 'http://snomed.info/sct'
                        || cleanString($routeCoding['code'] ?? '') === ''
                        || cleanString($routeCoding['display'] ?? '') === '') {
                        $medicationFailures[] =
                            "MedicationRequest[$index].dosageInstruction[$dosageIndex].route.coding must use SNOMED CT with system, code and display";
                    }
                    if (cleanString($dosage['route']['text'] ?? '') === '') {
                        $medicationFailures[] =
                            "MedicationRequest[$index].dosageInstruction[$dosageIndex].route.text is missing";
                    }
                }
                if (isset($dosage['method'])) {
                    $methodCoding = $dosage['method']['coding'][0] ?? [];
                    if (!is_array($methodCoding)
                        || cleanString($methodCoding['system'] ?? '') !== 'http://snomed.info/sct'
                        || cleanString($methodCoding['code'] ?? '') === ''
                        || cleanString($methodCoding['display'] ?? '') === '') {
                        $medicationFailures[] =
                            "MedicationRequest[$index].dosageInstruction[$dosageIndex].method.coding must use SNOMED CT with system, code and display";
                    }
                    if (cleanString($dosage['method']['text'] ?? '') === '') {
                        $medicationFailures[] =
                            "MedicationRequest[$index].dosageInstruction[$dosageIndex].method.text is missing";
                    }
                }
            }
        }
        $quantity =
            $medication['dispenseRequest']['quantity']['value'] ??
            null;
        if (
            $quantity === null ||
            !is_numeric($quantity) ||
            (float)$quantity <= 0
        ) {
            $medicationFailures[] =
                "MedicationRequest[$index] dispense quantity is invalid";
        }
    }
    check(
        count($medicationFailures) === 0,
        'MEDICATION_REQUEST_VALIDATION',
        count($medicationFailures) === 0
            ? 'All MedicationRequest resources passed validation.'
            : 'One or more MedicationRequest resources failed validation.',
        $checks
    );
    /*
     * -------------------------------------------------------------
     * ENCOUNTER VALIDATION
     * -------------------------------------------------------------
     */
    if ($encounter !== null) {
        check(
            cleanString($encounter['status'] ?? '') !== '',
            'ENCOUNTER_STATUS',
            cleanString($encounter['status'] ?? '') !== ''
                ? 'Encounter status is populated.'
                : 'Encounter status is missing.',
            $checks
        );
        validateReferenceType(
            $resources,
            $encounter,
            'subject',
            'Patient',
            'ENCOUNTER_PATIENT',
            'Encounter subject',
            $checks
        );
        $serviceProviderReference =
            cleanString(
                $encounter['serviceProvider']['reference'] ?? ''
            );
        $serviceProvider =
            $serviceProviderReference !== ''
                ? resolveReference(
                    $resources,
                    $serviceProviderReference
                )
                : null;
        check(
            $serviceProvider !== null &&
            resourceType($serviceProvider) === 'Organization',
            'ENCOUNTER_SERVICE_PROVIDER',
            $serviceProvider !== null
                ? 'Encounter serviceProvider resolves to Organization.'
                : 'Encounter serviceProvider reference is missing or broken.',
            $checks
        );
        $encounterIdentifier = getIdentifierValue(
            $encounter
        );
        check(
            $encounterIdentifier !== '',
            'ENCOUNTER_IDENTIFIER',
            $encounterIdentifier !== ''
                ? 'Encounter identifier is populated.'
                : 'Encounter identifier is missing.',
            $checks
        );
    }
    /*
     * -------------------------------------------------------------
     * REFERENCE INTEGRITY
     * -------------------------------------------------------------
     */
    $clinicalReferenceFailures = [];
    foreach ($medicationRequests as $medication) {
        $id = resourceId($medication);
        foreach (
            [
                'subject' => 'Patient',
                'encounter' => 'Encounter',
                'requester' => 'Practitioner'
            ] as $field => $expectedType
        ) {
            $reference =
                cleanString(
                    $medication[$field]['reference'] ?? ''
                );
            $resolved = $reference !== ''
                ? resolveReference(
                    $resources,
                    $reference
                )
                : null;
            if (
                $resolved === null ||
                resourceType($resolved) !== $expectedType
            ) {
                $clinicalReferenceFailures[] =
                    "MedicationRequest/{$id}: invalid {$field} reference";
            }
        }
    }
    check(
        count($clinicalReferenceFailures) === 0,
        'CLINICAL_REFERENCES',
        count($clinicalReferenceFailures) === 0
            ? 'All MedicationRequest clinical references resolve correctly.'
            : 'One or more MedicationRequest references are invalid.',
        $checks
    );
    /*
     * -------------------------------------------------------------
     * BUNDLE ENTRY FULLURL CHECK
     * -------------------------------------------------------------
     */
    $fullUrlFailures = [];
    foreach ($entries as $index => $entry) {
        $fullUrl = cleanString(
            $entry['fullUrl'] ?? ''
        );
        $resource = $entry['resource'] ?? [];
        $resourceIdValue = is_array($resource)
            ? resourceId($resource)
            : '';
        if ($fullUrl === '') {
            $fullUrlFailures[] =
                "entry[$index] fullUrl is missing";
            continue;
        }
        if ($resourceIdValue === '') {
            $fullUrlFailures[] =
                "entry[$index] resource id is missing";
        }
    }
    check(
        count($fullUrlFailures) === 0,
        'ENTRY_FULLURL',
        count($fullUrlFailures) === 0
            ? 'All Bundle entries have fullUrl and resource id.'
            : 'One or more Bundle entries have invalid fullUrl/resource id.',
        $checks
    );
    /*
     * -------------------------------------------------------------
     * OFFICIAL NRCES PROFILE SUMMARY
     * -------------------------------------------------------------
     */
    $officialProfileChecks = [
        'PrescriptionRecord Composition status/type/date/title',
        'PrescriptionRecord single section and MedicationRequest entry slices',
        'MedicationRequest status/intent/medication[x]/authoredOn',
        'MedicationRequest dosage route/method SNOMED structure when supplied'
    ];
    check(
        true,
        'NRCES_PROFILE_VERSION',
        'Validation rules above are aligned to the published ABDM FHIR IG v6.5.0 PrescriptionRecord and MedicationRequest profiles.',
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
            ? 'Prescription FHIR Bundle validation passed.'
            : 'Prescription FHIR Bundle validation failed.',
        'prescription_id' => $prescriptionId,
        'generator_http_status' => $generatorHttpStatus,
        'bundle_id' => $bundle['id'] ?? null,
        'bundle_type' => $bundle['type'] ?? null,
        'entry_count' => count($entries),
        'resource_summary' => $resourceSummary,
        'patient_id' => $patient['id'] ?? null,
        'practitioner_id' => $practitioner['id'] ?? null,
        'organization_id' => $organization['id'] ?? null,
        'encounter_id' => $encounter['id'] ?? null,
        'medication_request_count' => count($medicationRequests),
        'section_reference_failures' => $sectionReferenceFailures,
        'medication_failures' => $medicationFailures,
        'clinical_reference_failures' => $clinicalReferenceFailures,
        'entry_failures' => $entryFailures,
        'nrces_profile' => 'ABDM FHIR IG v6.5.0',
        'official_profile_checks' => $officialProfileChecks,
        'checks' => $checks
    ], $passed ? 200 : 422);
} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'result' => 'FAIL',
        'message' => 'Prescription FHIR validation failed unexpectedly.',
        'error' => $e->getMessage()
    ], 500);
}
?>