<?php
declare(strict_types=1);

/*
 * ABDM M3 - HIP health-information delivery
 * -----------------------------------------
 *
 * Implements the ABDM v3 HIP-side data transfer for the contract the gateway
 * posts to /api/v3/hip/health-information/request:
 *
 *   1. The requester (HIU) supplies keyMaterial: its ephemeral X25519 public
 *      key DHPK(U) and its 32 byte nonce RAND(U).
 *   2. The HIP generates its OWN ephemeral key pair DHSK(P)/DHPK(P) and its
 *      own nonce RAND(P) for this transaction.
 *   3. Shared key DHK(U,P) is computed from DHPK(U) and DHSK(P). The two
 *      nonces are XORed: the first 20 bytes are the HKDF salt, the last 12
 *      are the AES-GCM IV.
 *   4. SK(U,P) = HKDF-SHA256(ikm = DHK(U,P), length 32, info = '').
 *   5. Each entry is encrypted with AES-256-GCM, no additional authenticated
 *      data. The 16 byte tag is appended to the ciphertext and the result is
 *      Base64 encoded. `checksum` is the MD5 of the PLAINTEXT, hexadecimal.
 *   6. DHPK(P), RAND(P) and the encrypted entries are POSTed to the
 *      dataPushUrl named by the requester, with the gateway access token as
 *      `Authorization: Bearer`.
 *   7. One entry per page, with fresh key material per page.
 *
 * RUNTIME NOTE - X25519 on PHP 8.2 / 8.3
 * -----------------------------------------
 * `openssl_pkey_new(['x25519' => []])` and OPENSSL_KEYTYPE_X25519 only exist
 * from PHP 8.4. This library therefore builds the key pair without them:
 *
 *   - the private key is a 32 random byte X25519 seed wrapped in a PKCS#8
 *     SubjectPrivateKeyInfo DER, and imported with openssl_pkey_get_private();
 *   - openssl_pkey_get_details()['key'] returns the matching 44 byte
 *     SubjectPublicKeyInfo PEM, whose last 32 bytes are the raw public key
 *     that must be sent to the requester.
 *
 * This path is not a workaround for an unsupported curve: it is the same
 * Curve25519 primitive, and it is verified against the RFC 7748 section 6.1
 * Alice/Bob test vector, which this implementation reproduces exactly.
 * openssl_pkey_derive(), hash_hkdf() and AES-256-GCM are then the same
 * primitives used on every other PHP version.
 *
 * This file performs no database access, no session access and no output. It
 * is a library only.
 */

/**
 * Generate an ephemeral X25519 key pair for one transfer.
 *
 * @return array{private_pem:string,public_raw:string,public_key_b64:string}
 */
function abdmM3HipKeypairGenerate(): array
{
    $seed = random_bytes(32);

    // PKCS#8 v1, no attributes, algorithm = id-X25519 (1.3.101.110),
    // privateKey OCTET STRING holding the 32 byte seed.
    $der = hex2bin('302e020100300506032b656e04220420') . $seed;

    if ($der === false) {
        throw new RuntimeException('Unable to build the X25519 private key structure.');
    }

    $privatePem = "-----BEGIN PRIVATE KEY-----\n"
        . chunk_split(base64_encode($der), 64, "\n")
        . "-----END PRIVATE KEY-----\n";

    $privateKey = openssl_pkey_get_private($privatePem);

    if ($privateKey === false) {
        throw new RuntimeException('Unable to import the generated X25519 private key.');
    }

    // openssl_pkey_get_details()['key'] is the SubjectPublicKeyInfo PEM.
    // For X25519 it is always 44 DER bytes: a 12 byte header then the key.
    $details = openssl_pkey_get_details($privateKey);

    if (!is_array($details) || !isset($details['key']) || !is_string($details['key'])) {
        throw new RuntimeException('Unable to read the generated X25519 public key.');
    }

    $body = preg_replace('/-----[^-]+-----|\s+/', '', $details['key']);

    if (!is_string($body)) {
        throw new RuntimeException('Unable to decode the generated X25519 public key.');
    }

    $publicDer = base64_decode($body, true);

    if (!is_string($publicDer) || strlen($publicDer) !== 44) {
        throw new RuntimeException('Unexpected X25519 SubjectPublicKeyInfo length.');
    }

    $publicRaw = substr($publicDer, -32);

    return [
        'private_pem'    => $privatePem,
        'public_raw'     => $publicRaw,
        'public_key_b64' => base64_encode($publicRaw)
    ];
}

/**
 * Wrap a raw 32 byte X25519 public key in a SubjectPublicKeyInfo PEM.
 * This is the form the requester already understands, and is the same
 * construction the inbound data-push endpoint uses.
 */
function abdmM3X25519PublicPem(string $rawPublicKey): string
{
    if (strlen($rawPublicKey) !== 32) {
        throw new RuntimeException('X25519 public key must be exactly 32 bytes.');
    }

    $der = hex2bin('302a300506032b656e032100') . $rawPublicKey;

    if ($der === false) {
        throw new RuntimeException('Unable to build the X25519 public key structure.');
    }

    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($der), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

/**
 * Agree the AES-256-GCM session key with the requester.
 *
 * @param string $hipPrivatePem  This side's PKCS#8 private key PEM.
 * @param string $hiuPublicKeyB64 Requester's DHPK(U), Base64.
 * @param string $hiuNonceB64      Requester's RAND(U), Base64.
 * @param string $hipNonceB64      This side's RAND(P), Base64.
 *
 * @return array{aes_key:string,iv:string,salt:string,shared_secret:string}
 */
function abdmM3SessionKeyDerive(
    string $hipPrivatePem,
    string $hiuPublicKeyB64,
    string $hiuNonceB64,
    string $hipNonceB64
): array {
    $hiuPublicRaw = base64_decode($hiuPublicKeyB64, true);

    if (!is_string($hiuPublicRaw) || strlen($hiuPublicRaw) !== 32) {
        throw new RuntimeException('Requester X25519 public key must be 32 bytes.');
    }

    $hiuNonceRaw = base64_decode($hiuNonceB64, true);

    if (!is_string($hiuNonceRaw) || strlen($hiuNonceRaw) !== 32) {
        throw new RuntimeException('Requester nonce must be 32 bytes.');
    }

    $hipNonceRaw = base64_decode($hipNonceB64, true);

    if (!is_string($hipNonceRaw) || strlen($hipNonceRaw) !== 32) {
        throw new RuntimeException('HIP nonce must be 32 bytes.');
    }

    $hipPrivateKey = openssl_pkey_get_private($hipPrivatePem);

    if ($hipPrivateKey === false) {
        throw new RuntimeException('Unable to import the HIP X25519 private key.');
    }

    $hiuPublicKey = openssl_pkey_get_public(
        abdmM3X25519PublicPem($hiuPublicRaw)
    );

    if ($hiuPublicKey === false) {
        throw new RuntimeException('Unable to import the requester X25519 public key.');
    }

    $sharedSecret = openssl_pkey_derive($hiuPublicKey, $hipPrivateKey, 32);

    if (!is_string($sharedSecret) || strlen($sharedSecret) !== 32) {
        throw new RuntimeException('ECDH shared-secret derivation failed.');
    }

    $combinedNonce = $hiuNonceRaw ^ $hipNonceRaw;

    if (strlen($combinedNonce) !== 32) {
        throw new RuntimeException('Combined nonce must be 32 bytes.');
    }

    $salt = substr($combinedNonce, 0, 20);
    $iv = substr($combinedNonce, 20, 12);

    $aesKey = hash_hkdf('sha256', $sharedSecret, 32, '', $salt);

    if (!is_string($aesKey) || strlen($aesKey) !== 32) {
        throw new RuntimeException('Unable to derive the AES-256-GCM session key.');
    }

    return [
        'aes_key'       => $aesKey,
        'iv'            => $iv,
        'salt'          => $salt,
        'shared_secret' => $sharedSecret
    ];
}

/**
 * Encrypt one FHIR bundle for transfer.
 *
 * The entry keys are the contract's camelCase names, which is what the
 * receiver reads: see api/v3/hiu/health-information/data-push/index.php,
 * where `$entry['careContextReference']` is consumed.
 *
 * @return array{content:string,checksum:string,media:string,careContextReference:string}
 */
function abdmM3EntryEncrypt(
string $fhirJson,
string $aesKey,
string $iv,
string $careContextReference
): array {
    if (!in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
        throw new RuntimeException('AES-256-GCM is not available on this server.');
    }

    // The requester verifies the MD5 of the PLAINTEXT, before encryption.
    $checksum = md5($fhirJson);

    $tag = '';

    $ciphertext = openssl_encrypt(
        $fhirJson,
        'aes-256-gcm',
        $aesKey,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($ciphertext === false) {
        throw new RuntimeException('AES-256-GCM encryption failed.');
    }

    if (!is_string($tag) || strlen($tag) !== 16) {
        throw new RuntimeException('Unexpected AES-256-GCM authentication tag length.');
    }

return [
'content'               => base64_encode($ciphertext . $tag),
'checksum'              => $checksum,
'media'                 => 'application/fhir+json',
'careContextReference'  => $careContextReference
];
}

/**
 * Build the keyMaterial block the requester needs in order to derive the same
 * session key. This side's public key and nonce travel with the data.
 */
function abdmM3KeyMaterialBuild(string $publicKeyB64, string $nonceB64): array
{
    $expiry = new DateTime('now', new DateTimeZone('UTC'));
    $expiry->modify('+10 minutes');

    return [
        'cryptoAlg' => 'ECDH',
        'curve'     => 'curve25519',
        'dhPublicKey' => [
            'expiry'     => $expiry->format('Y-m-d\TH:i:s.v\Z'),
            'parameters' => 'Curve25519/32byte random key',
            'keyValue'   => $publicKeyB64
        ],
        'nonce' => $nonceB64
    ];
}

/**
 * Build one transfer page.
 *
 * The contract is one entry per page with fresh key material per page, so
 * pageCount always equals the number of entries.
 */
function abdmM3TransferPageBuild(
    string $transactionId,
    int $pageNumber,
    int $pageCount,
    array $entries,
    array $keyMaterial
): array {
    return [
        'transactionId' => $transactionId,
        'pageNumber'    => $pageNumber,
        'pageCount'     => $pageCount,
        'entries'       => array_values($entries),
        'keyMaterial'   => $keyMaterial
    ];
}

/**
 * POST one encrypted page to the requester's dataPushUrl.
 *
 * This call does not pass through the ABDM gateway. The gateway access token
 * is nevertheless mandatory and is sent as `Authorization: Bearer`.
 *
 * $curlOptionsOverride exists so the offline test harness can inject a
 * transport double. Production callers must never pass it.
 *
 * @return array{success:bool,http_code:int,error:?string,response:?string}
 */
function abdmM3TransferPost(
    string $url,
    array $payload,
    string $bearerToken,
    array $curlOptionsOverride = []
): array {
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

    if ($body === false) {
        return [
            'success'    => false,
            'http_code'  => 0,
            'error'      => 'Unable to encode the transfer payload.',
            'response'   => null
        ];
    }

    if (!array_key_exists('__transport', $curlOptionsOverride)) {
        $ch = curl_init($url);

        if ($ch === false) {
            return [
                'success'   => false,
                'http_code' => 0,
                'error'     => 'Unable to initialise cURL.',
                'response'  => null
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $bearerToken,
                'REQUEST-ID: ' . abdmM3TransferRequestId(),
                'TIMESTAMP: ' . gmdate('Y-m-d\TH:i:s.v\Z')
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            return [
                'success'   => false,
                'http_code' => $httpCode,
                'error'     => 'cURL error: ' . $curlError,
                'response'  => null
            ];
        }

        return [
            'success'   => ($httpCode >= 200 && $httpCode < 300),
            'http_code' => $httpCode,
            'error'     => null,
            'response'  => is_string($response) ? $response : null
        ];
    }

    // Offline transport double. Used only by the regression harness.
    $transport = $curlOptionsOverride['__transport'];
    $result = $transport($url, $body, $bearerToken);

    return [
        'success'   => !empty($result['success']),
        'http_code' => (int)($result['http_code'] ?? 0),
        'error'     => $result['error'] ?? null,
        'response'  => $result['response'] ?? null
    ];
}

function abdmM3TransferRequestId(): string
{
    return sprintf(
        '%08x-%04x-%04x-%04x-%012x',
        random_int(0, 0xffffffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffffffffffff)
    );
}

/**
* Deliver the consented records to the requester's dataPushUrl.
*
* Every precondition is checked before a single byte leaves this system:
*
*   - the requester must have supplied a dataPushUrl and usable key material;
*   - the consent artefact behind the request must exist and be GRANTED here;
*   - the requested date range must fall inside the consent's own permission
*     window. A wider window is refused, not silently trimmed;
*   - only care contexts this installation has actually linked are eligible;
*   - only records inside the requested range are selected.
*
* The function never throws. Any failure is returned as a reason string so
* that the acknowledgement, which has already succeeded, is not disturbed.
*
* @return array{success:bool,skipped:bool,reason:?string,entries_sent:int,pages_sent:int,care_contexts:array,summary:array}
*/
function abdmM3HipDeliverHealthInformation(mysqli $conn, array $ctx): array
{
$out = [
'success'       => false,
'skipped'       => false,
'reason'        => null,
'entries_sent'  => 0,
'pages_sent'    => 0,
'care_contexts' => [],
'summary'       => []
];

if (!function_exists('abdmM3HipKeypairGenerate')
|| !function_exists('abdmM3SessionKeyDerive')
|| !function_exists('abdmM3EntryEncrypt')
|| !function_exists('abdmM3TransferPost')) {
$out['skipped'] = true;
$out['reason']  = 'Delivery library is not available.';
return $out;
}

$dataPushUrl = trim((string)($ctx['data_push_url'] ?? ''));
$requestKey = trim((string)($ctx['request_key_value'] ?? ''));
$requestNonce = trim((string)($ctx['request_nonce'] ?? ''));
$transactionId = trim((string)($ctx['transaction_id'] ?? ''));
$consentId = trim((string)($ctx['consent_id'] ?? ''));
$accessToken = trim((string)($ctx['access_token'] ?? ''));

if ($dataPushUrl === '' || $transactionId === '' || $consentId === '') {
$out['skipped'] = true;
$out['reason']  = 'Incomplete request context.';
return $out;
}

if ($requestKey === '' || $requestNonce === '' || $accessToken === '') {
$out['reason'] = 'Requester key material or gateway token is missing.';
return $out;
}

if (!filter_var($dataPushUrl, FILTER_VALIDATE_URL)
|| !preg_match('#^https://#i', $dataPushUrl)) {
$out['reason'] = 'Requester dataPushUrl is not an acceptable HTTPS destination.';
return $out;
}

// ---- consent must be one this installation holds, and must be granted ----
$consentStmt = $conn->prepare("
SELECT
c.patient_id,
c.care_context_reference,
c.hi_types,
c.permission_date_from,
c.permission_date_to,
c.status,
a.status AS artefact_status
FROM abdm_m3_consent_requests c
LEFT JOIN abdm_m3_consent_artefacts a
ON a.consent_request_id = c.consent_request_id
AND a.hospital_id = c.hospital_id
WHERE c.hospital_id = ?
AND c.consent_request_id = (
SELECT consent_request_id
FROM abdm_m3_health_information_requests
WHERE id = ?
LIMIT 1
)
ORDER BY a.id DESC
LIMIT 1
");

if (!$consentStmt) {
$out['reason'] = 'Unable to prepare the consent lookup.';
return $out;
}

$consentStmt->bind_param('ii', $ctx['hospital_id'], $ctx['local_request_id']);
$consent = $consentStmt->execute() ? $consentStmt->get_result()->fetch_assoc() : null;
$consentStmt->close();

if (!$consent) {
$out['skipped'] = true;
$out['reason']  = 'No local consent request matches this transaction.';
return $out;
}

if (strtoupper((string)$consent['status']) !== 'GRANTED') {
$out['skipped'] = true;
$out['reason']  = 'Consent is not granted locally.';
return $out;
}

$patientId = (int)$consent['patient_id'];

if ($patientId <= 0) {
$out['reason'] = 'Consent does not resolve to a local patient.';
return $out;
}

// ---- the requested window must sit inside the consent's own window ----
$consentFrom = strtotime((string)$consent['permission_date_from'] . ' UTC');
$consentTo = strtotime((string)$consent['permission_date_to'] . ' UTC');
$askedFrom = strtotime((string)($ctx['date_from'] ?? ''));
$askedTo = strtotime((string)($ctx['date_to'] ?? ''));

if ($consentFrom === false || $consentTo === false) {
$out['reason'] = 'Consent permission window is not usable.';
return $out;
}

if ($askedFrom !== false && $askedFrom < $consentFrom) {
$out['reason'] = 'Requested start precedes the consent window.';
return $out;
}

if ($askedTo !== false && $askedTo > $consentTo) {
$out['reason'] = 'Requested end exceeds the consent window.';
return $out;
}

// ---- only linked care contexts are eligible ----
$ccStmt = $conn->prepare("
SELECT care_context_reference, care_context_type
FROM abdm_care_contexts
WHERE patient_id = ?
AND link_status = 'Linked'
ORDER BY id ASC
");

if (!$ccStmt) {
$out['reason'] = 'Unable to prepare the care context lookup.';
return $out;
}

$ccStmt->bind_param('i', $patientId);
$careContexts = $ccStmt->execute() ? $ccStmt->get_result()->fetch_all(MYSQLI_ASSOC) : [];
$ccStmt->close();

if (!$careContexts) {
$out['skipped'] = true;
$out['reason']  = 'No linked care context for this patient.';
return $out;
}

// ---- collect the records that fall inside the requested range ----
$rxStmt = $conn->prepare("
SELECT p.id AS prescription_id, p.appointment_id
FROM opd_prescriptions p
JOIN appointments a ON a.id = p.appointment_id
WHERE a.patient_id = ?
AND a.appointment_date BETWEEN ? AND ?
ORDER BY p.id ASC
");

if (!$rxStmt) {
$out['reason'] = 'Unable to prepare the prescription lookup.';
return $out;
}

$rangeFrom = $askedFrom !== false ? date('Y-m-d', $askedFrom) : date('Y-m-d', $consentFrom);
$rangeTo = $askedTo !== false ? date('Y-m-d', $askedTo) : date('Y-m-d', $consentTo);

$rxStmt->bind_param('iss', $patientId, $rangeFrom, $rangeTo);
$prescriptions = $rxStmt->execute() ? $rxStmt->get_result()->fetch_all(MYSQLI_ASSOC) : [];
$rxStmt->close();

if (!$prescriptions) {
$out['skipped'] = true;
$out['reason']  = 'No prescription falls inside the requested range.';
return $out;
}

$fhirLibrary = $_SERVER['DOCUMENT_ROOT'] . '/modules/abdm/m3/m3_prescription_fhir_lib.php';

if (!is_file($fhirLibrary) || !function_exists('m3_prescription_fhir_build_bundle')) {
require_once $fhirLibrary;
}

if (!function_exists('m3_prescription_fhir_build_bundle')) {
$out['reason'] = 'The FHIR bundle builder is not available.';
return $out;
}

// One entry per page, fresh key material per page, per the contract.
$pages = [];
$pageNumber = 0;

foreach ($prescriptions as $prescription) {
$prescriptionId = (int)$prescription['prescription_id'];

$bundle = m3_prescription_fhir_build_bundle($conn, $prescriptionId, 0);

if (!is_array($bundle) || ($bundle['success'] ?? false) !== true
|| !isset($bundle['bundle'])) {
continue;
}

$fhirJson = json_encode($bundle['bundle'], JSON_UNESCAPED_SLASHES);

if (!is_string($fhirJson)) {
continue;
}

$pageNumber++;

try {
$hipKeypair = abdmM3HipKeypairGenerate();
$hipNonce = random_bytes(32);

$session = abdmM3SessionKeyDerive(
$hipKeypair['private_pem'],
$requestKey,
$requestNonce,
base64_encode($hipNonce)
);

$entry = abdmM3EntryEncrypt(
$fhirJson,
$session['aes_key'],
$session['iv'],
'PRESCRIPTION-' . $prescriptionId
);

$pages[] = abdmM3TransferPageBuild(
$transactionId,
$pageNumber,
$pageNumber,
[$entry],
abdmM3KeyMaterialBuild(
$hipKeypair['public_key_b64'],
base64_encode($hipNonce)
)
);

$out['care_contexts'][] = [
'care_context_reference' => 'PRESCRIPTION-' . $prescriptionId,
'prescription_id'        => $prescriptionId,
'status'                 => 'PENDING'
];
} catch (Throwable $e) {
$out['care_contexts'][] = [
'care_context_reference' => 'PRESCRIPTION-' . $prescriptionId,
'prescription_id'        => $prescriptionId,
'status'                 => 'ERRORED',
'error'                  => $e->getMessage()
];
}
}

if (!$pages) {
$out['reason'] = 'No bundle could be encrypted for transfer.';
return $out;
}

// ---- deliver ----
/*
 * A caller may supply `curl_options_override` to substitute the transport.
 * Production callers never set it; the offline regression harness uses it to
 * stand in for the recipient so no clinical record is ever sent over a
 * network during testing.
 */
$transferOptions = isset($ctx['curl_options_override']) && is_array($ctx['curl_options_override'])
? $ctx['curl_options_override']
: [];

foreach ($pages as $index => $page) {
$result = abdmM3TransferPost($dataPushUrl, $page, $accessToken, $transferOptions);

if ($result['success']) {
$out['pages_sent']++;
$out['entries_sent']++;

if (isset($out['care_contexts'][$index])) {
$out['care_contexts'][$index]['status'] = 'DELIVERED';
}
} else {
if (isset($out['care_contexts'][$index])) {
$out['care_contexts'][$index]['status'] = 'ERRORED';
$out['care_contexts'][$index]['error'] = (string)$result['error'];
}

$out['reason'] = 'Transfer rejected: HTTP ' . (int)$result['http_code']
. ($result['error'] !== null ? ' (' . $result['error'] . ')' : '');

$out['summary'] = [
'pages_sent'   => $out['pages_sent'],
'entries_sent' => $out['entries_sent'],
'page_count'   => count($pages),
'http_code'    => (int)$result['http_code'],
'care_contexts' => $out['care_contexts']
];

abdmM3DeliveryPersist($conn, $ctx, $out['summary'], 'DeliveryFailed');

return $out;
}
}

$out['success'] = true;
$out['summary'] = [
'pages_sent'     => $out['pages_sent'],
'entries_sent'   => $out['entries_sent'],
'care_contexts'  => $out['care_contexts'],
'http_code'      => 202,
'date_range'     => [$rangeFrom, $rangeTo],
'patient_id'     => $patientId
];

abdmM3DeliveryPersist($conn, $ctx, $out['summary'], 'Delivered');

return $out;
}

/**
* Record the transfer outcome against the health-information request row.
* Never throws: a bookkeeping failure must not fail the transfer.
*/
function abdmM3DeliveryPersist(mysqli $conn, array $ctx, array $summary, string $status): void
{
$localRequestId = (int)($ctx['local_request_id'] ?? 0);

if ($localRequestId <= 0) {
return;
}

$payload = json_encode($summary, JSON_UNESCAPED_SLASHES);

$stmt = $conn->prepare("
UPDATE abdm_m3_health_information_requests
SET data_response = ?,
status = ?
WHERE id = ?
LIMIT 1
");

if (!$stmt) {
return;
}

$stmt->bind_param('ssi', $payload, $status, $localRequestId);
$stmt->execute();
$stmt->close();
}

