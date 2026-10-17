<?php
require_once __DIR__ . '/m2_config.php';

function abdmM2NormalizeAbhaNumber($value)
{
    return preg_replace('/\D+/', '', (string)$value);
}

/**
 * Normalise an inbound mobile number to the exact form patient_master stores.
 *
 * patient_master.mobile is a bare 10-digit Indian mobile with no country code,
 * no leading zero and a UNIQUE index. ABDM may present the same number in any
 * of these shapes:
 *
 *   9882945635         already canonical
 *   +919882945635      country code, 12 digits
 *   91-98829-45635     country code plus separators, 12 digits
 *   09882945635        leading trunk zero, 11 digits
 *
 * All four must reduce to the same stored value or a valid patient becomes
 * invisible to demographic matching.
 *
 * The result must additionally begin with 6, 7, 8 or 9, which is the range
 * every Indian mobile number occupies. This rejects landlines and short codes
 * that would otherwise survive digit stripping: "01123456789" is a landline,
 * and without the range check its leading zero would be stripped to produce the
 * plausible-looking but fictional "1123456789". A number that reduces to
 * something outside the mobile range is refused rather than matched, because the
 * cost of a wrong match is one patient's care contexts attributed to another,
 * while the cost of a refused match is a single logged no-match.
 *
 * The function REFUSES anything it cannot reduce to a valid 10-digit mobile
 * rather than truncating.
 *
 * @param mixed $value
 * @return string Ten digits, or empty string when unusable.
 */
function abdmM2NormalizeMobile($value)
{
    $digits = preg_replace('/\D+/', '', (string)$value);

    if ($digits === '' || $digits === null) {
        return '';
    }

    $length = strlen($digits);

    if ($length === 10) {
        $candidate = $digits;
    } elseif ($length === 12 && strpos($digits, '91') === 0) {
        // 91 + 10 digits is the only country-coded form accepted.
        $candidate = substr($digits, 2);
    } elseif ($length === 11 && $digits[0] === '0') {
        // A leading trunk zero is a dialling artefact, not part of the number.
        $candidate = substr($digits, 1);
    } else {
        return '';
    }

    // Must land in the Indian mobile range. This is a range check, not a
    // deep validation: it rules out landlines and short codes without
    // re-implementing the national number plan.
    if (preg_match('/^[6-9]\d{9}$/', $candidate) !== 1) {
        return '';
    }

    return $candidate;
}

/**
 * Map an ABDM gender value onto the value patient_master.sex actually stores.
 *
 * The HiECM gateway sends the code form ("M", "F", "O"). The application stores
 * the full English word ("Male", "Female", "Other") because that is what the
 * registration form writes - see modules/registration/register.php:1507-1511.
 * The M2 link-token builder converts in the opposite direction at
 * modules/abdm/m2_link_token.php:99-107; this is its inverse.
 *
 * "U" / "unknown" is deliberately NOT mapped onto "Other". "Other" is a
 * deliberate value a patient was recorded with, whereas "unknown" means the
 * gateway does not know. Collapsing them would turn an unknown gender into a
 * wildcard and could match the wrong patient. Unmapped input returns an empty
 * string, which makes the caller refuse the match.
 *
 * @param mixed $value
 * @return string 'Male' | 'Female' | 'Other', or empty string when unusable.
 */
function abdmM2MapAbdmGenderToSex($value)
{
    $gender = strtoupper(trim((string)$value));

    if ($gender === '') {
        return '';
    }

    if ($gender === 'M' || $gender === 'MALE') {
        return 'Male';
    }

    if ($gender === 'F' || $gender === 'FEMALE') {
        return 'Female';
    }

    if ($gender === 'O' || $gender === 'OTHER') {
        return 'Other';
    }

    return '';
}

/**
 * Validate and return an inbound year of birth as an integer.
 *
 * patient_master.dob is a DATE column, so the year is compared with YEAR(dob)
 * rather than against a stored year column.
 *
 * The plausibility window is intentionally generous but excludes values that
 * cannot be a real patient's year, which keeps an obviously malformed payload
 * from matching a row with a sentinel date.
 *
 * @param mixed $value
 * @return int|null Null when absent, non-numeric or out of range.
 */
function abdmM2ExtractYearOfBirth($value)
{
    $raw = trim((string)$value);

    if ($raw === '') {
        return null;
    }

    // Accept "1991" and "1991-03-07"; reject anything else.
    if (preg_match('/^(\d{4})(?:-\d{2}-\d{2})?$/', $raw, $matches) !== 1) {
        return null;
    }

    $year = (int)$matches[1];

    if ($year < 1900 || $year > (int)gmdate('Y')) {
        return null;
    }

    return $year;
}

/**
 * Pull the verified MOBILE identifier out of an ABDM discovery patient object.
 *
 * @param mixed $patient
 * @return string Raw value as sent by the gateway, or empty string.
 */
function abdmM2ExtractVerifiedMobile($patient)
{
    if (!is_array($patient) || empty($patient['verifiedIdentifiers']) || !is_array($patient['verifiedIdentifiers'])) {
        return '';
    }

    foreach ($patient['verifiedIdentifiers'] as $identifier) {
        if (!is_array($identifier)) {
            continue;
        }

        if (strtoupper(trim((string)($identifier['type'] ?? ''))) === 'MOBILE') {
            return trim((string)($identifier['value'] ?? ''));
        }
    }

    return '';
}

/**
 * Does the payload carry an ABHA anchor?
 *
 * The discovery endpoint uses this to choose a matcher rather than to decide
 * whether a match is acceptable. ABHA present means the ABHA matcher runs and a
 * genuine ABHA-based refusal is reported as-is rather than being silently
 * retried against demographics, which could turn "this ABHA does not resolve"
 * into "some other patient's demographics happen to match".
 *
 * @param mixed $patient
 * @return bool
 */
function abdmM2HasAbhaAnchor($patient)
{
    if (!is_array($patient) || empty($patient['verifiedIdentifiers']) || !is_array($patient['verifiedIdentifiers'])) {
        return false;
    }

    foreach ($patient['verifiedIdentifiers'] as $identifier) {
        if (!is_array($identifier)) {
            continue;
        }

        $type = strtoupper(trim((string)($identifier['type'] ?? '')));

        if ($type === 'ABHA_ADDRESS' || $type === 'ABHA_NUMBER') {
            if (trim((string)($identifier['value'] ?? '')) !== '') {
                return true;
            }
        }
    }

    return false;
}

/*
 * M2 P0 - IDENTITY MATCH HARDENING
 * =====================================================================
 * This function used to build a WHERE clause of OR-ed conditions over
 * ABHA_ADDRESS, ABHA_NUMBER, MOBILE and MR, then `LIMIT 1`.
 *
 * Two defects followed from that:
 *
 *   1. A MOBILE or an MR alone was sufficient to "identify" a patient.
 *      Because the endpoint then builds and forwards the discovery response,
 *      knowing one patient's mobile number disclosed that patient's UHID, full
 *      name and every prescription/appointment care context.
 *   2. `LIMIT 1` with no ORDER BY silently picked an arbitrary row whenever
 *      more than one patient could match.
 *
 * The rule is now:
 *   - An ABHA anchor is MANDATORY. At least one of ABHA_ADDRESS or
 *     ABHA_NUMBER must be present, otherwise the match is refused.
 *   - The patient is selected by ABHA anchors ONLY. Two independent anchors
 *     exist in the schema and both are honoured:
 *       * abdm_patient_links.abha_address / abha_number  (ABDM-facing link)
 *       * patient_master.abha_id                         (application source
 *         of truth, confirmed in handover D.19)
 *     When BOTH ABHA identifiers are supplied they are combined with AND, so
 *     two identifiers that disagree cannot resolve to one patient.
 *   - MOBILE and MR are CORROBORATION ONLY. They never widen the match. If
 *     either is supplied it must agree with the ABHA-matched patient, and a
 *     disagreement is refused rather than silently ignored.
 *   - There is no `LIMIT 1`. The candidate set is resolved explicitly and an
 *     ambiguous result is reported as such instead of guessed.
 *
 * No schema change and no new verification mechanism is introduced: this only
 * uses columns that already exist.
 */
function abdmM2GetUserLinkingPatient($patient)
{
    global $conn;

    if (!isset($conn) || !($conn instanceof mysqli)) {
        return [
            'success' => false,
            'error' => 'Database connection not available.'
        ];
    }

    $abhaAddress = '';
    $abhaNumber = '';
    $mobile = '';
    $uhid = '';
    $name = '';
    $gender = '';
    $yearOfBirth = '';

    if (!empty($patient['verifiedIdentifiers']) && is_array($patient['verifiedIdentifiers'])) {
        foreach ($patient['verifiedIdentifiers'] as $identifier) {
            $type = strtoupper(trim($identifier['type'] ?? ''));
            $value = trim($identifier['value'] ?? '');

            if ($type === 'ABHA_ADDRESS') {
                $abhaAddress = $value;
            } elseif ($type === 'ABHA_NUMBER') {
                $abhaNumber = abdmM2NormalizeAbhaNumber($value);
            } elseif ($type === 'MOBILE') {
                $mobile = $value;
            }
        }
    }

    if (!empty($patient['unverifiedIdentifiers']) && is_array($patient['unverifiedIdentifiers'])) {
        foreach ($patient['unverifiedIdentifiers'] as $identifier) {
            $type = strtoupper(trim($identifier['type'] ?? ''));
            $value = trim($identifier['value'] ?? '');

            if ($type === 'MR') {
                $uhid = $value;
            }
        }
    }

    $name = trim($patient['name'] ?? '');
    $gender = trim($patient['gender'] ?? '');
    $yearOfBirth = trim($patient['yearOfBirth'] ?? '');

    if ($abhaAddress === '' && $abhaNumber === '' && $mobile === '' && $uhid === '') {
        return [
            'success' => false,
            'error' => 'No supported patient identifier supplied.'
        ];
    }

    // ─────────────────────────────────────────────────────────
    // M2 P0 - an ABHA anchor is now MANDATORY
    // ─────────────────────────────────────────────────────────
    // MOBILE and MR are still accepted in the payload, but on their own they
    // can no longer identify a patient. Requiring an ABHA is what stops a
    // known mobile number from disclosing that patient's identity and care
    // contexts.
    if ($abhaAddress === '' && $abhaNumber === '') {
        return [
            'success' => false,
            'error' => 'An ABHA identifier (ABHA_ADDRESS or ABHA_NUMBER) is required for discovery matching.'
        ];
    }

    /*
     * ABHA anchors only.
     *
     * Each supplied ABHA identifier contributes ONE AND-ed condition, and that
     * condition accepts either of the two anchors the schema already provides:
     * the ABDM-facing link row, or patient_master.abha_id (application source
     * of truth). Combining with AND means an address and a number that disagree
     * cannot resolve to the same row.
     *
     * No LIMIT 1: every candidate is returned so ambiguity can be detected.
     */
    $conditions = [];
    $types = '';
    $values = [];

    if ($abhaAddress !== '') {
        $conditions[] = "(apl.abha_address = ? OR p.abha_id = ?)";
        $types .= 'ss';
        $values[] = $abhaAddress;
        $values[] = $abhaAddress;
    }

    if ($abhaNumber !== '') {
        $normalizedAbha = abdmM2NormalizeAbhaNumber($abhaNumber);
        /*
         * The column side is normalised in SQL exactly as the pre-existing
         * code did (strip - / space / dot); the parameter side is normalised
         * in PHP by abdmM2NormalizeAbhaNumber() so both sides are digits-only.
         */
        $conditions[] = "(REPLACE(REPLACE(REPLACE(REPLACE(apl.abha_number, '-', ''), ' ', ''), '/', ''), '.', '') = ?
                      OR REPLACE(REPLACE(REPLACE(REPLACE(p.abha_id, '-', ''), ' ', ''), '/', ''), '.', '') = ?)";
        $types .= 'ss';
        $values[] = $normalizedAbha;
        $values[] = $normalizedAbha;
    }

    $sql = "
        SELECT
            p.id,
            p.uhid,
            p.first_name,
            p.last_name,
            p.sex,
            p.mobile,
            p.dob,
            apl.abha_number,
            apl.abha_address
        FROM patient_master p
        LEFT JOIN abdm_patient_links apl
            ON apl.patient_id = p.id
        WHERE " . implode(' AND ', $conditions) . "
        ORDER BY p.id ASC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return [
            'success' => false,
            'error' => 'Unable to prepare patient matching query.'
        ];
    }

    $stmt->bind_param($types, ...$values);
    $stmt->execute();

    $result = $stmt->get_result();
    $candidates = [];
    while ($candidate = $result->fetch_assoc()) {
        $candidates[(int)$candidate['id']] = $candidate;
    }

    $stmt->close();

    if (!$candidates) {
        return [
            'success' => false,
            'error' => 'Patient not found.'
        ];
    }

    /*
     * M2 P0 - explicit ambiguity handling.
     *
     * The old query returned an arbitrary row here. Two distinct patients
     * satisfying the ABHA anchor is a data problem that must be surfaced, not
     * silently resolved, because picking either one would attribute a real
     * patient's care contexts to the wrong record.
     */
    if (count($candidates) > 1) {
        error_log(
            'ABDM M2 DISCOVERY | ABHA anchor matched ' . count($candidates) .
            ' distinct patients; refusing to guess. Identifiers were ABHA and/or ABHA number.'
        );

        return [
            'success' => false,
            'error' => 'ABHA identifier matches more than one patient record; discovery refused.'
        ];
    }

    $matchedPatient = reset($candidates);

    // ─────────────────────────────────────────────────────────
    // M2 P0 - MOBILE / MR are corroboration only
    // ─────────────────────────────────────────────────────────
    // These never widen the match. If the caller supplied one it must agree
    // with the ABHA-matched patient; a disagreement is a refusal, not a
    // warning, because it means the payload is internally inconsistent.
    if ($mobile !== '') {
        $matchedMobile = preg_replace('/\D+/', '', (string)($matchedPatient['mobile'] ?? ''));
        $suppliedMobile = preg_replace('/\D+/', '', $mobile);

        if ($suppliedMobile === '') {
            return [
                'success' => false,
                'error' => 'Supplied MOBILE identifier is not usable for corroboration.'
            ];
        }

        if ($matchedMobile === '') {
            return [
                'success' => false,
                'error' => 'Matched patient has no mobile on record; MOBILE corroboration could not be satisfied.'
            ];
        }

        if (substr($matchedMobile, -10) !== substr($suppliedMobile, -10)) {
            return [
                'success' => false,
                'error' => 'MOBILE identifier does not corroborate the ABHA-matched patient.'
            ];
        }
    }

    if ($uhid !== '') {
        $matchedUhid = trim((string)($matchedPatient['uhid'] ?? ''));

        if ($matchedUhid === '' || strcasecmp($matchedUhid, $uhid) !== 0) {
            return [
                'success' => false,
                'error' => 'MR identifier does not corroborate the ABHA-matched patient.'
            ];
        }
    }

    return [
        'success' => true,
        'patient' => $matchedPatient,
        'match_method' => 'abha',
        'matched_identifiers' => [
            'ABHA_ADDRESS' => $abhaAddress !== '',
            'ABHA_NUMBER' => $abhaNumber !== '',
            'MOBILE' => $mobile !== '',
            'MR' => $uhid !== ''
        ]
    ];
}

/**
 * Resolve a patient from verified demographics alone.
 *
 * Purpose
 * -------
 * The HiECM user-initiated discovery flow can present a discovery request that
 * carries no ABHA at all, only a verified mobile number plus the demographic
 * attributes the ABHA app shared. abdmM2GetUserLinkingPatient() correctly
 * REFUSES that payload, because on its own a mobile number used to disclose a
 * patient's identity and every one of their care contexts.
 *
 * This matcher is the replacement path for that case, and it is deliberately
 * much narrower than an OR-ed identifier match:
 *
 *   - All three of MOBILE, GENDER and YEAR OF BIRTH must be present AND must
 *     agree with the stored row. Any one of them missing refuses the match; a
 *     partial match is never completed from the others.
 *   - Each value is normalised to the exact form the column stores before it is
 *     bound (see the normalisers above). A mismatch in presentation therefore
 *     cannot cause a false negative against a real patient.
 *   - There is no LIKE, no partial comparison and no ORDER BY LIMIT 1. The
 *     candidate set is resolved explicitly and ambiguity is refused, matching
 *     the policy in abdmM2GetUserLinkingPatient().
 *
 * Callers MUST reach this function only after a cryptographically verified
 * gateway token has been established. Demographics are caller-asserted values:
 * they are trustworthy because the caller proved it was the ABDM gateway, not
 * because they are self-authenticating. The discovery endpoint enforces this by
 * passing the JWT gate result; see api/v3/hip/patient/care-context/discover.
 *
 * Columns used (real schema):
 *   patient_master.mobile  bare 10-digit mobile, UNIQUE
 *   patient_master.sex     'Male' | 'Female' | 'Other'
 *   patient_master.dob     DATE, compared with YEAR(dob)
 *
 * @param mixed $patient The ABDM discovery `patient` object.
 * @return array{success:bool, error?:string, patient?:array, match_method?:string,
 *               matched_identifiers?:array, has_abha_on_record?:bool}
 */
function abdmM2MatchPatientByDemographics($patient)
{
    global $conn;

    if (!isset($conn) || !($conn instanceof mysqli)) {
        return [
            'success' => false,
            'error' => 'Database connection not available.'
        ];
    }

    if (!is_array($patient)) {
        return [
            'success' => false,
            'error' => 'Patient object is required for demographic matching.'
        ];
    }

    $rawMobile = abdmM2ExtractVerifiedMobile($patient);
    $rawGender = trim((string)($patient['gender'] ?? ''));
    $rawYear   = trim((string)($patient['yearOfBirth'] ?? ''));

    // ── Normalise before deciding whether the request is even usable ──────────
    $mobile = abdmM2NormalizeMobile($rawMobile);
    $sex    = abdmM2MapAbdmGenderToSex($rawGender);
    $year   = abdmM2ExtractYearOfBirth($rawYear);

    if ($rawMobile === '') {
        return [
            'success' => false,
            'error' => 'No verified MOBILE identifier supplied for demographic matching.'
        ];
    }

    if ($mobile === '') {
        return [
            'success' => false,
            'error' => 'Verified MOBILE identifier is not a usable 10-digit mobile number.'
        ];
    }

    if ($rawGender === '') {
        return [
            'success' => false,
            'error' => 'Gender is required for demographic matching.'
        ];
    }

    if ($sex === '') {
        /*
         * Includes the "unknown" gender case. Falling back to a partial match
         * would let an unknown gender widen the search across every patient
         * sharing a mobile number, which is the disclosure the ABHA mandate
         * exists to prevent.
         */
        return [
            'success' => false,
            'error' => 'Supplied gender cannot be mapped to a recorded patient gender.'
        ];
    }

    if ($rawYear === '') {
        return [
            'success' => false,
            'error' => 'Year of birth is required for demographic matching.'
        ];
    }

    if ($year === null) {
        return [
            'success' => false,
            'error' => 'Supplied year of birth is not a usable four-digit year.'
        ];
    }

    // ── Resolve the candidate set explicitly ─────────────────────────────────
    $sql = "
        SELECT
            p.id,
            p.uhid,
            p.first_name,
            p.last_name,
            p.sex,
            p.mobile,
            p.dob,
            p.abha_id,
            apl.abha_number,
            apl.abha_address
        FROM patient_master p
        LEFT JOIN abdm_patient_links apl
            ON apl.patient_id = p.id
        WHERE p.mobile = ?
          AND p.sex = ?
          AND YEAR(p.dob) = ?
        ORDER BY p.id ASC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return [
            'success' => false,
            'error' => 'Unable to prepare demographic matching query.'
        ];
    }

    $stmt->bind_param('ssi', $mobile, $sex, $year);

    $writeFailed = false;

    try {
        $executed = $stmt->execute();
    } catch (Throwable $e) {
        $executed = false;
        $writeFailed = true;
        error_log('ABDM M2 DISCOVERY | Demographic query threw | ' . $e->getMessage());
    }

    $candidates = [];

    if ($executed) {
        try {
            $result = $stmt->get_result();

            while ($candidate = $result->fetch_assoc()) {
                $candidates[(int)$candidate['id']] = $candidate;
            }
        } catch (Throwable $e) {
            $writeFailed = true;
            error_log('ABDM M2 DISCOVERY | Demographic result read threw | ' . $e->getMessage());
        }
    } elseif (!$writeFailed) {
        error_log('ABDM M2 DISCOVERY | Demographic query execute failed.');
    }

    $stmt->close();

    if (!$candidates) {
        return [
            'success' => false,
            'error' => 'No patient matches the supplied verified demographics.'
        ];
    }

    /*
     * patient_master.mobile is UNIQUE so this should be unreachable. It is
     * still refused rather than resolved, because if the unique constraint is
     * ever dropped the two rows would be two real people and picking either
     * would attribute one patient's care contexts to the other.
     */
    if (count($candidates) > 1) {
        error_log(
            'ABDM M2 DISCOVERY | Demographic match resolved ' . count($candidates) .
            ' patient rows for one mobile; refusing to guess.'
        );

        return [
            'success' => false,
            'error' => 'Supplied demographics match more than one patient record; discovery refused.'
        ];
    }

    $matchedPatient = reset($candidates);

    /*
     * Reported so the caller can decide what it is allowed to do next. A patient
     * with no ABHA on record cannot be auto-linked at all, because the HiECM
     * care-context link API is ABHA-anchored.
     */
    $hasAbhaOnRecord = trim((string)($matchedPatient['abha_address'] ?? '')) !== ''
        || trim((string)($matchedPatient['abha_number'] ?? '')) !== '';

    return [
        'success' => true,
        'patient' => $matchedPatient,
        'match_method' => 'demographics',
        'has_abha_on_record' => $hasAbhaOnRecord,
        'matched_identifiers' => [
            'ABHA_ADDRESS' => false,
            'ABHA_NUMBER' => false,
            'MOBILE' => true,
            'MR' => false
        ]
    ];
}

function abdmM2GetPatientCareContexts($patientId)
{
    global $conn;

    $careContexts = [];

    /*
     * Existing OP Consultation Care Contexts
     * Do not change the existing OPConsultation logic.
     */
    $stmt = $conn->prepare("
        SELECT
            id,
            opd_no,
            appointment_date
        FROM appointments
        WHERE patient_id = ?
          AND type = 'doctor'
          AND opd_no IS NOT NULL
          AND opd_no != ''
        ORDER BY appointment_date DESC, id DESC
    ");

    if (!$stmt) {
        return [
            'success' => false,
            'error' => 'Unable to prepare OP consultation query.'
        ];
    }

    $stmt->bind_param('i', $patientId);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $careContexts[] = [
            'referenceNumber' => (string)$row['opd_no'],
            'display' => (string)$row['opd_no'] . ' - ' . $row['appointment_date'],
            'hiType' => 'OPConsultation'
        ];
    }

    $stmt->close();

    /*
     * Prescription Care Contexts
     *
     * Relationship:
     * opd_prescriptions.appointment_id
     * -> appointments.id
     * -> appointments.patient_id
     *
     * Existing prescription reference format:
     * PRESCRIPTION-{prescription_id}
     */
    $stmt = $conn->prepare("
        SELECT
            p.id AS prescription_id,
            p.appointment_id,
            a.appointment_date
        FROM opd_prescriptions p
        INNER JOIN appointments a
            ON a.id = p.appointment_id
        WHERE a.patient_id = ?
          AND a.type = 'doctor'
        ORDER BY p.id DESC
    ");

    if (!$stmt) {
        return [
            'success' => false,
            'error' => 'Unable to prepare prescription care context query.'
        ];
    }

    $stmt->bind_param('i', $patientId);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $prescriptionId = (int)$row['prescription_id'];

        $careContexts[] = [
            'referenceNumber' => 'PRESCRIPTION-' . $prescriptionId,
            'display' => 'Prescription-' . $prescriptionId . ' - ' . $row['appointment_date'],
            'hiType' => 'PRESCRIPTION'
        ];
    }

    $stmt->close();

    return [
        'success' => true,
        'care_contexts' => $careContexts
    ];
}
function abdmM2GetPatientBasicDetails($patientId)
{
    global $conn;

    $stmt = $conn->prepare("
        SELECT
            id,
            uhid,
            first_name,
            last_name
        FROM patient_master
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return [
            'success' => false,
            'error' => 'Unable to prepare patient details query.'
        ];
    }

    $stmt->bind_param('i', $patientId);
    $stmt->execute();

    $result = $stmt->get_result();
    $patient = $result->fetch_assoc();

    $stmt->close();

    if (!$patient) {
        return [
            'success' => false,
            'error' => 'Patient not found.'
        ];
    }

    return [
        'success' => true,
        'patient' => $patient
    ];
}
function abdmM2BuildDiscoveryResponse($patientId)
{
    $patientResult = abdmM2GetPatientCareContexts($patientId);

    if (!$patientResult['success']) {
        return $patientResult;
    }

    $careContexts = $patientResult['care_contexts'];

    $grouped = [];
$patientDetailsResult = abdmM2GetPatientBasicDetails($patientId);

if (!$patientDetailsResult['success']) {
    return $patientDetailsResult;
}

$patientDetails = $patientDetailsResult['patient'];

$patientReferenceNumber = (string)$patientDetails['uhid'];
$patientDisplay = trim(
    ($patientDetails['first_name'] ?? '') . ' ' .
    ($patientDetails['last_name'] ?? '')
);
    foreach ($careContexts as $context) {
        $hiType = $context['hiType'];

        if (!isset($grouped[$hiType])) {
            $grouped[$hiType] = [];
        }

        $grouped[$hiType][] = [
            'referenceNumber' => $context['referenceNumber'],
            'display' => $context['display']
        ];
    }

    $patientRecords = [];

    foreach ($grouped as $hiType => $contexts) {
       $patientRecords[] = [
    'referenceNumber' => $patientReferenceNumber,
    'display' => $patientDisplay,
    'careContexts' => $contexts,
    'hiType' => $hiType,
    'count' => count($contexts)
];
    }

    return [
        'success' => true,
        'patient_id' => $patientId,
        'patient' => $patientRecords
    ];
}
?>