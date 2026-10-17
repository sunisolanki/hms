<?php
/**
 * ABDM M2 - inbound callback bearer-token verifier (HiECM v3).
 *
 * Purpose
 * -------
 * Every public ABDM callback under api/v3/ currently authenticates itself with
 * nothing more than "the X-HIP-ID header equals the value we configured". That
 * check is NOT source authentication: X-HIP-ID is a facility registry code that
 * is disclosed during bridge registration and appears in every outbound request
 * this installation makes. It proves which facility the caller CLAIMS to be, not
 * that the caller IS the ABDM gateway.
 *
 * This module adds the second half: cryptographic verification of the gateway
 * session token that the ABDM gateway documents as being sent on callbacks. The
 * certificate authority for that is the HiECM gateway certificate endpoint:
 *
 *   GET {ABDM_BASE_URL}/hiecm/gateway/v3/certs
 *
 * ABDM sandbox documentation, section "OAuth Certificate API":
 *   "This API provide an OAuth certificate that can be used to validate which
 *    received gateway session token in Header on the call back."
 *
 * The token travels as `Authorization: Bearer <JWT>`.
 *
 * Modes (do not collapse these)
 * ------------------------------
 *   off      No verification and no network call. Legacy behaviour.
 *   observe  Verification is ATTEMPTED and the verdict is written to the PHP
 *            error log, but the request is ALWAYS ALLOWED. This is the shipping
 *            default because it cannot change gateway behaviour, so the existing
 *            Step 6 harness and any live callback keep working unchanged while we
 *            gather evidence about what the gateway actually sends.
 *   enforce  Verification is MANDATORY. A missing, malformed or unverifiable
 *            token is refused with HTTP 401. Only flip to this once observe-mode
 *            logs prove the live gateway sends a verifiable RS256/RS512 token on
 *            discovery callbacks.
 *
 * Deliberate asymmetry with the global ABDM switch
 * ------------------------------------------------
 * abdmGetGlobalSwitch() in includes/abdm_helper.php FAILS CLOSED: if the setting
 * cannot be read, ABDM is treated as disabled. That is correct for the switch,
 * which governs OUTBOUND consent-carrying traffic - if we cannot prove the
 * installation opted in, we must not send.
 *
 * This flag governs whether an INBOUND patient-facing callback is rejected, so
 * it fails OPEN to `observe`. Failing closed here would mean a database blip
 * silently disables patient linking at a hospital, which is a worse failure than
 * the one being defended against - and `observe` already cannot reject anything.
 * An unreadable or malformed value is therefore logged loudly and degraded to
 * observe. Both decisions are written to the log with a distinct source label so
 * the degradation is never silent.
 *
 * Storage
 * -------
 * `callback_auth_mode` on the single abdm_master_settings row (id = 1), the same
 * table as the global switch. ABDM settings are per installation because tenancy
 * in WebSplend HMS is one database per client, so no tenant column is needed.
 *
 * The column is added by an out-of-band migration because the application
 * database account is least-privilege and has no ALTER right (see
 * modules/abdm/settings/save_global_switch.php). Absent column and absent table
 * are therefore normal states, and both resolve to `observe`. That ordering is
 * what makes it safe to deploy this file before running the migration.
 *
 * Algorithm safety
 * ----------------
 * The `alg` field of the JWT header is attacker-controlled, so it is NEVER
 * allowed to select the verification algorithm on its own. The value is matched
 * against a fixed allowlist first and anything outside it is refused:
 *
 *   - "none" / empty      refused. This is the classic unsigned-token bypass.
 *   - HS256 / HS512       refused. HMAC verification against an RSA public key
 *                         is the algorithm-confusion attack; the public key from
 *                         the certificate endpoint must never be usable as an
 *                         HMAC secret.
 *   - anything else       refused. Unknown algorithms are refused rather than
 *                         attempted, so the allowlist cannot be bypassed by
 *                         sending something the server has never heard of.
 *
 * The signature is verified with openssl_verify() against a public key built from
 * the certificate endpoint's JWKS, then `exp` and `nbf` are checked with a
 * clock-skew allowance because ABDM and this host are not NTP-synchronised to the
 * millisecond.
 *
 * Logging discipline
 * ------------------
 * Following the P0 redaction pattern already applied to
 * api/v3/hip/patient/care-context/discover/index.php: no token, no signature,
 * no claim values, no ABHA number, no patient identifier and no SQL text is ever
 * written. Only header NAMES, header presence booleans, algorithm names, key
 * identifiers, expiry presence and pass/fail reason labels are logged.
 *
 * Architecture constraint: procedural PHP, mysqli only, no framework, no ORM,
 * no composer dependency. openssl is already required by the M3 crypto work.
 */

defined('ABDM_M2_JWT_VERIFIER_LOADED') || define('ABDM_M2_JWT_VERIFIER_LOADED', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/abdm_helper.php';

/**
 * Allowed values for abdm_master_settings.callback_auth_mode.
 *
 * @var string[]
 */
if (!defined('ABDM_M2_AUTH_MODES')) {
    define('ABDM_M2_AUTH_MODES', ['off', 'observe', 'enforce']);
}

/**
 * RS algorithms this verifier is willing to verify, mapped to the OpenSSL
 * constant used by openssl_verify(). Deliberately excludes every HS* and ES*
 * algorithm. See the algorithm-safety section in the file header.
 *
 * @var array<string,int>
 */
if (!defined('ABDM_M2_JWT_ALG_MAP')) {
    define('ABDM_M2_JWT_ALG_MAP', [
        'RS256' => OPENSSL_ALGO_SHA256,
        'RS512' => OPENSSL_ALGO_SHA512,
    ]);
}

/**
 * Clock-skew allowance applied to `exp` and `nbf`, in seconds.
 *
 * The gateway and this host are separate systems; a strict comparison rejects
 * tokens that are genuinely valid because of a few hundred milliseconds of drift.
 */
if (!defined('ABDM_M2_JWT_CLOCK_SKEW')) {
    define('ABDM_M2_JWT_CLOCK_SKEW', 300);
}

/**
 * How long a fetched JWKS is reused before it is fetched again, in seconds.
 */
if (!defined('ABDM_M2_JWKS_TTL')) {
    define('ABDM_M2_JWKS_TTL', 3600);
}

/**
 * MySQL/MariaDB error numbers that mean "the callback-auth setting is not
 * installed yet" rather than "the setting could not be read".
 *
 * 1146 = ER_NO_SUCH_TABLE     - abdm_master_settings has not been created
 * 1054 = ER_BAD_FIELD_ERROR   - the row exists but callback_auth_mode is absent,
 *                               i.e. the migration has not been applied yet
 */
if (!defined('ABDM_M2_AUTH_ABSENT_ERRNOS')) {
    define('ABDM_M2_AUTH_ABSENT_ERRNOS', [1146, 1054]);
}

/**
 * Write a sanitized diagnostic to the PHP error log.
 *
 * Callers pass pre-computed label/value pairs only. Never pass a token, a
 * signature, a claim value or a patient identifier through here.
 *
 * @param array<string,mixed> $fields
 * @return void
 */
function abdmM2JwtLog($fields)
{
    $parts = [];

    foreach ($fields as $key => $value) {
        if (is_bool($value)) {
            $value = $value ? 'YES' : 'NO';
        }

        // Values are restricted to short label characters. Anything else is
        // replaced rather than written, so a future caller cannot accidentally
        // leak arbitrary content into the error log through this helper.
        $value = preg_replace('/[^A-Za-z0-9_.:\/-]/', '', (string)$value);

        $parts[] = $key . '=' . $value;
    }

    error_log('[ABDM-CALLBACK-AUTH] ' . implode(' ', $parts));
}

/**
 * Resolve the configured callback-auth mode.
 *
 * Every failure path resolves to 'observe' rather than 'enforce' or 'off'. See
 * the "deliberate asymmetry" section in the file header for why this reader does
 * not fail closed the way abdmGetGlobalSwitch() does.
 *
 * @param mysqli|null $conn Optional. Defaults to the global $conn.
 * @return array{mode:string, source:string, available:bool, message:string}
 */
function abdmM2JwtAuthMode($conn = null)
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    if ($conn === null) {
        global $conn;
    }

    $degrade = function ($source, $errno = 0) {
        abdmM2JwtLog(['event' => 'mode_degraded', 'source' => $source, 'errno' => $errno, 'mode' => 'observe']);

        return [
            'mode'      => 'observe',
            'source'    => $source,
            'available' => false,
            'message'   => 'Callback authentication is observing (default).',
        ];
    };

    if (!($conn instanceof mysqli)) {
        return $cache = $degrade('no_connection');
    }

    $sql = 'SELECT callback_auth_mode FROM abdm_master_settings WHERE id = 1 LIMIT 1';

    try {
        $stmt = $conn->prepare($sql);
    } catch (mysqli_sql_exception $e) {
        $errno = (int)$e->getCode();

        return $cache = in_array($errno, ABDM_M2_AUTH_ABSENT_ERRNOS, true)
            ? $degrade('schema_absent', $errno)
            : $degrade('read_error', $errno);
    }

    if ($stmt === false) {
        $errno = (int)$conn->errno;

        return $cache = in_array($errno, ABDM_M2_AUTH_ABSENT_ERRNOS, true)
            ? $degrade('schema_absent', $errno)
            : $degrade('read_error', $errno);
    }

    try {
        $executed = $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        $errno = (int)$e->getCode();
        @$stmt->close();

        return $cache = in_array($errno, ABDM_M2_AUTH_ABSENT_ERRNOS, true)
            ? $degrade('schema_absent', $errno)
            : $degrade('read_error', $errno);
    }

    if ($executed === false) {
        $errno = (int)$stmt->errno;
        @$stmt->close();

        return $cache = $degrade('read_error', $errno);
    }

    try {
        $result = $stmt->get_result();
    } catch (mysqli_sql_exception $e) {
        $errno = (int)$e->getCode();
        @$stmt->close();

        return $cache = $degrade('read_error', $errno);
    }

    if (!($result instanceof mysqli_result)) {
        @$stmt->close();

        return $cache = $degrade('read_error', (int)$conn->errno);
    }

    $row = $result->fetch_assoc();
    @$stmt->close();

    if (!is_array($row) || !array_key_exists('callback_auth_mode', $row) || $row['callback_auth_mode'] === null) {
        return $cache = $degrade('row_missing');
    }

    $raw = strtolower(trim((string)$row['callback_auth_mode']));

    // Strict validation against a fixed enum. An unrecognised value is malformed
    // data and is degraded rather than guessed at, so a typo can never be read
    // as an unintended enforcement level.
    if (!in_array($raw, ABDM_M2_AUTH_MODES, true)) {
        return $cache = $degrade('malformed_value');
    }

    $cache = [
        'mode'      => $raw,
        'source'    => 'row',
        'available' => true,
        'message'   => 'Callback authentication mode: ' . $raw . '.',
    ];

    return $cache;
}

/**
 * Environment constants for the active ABDM M2 configuration.
 *
 * Derived from the caller's own $conn rather than from m2_config.php because the
 * public callbacks cannot load m2_config.php: it pulls in includes/db.php, which
 * sets the global $conn to null when there is no session (see db.php:7-10).
 *
 * @param mysqli $conn
 * @return array{base_url:string, cm_id:string}|null
 */
function abdmM2JwtEnvironment($conn)
{
    if (!($conn instanceof mysqli)) {
        return null;
    }

    $sql = "SELECT environment FROM abdm_m2_settings WHERE status = 'Active' ORDER BY id DESC LIMIT 1";

    try {
        $stmt = $conn->prepare($sql);

        if ($stmt === false) {
            return null;
        }

        $executed = $stmt->execute();

        if ($executed === false) {
            @$stmt->close();

            return null;
        }

        $result = $stmt->get_result();
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        @$stmt->close();
    } catch (Throwable $e) {
        return null;
    }

    // A failed lookup falls back to Sandbox, matching the existing behaviour of
    // m2_config.php:71-77 so the two cannot disagree about which CM is in play.
    $environment = trim((string)($row['environment'] ?? ''));

    if ($environment === 'Production') {
        return ['base_url' => 'https://apis.abdm.gov.in/api', 'cm_id' => 'abdm'];
    }

    return ['base_url' => 'https://dev.abdm.gov.in/api', 'cm_id' => 'sbx'];
}

/**
 * Decode a base64url segment into raw bytes.
 *
 * @param string $input
 * @return string|false
 */
function abdmM2JwtBase64UrlDecode($input)
{
    $remainder = strlen($input) % 4;

    if ($remainder !== 0) {
        $input .= str_repeat('=', 4 - $remainder);
    }

    $decoded = base64_decode(strtr($input, '-_', '+/'), true);

    return $decoded === false ? false : $decoded;
}

/**
 * Split a compact JWS into its three segments.
 *
 * @param string $jwt
 * @return array{header:string, payload:string, signature:string}|null
 */
function abdmM2JwtSplit($jwt)
{
    $parts = explode('.', $jwt);

    if (count($parts) !== 3) {
        return null;
    }

    if ($parts[0] === '' || $parts[1] === '' || $parts[2] === '') {
        return null;
    }

    return [
        'header'    => $parts[0],
        'payload'   => $parts[1],
        'signature' => $parts[2],
    ];
}

/**
 * Extract the inbound bearer token.
 *
 * HTTP_AUTHORIZATION is not populated under every SAPI/Apache combination. When
 * mod_php strips it, apache_request_headers() still exposes it, so both are
 * consulted. The token itself is never logged or returned to the caller.
 *
 * @return string Empty string when no bearer token was sent.
 */
function abdmM2JwtReadBearerToken()
{
    $candidates = [];

    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $candidates[] = (string)$_SERVER['HTTP_AUTHORIZATION'];
    }

    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $candidates[] = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();

        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string)$name, 'Authorization') === 0 && is_string($value)) {
                    $candidates[] = $value;
                }
            }
        }
    }

    foreach ($candidates as $candidate) {
        $candidate = trim($candidate);

        if (stripos($candidate, 'bearer ') !== 0) {
            continue;
        }

        $token = trim(substr($candidate, 7));

        if ($token !== '') {
            return $token;
        }
    }

    return '';
}

/**
 * Decode the JOSE header WITHOUT verifying anything.
 *
 * Used by observe mode to learn what the gateway sent, and by the verifier to
 * learn which algorithm to pin and which key to select. Returns no claim values.
 *
 * @param string $jwt
 * @return array{ok:bool, reason:string, alg:string, kid:string}
 */
function abdmM2JwtParseHeader($jwt)
{
    $fail = function ($reason) {
        return ['ok' => false, 'reason' => $reason, 'alg' => '', 'kid' => ''];
    };

    $segments = abdmM2JwtSplit($jwt);

    if ($segments === null) {
        return $fail('segment_count');
    }

    $raw = abdmM2JwtBase64UrlDecode($segments['header']);

    if ($raw === false) {
        return $fail('header_not_base64url');
    }

    $header = json_decode($raw, true);

    if (!is_array($header)) {
        return $fail('header_not_json');
    }

    $alg = isset($header['alg']) ? strtoupper(trim((string)$header['alg'])) : '';
    $kid = isset($header['kid']) ? trim((string)$header['kid']) : '';

    // Report the empty-algorithm case explicitly so the observe-mode log
    // distinguishes "no alg" from "alg: none", which are different failures.
    if ($alg === '') {
        return $fail('alg_absent');
    }

    if (!isset(ABDM_M2_JWT_ALG_MAP[$alg])) {
        return $fail('alg_not_allowed');
    }

    return ['ok' => true, 'reason' => '', 'alg' => $alg, 'kid' => $kid];
}

/**
 * Encode a DER length prefix.
 *
 * @param int $length
 * @return string
 */
function abdmM2JwtDerLength($length)
{
    if ($length < 0x80) {
        return chr($length);
    }

    $bytes = '';

    while ($length > 0) {
        $bytes = chr($length & 0xFF) . $bytes;
        $length >>= 8;
    }

    return chr(0x80 | strlen($bytes)) . $bytes;
}

/**
 * Wrap a payload in a DER TLV with the given tag.
 *
 * @param int   $tag
 * @param string $payload
 * @return string
 */
function abdmM2JwtDerWrap($tag, $payload)
{
    return chr($tag) . abdmM2JwtDerLength(strlen($payload)) . $payload;
}

/**
 * Build a DER INTEGER from raw unsigned big-endian bytes.
 *
 * A leading 0x00 is prepended when the high bit is set so the value is read as
 * a positive integer rather than a negative one.
 *
 * @param string $bytes
 * @return string
 */
function abdmM2JwtDerInteger($bytes)
{
    $bytes = ltrim($bytes, "\x00");

    if ($bytes === '') {
        $bytes = "\x00";
    }

    if (ord($bytes[0]) & 0x80) {
        $bytes = "\x00" . $bytes;
    }

    return abdmM2JwtDerWrap(0x02, $bytes);
}

/**
 * Convert a JWKS RSA public key into a PEM public key.
 *
 * This performs the DER encoding by hand rather than pulling in a JWT library:
 * the application has no root composer.json and its vendor tree contains only
 * PhpSpreadsheet, TCPDF and their dependencies. Adding firebase/php-jwt would be
 * a new production dependency for one function.
 *
 * ASN.1 structure produced (X.509 SubjectPublicKeyInfo):
 *
 *   SEQUENCE {
 *     SEQUENCE { OID 1.2.840.113549.1.1.1, NULL }
 *     BIT STRING {
 *       0x00
 *       SEQUENCE { INTEGER n, INTEGER e }
 *     }
 *   }
 *
 * @param array $jwk A JWKS entry containing base64url `n` and `e`.
 * @return string|null PEM, or null when the key is unusable.
 */
function abdmM2JwksKeyToPem($jwk)
{
    if (!is_array($jwk) || empty($jwk['n']) || empty($jwk['e'])) {
        return null;
    }

    $modulus = abdmM2JwtBase64UrlDecode((string)$jwk['n']);
    $exponent = abdmM2JwtBase64UrlDecode((string)$jwk['e']);

    if ($modulus === false || $exponent === false || $modulus === '' || $exponent === '') {
        return null;
    }

    // rsaEncryption OID 1.2.840.113549.1.1.1, followed by the required NULL.
    $algorithmIdentifier = abdmM2JwtDerWrap(
        0x30,
        abdmM2JwtDerWrap(0x06, "\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01") . abdmM2JwtDerWrap(0x05, '')
    );

    $rsaPublicKey = abdmM2JwtDerWrap(
        0x30,
        abdmM2JwtDerInteger($modulus) . abdmM2JwtDerInteger($exponent)
    );

    // BIT STRING: first byte counts unused trailing bits, which is zero here
    // because every bit of the DER sequence is significant.
    $subjectPublicKeyInfo = abdmM2JwtDerWrap(
        0x30,
        $algorithmIdentifier . abdmM2JwtDerWrap(0x03, "\x00" . $rsaPublicKey)
    );

    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

/**
 * Fetch the gateway certificate set (JWKS).
 *
 * The response is cached on disk outside the document root for
 * ABDM_M2_JWKS_TTL seconds. sys_get_temp_dir() is used deliberately: it cannot
 * be served over HTTP, so no .htaccess rule is needed and the cached public keys
 * cannot be downloaded by a browser.
 *
 * @param string $baseUrl
 * @param string $cmId
 * @return array{ok:bool, reason:string, keys:array}
 */
function abdmM2JwtFetchJwks($baseUrl, $cmId)
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cacheFile = rtrim(sys_get_temp_dir(), '/\\')
        . DIRECTORY_SEPARATOR
        . 'websplend_abdm_jwks_' . substr(md5($cmId), 0, 12) . '.json';

    // Reuse a cached set while it is still fresh.
    if (is_readable($cacheFile)) {
        $age = time() - (int)@filemtime($cacheFile);

        if ($age >= 0 && $age < ABDM_M2_JWKS_TTL) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);

            if (is_array($cached) && !empty($cached['keys']) && is_array($cached['keys'])) {
                return $cache = ['ok' => true, 'reason' => 'cache_hit', 'keys' => $cached['keys']];
            }
        }
    }

    $requestId = abdmM2JwtRequestId();
    $timestamp = gmdate('Y-m-d\TH:i:s.v\Z');

    $ch = curl_init(rtrim($baseUrl, '/') . '/hiecm/gateway/v3/certs');

    curl_setopt_array($ch, [
        CURLOPT_HTTPGET        => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'REQUEST-ID: ' . $requestId,
            'TIMESTAMP: ' . $timestamp,
            'X-CM-ID: ' . $cmId,
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        return $cache = ['ok' => false, 'reason' => 'certs_transport_error', 'keys' => []];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return $cache = ['ok' => false, 'reason' => 'certs_http_' . $httpCode, 'keys' => []];
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        return $cache = ['ok' => false, 'reason' => 'certs_not_json', 'keys' => []];
    }

    /*
     * The endpoint normally answers with a JWKS (`keys`). Some deployments and
     * older documentation describe it as returning a single certificate, so a
     * PEM `certificate` value is also accepted and normalised into a one-entry
     * key list. A JWKS is preferred because it is what carries the `kid` needed
     * to select the right key.
     */
    if (!empty($decoded['keys']) && is_array($decoded['keys'])) {
        $keys = [];

        foreach ($decoded['keys'] as $key) {
            if (is_array($key) && isset($key['kty']) && strtoupper((string)$key['kty']) === 'RSA') {
                $keys[] = $key;
            }
        }

        if (!$keys) {
            return $cache = ['ok' => false, 'reason' => 'certs_no_rsa_keys', 'keys' => []];
        }

        @file_put_contents($cacheFile, json_encode(['keys' => $keys]), LOCK_EX);

        return $cache = ['ok' => true, 'reason' => 'fetched', 'keys' => $keys];
    }

    $pem = '';

    foreach (['certificate', 'cert', 'publicKey'] as $field) {
        if (!empty($decoded[$field]) && is_string($decoded[$field])) {
            $pem = trim($decoded[$field]);

            break;
        }
    }

    if ($pem === '' || strpos($pem, '-----BEGIN') === false) {
        return $cache = ['ok' => false, 'reason' => 'certs_unrecognised_shape', 'keys' => []];
    }

    @file_put_contents($cacheFile, json_encode(['pem' => $pem]), LOCK_EX);

    return $cache = ['ok' => true, 'reason' => 'fetched_pem', 'keys' => [['pem' => $pem]]];
}

/**
 * Generate a request identifier for the certificate call.
 *
 * Uses the same UUIDv4 shape as abdmM2GenerateRequestId() so the two are
 * indistinguishable to the gateway. This file deliberately does not require
 * m2_auth.php: that pulls in m2_config.php, which loads includes/db.php and
 * would clobber the $conn a public callback depends on.
 *
 * @return string
 */
function abdmM2JwtRequestId()
{
    return sprintf(
        '%08s-%04s-%04s-%04s-%012s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(6))
    );
}

/**
 * Verify a compact JWS against the gateway certificate set.
 *
 * Claim VALUES are deliberately not returned. `exp` and `nbf` are validated but
 * reduced to a boolean, so no caller can accidentally log or return them.
 *
 * @param string $jwt
 * @param array  $parsed Result of abdmM2JwtParseHeader().
 * @param string $baseUrl
 * @param string $cmId
 * @return array{ok:bool, reason:string}
 */
function abdmM2JwtVerify($jwt, $parsed, $baseUrl, $cmId)
{
    $fail = function ($reason) {
        return ['ok' => false, 'reason' => $reason];
    };

    if (empty($parsed['ok'])) {
        return $fail($parsed['reason'] !== '' ? $parsed['reason'] : 'header_rejected');
    }

    // Pinned here rather than passed in from the JWT itself.
    $opensslAlgo = ABDM_M2_JWT_ALG_MAP[$parsed['alg']];

    $jwks = abdmM2JwtFetchJwks($baseUrl, $cmId);

    if (empty($jwks['ok'])) {
        return $fail('jwks_unavailable:' . $jwks['reason']);
    }

    $segments = abdmM2JwtSplit($jwt);

    if ($segments === null) {
        return $fail('segment_count');
    }

    $signature = abdmM2JwtBase64UrlDecode($segments['signature']);

    if ($signature === false || $signature === '') {
        return $fail('signature_not_base64url');
    }

    // When the token names a key, only that key is considered. Falling back to
    // every key on a miss would let an attacker present a token signed by an
    // unrelated ABDM key.
    $keys = [];

    if ($parsed['kid'] !== '') {
        foreach ($jwks['keys'] as $key) {
            if (isset($key['kid']) && (string)$key['kid'] === $parsed['kid']) {
                $keys[] = $key;

                break;
            }
        }

        if (!$keys) {
            return $fail('kid_not_in_jwks');
        }
    } else {
        $keys = $jwks['keys'];
    }

    $signingInput = $segments['header'] . '.' . $segments['payload'];

    $verified = false;
    $keyTried = 0;

    foreach ($keys as $key) {
        // The single-PEM shape from abdmM2JwtFetchJwks() has no JWK members.
        $pem = isset($key['pem']) ? (string)$key['pem'] : abdmM2JwksKeyToPem($key);

        if ($pem === null || $pem === '') {
            continue;
        }

        $keyTried++;
        $publicKey = @openssl_pkey_get_public($pem);

        if ($publicKey === false) {
            continue;
        }

        $result = @openssl_verify($signingInput, $signature, $publicKey, $opensslAlgo);
        unset($publicKey);

        if ($result === 1) {
            $verified = true;

            break;
        }
    }

    if (!$verified) {
        return $fail($keyTried === 0 ? 'no_usable_key' : 'signature_invalid');
    }

    /*
     * Signature is now proven. Validate lifetime from the payload. The payload
     * is only reached here because the signature over it already verified, so
     * these values are gateway-authored and not attacker-supplied.
     */
    $rawPayload = abdmM2JwtBase64UrlDecode($segments['payload']);

    if ($rawPayload === false) {
        return $fail('payload_not_base64url');
    }

    $claims = json_decode($rawPayload, true);

    if (!is_array($claims)) {
        return $fail('payload_not_json');
    }

    $now = time();

    if (!isset($claims['exp']) || !is_numeric($claims['exp'])) {
        return $fail('exp_absent');
    }

    if ((int)$claims['exp'] + ABDM_M2_JWT_CLOCK_SKEW < $now) {
        return $fail('exp_expired');
    }

    if (isset($claims['nbf']) && is_numeric($claims['nbf'])) {
        if ((int)$claims['nbf'] - ABDM_M2_JWT_CLOCK_SKEW > $now) {
            return $fail('nbf_in_future');
        }
    }

    return ['ok' => true, 'reason' => 'verified'];
}

/**
 * Record which ABDM headers arrived on an inbound callback.
 *
 * This is the Phase 0 observation probe. It records header NAMES and presence
 * only, plus the declared algorithm and key id, so the evidence needed to decide
 * whether enforce mode is safe can be gathered without recording a token.
 *
 * The body length is passed in rather than re-read from php://input, because the
 * caller has already consumed the input stream once and the body may be large.
 *
 * @param string $transactionId
 * @param string $requestId
 * @param int    $bodyLength
 * @return void
 */
function abdmM2JwtObserveHeaders($transactionId, $requestId, $bodyLength = 0)
{
    $watched = [
        'HTTP_AUTHORIZATION',
        'REDIRECT_HTTP_AUTHORIZATION',
        'HTTP_X_HIP_ID',
        'HTTP_X_HIU_ID',
        'HTTP_X_CM_ID',
        'HTTP_REQUEST_ID',
        'HTTP_TIMESTAMP',
    ];

    $present = [];

    foreach ($watched as $key) {
        if (!empty($_SERVER[$key])) {
            $present[] = str_replace('HTTP_', '', $key);
        }
    }

    sort($present);

    abdmM2JwtLog([
        'event'         => 'inbound_observed',
        'transactionId' => $transactionId,
        'requestId'     => $requestId,
        'headers'       => $present === [] ? 'NONE' : implode('+', $present),
        'body_bytes'    => (int)$bodyLength,
    ]);
}

/**
 * Decide whether an inbound ABDM callback may proceed.
 *
 * Called by every public ABDM callback that must authenticate its caller.
 *
 * The returned 'allow' value is the ONLY thing the caller should branch on when
 * deciding whether to PROCESS the request. In observe mode it is always true,
 * which is what makes shipping observe mode the safe first step: this function
 * cannot break the gateway or the Step 6 harness while it gathers evidence.
 *
 * The returned 'verified' flag is a separate, stricter question and callers may
 * gate TRUST on it even while 'allow' is true. It is true only when a gateway
 * session token was presented AND its signature was verified against the
 * HiECM certificate authority AND its lifetime was checked.
 *
 * This distinction is what lets the discovery endpoint accept ABHA-anchored
 * requests exactly as it did before (those rest on the X-HIP-ID facility check)
 * while refusing to act on caller-asserted demographics until the caller has
 * actually proven it is the ABDM gateway. Identity of the facility and identity
 * of the caller are different claims and are now answered by different checks.
 *
 * @param mysqli|null $conn
 * @return array{allow:bool, verified:bool, reason:string, mode:string}
 */
function abdmM2JwtEnforceInboundRequest($conn = null)
{
    if ($conn === null) {
        global $conn;
    }

    $state = abdmM2JwtAuthMode($conn);
    $mode = $state['mode'];

    if ($mode === 'off') {
        return ['allow' => true, 'verified' => false, 'reason' => 'auth_off', 'mode' => $mode];
    }

    $token = abdmM2JwtReadBearerToken();

    if ($token === '') {
        abdmM2JwtLog([
            'event' => 'no_bearer_token',
            'mode'  => $mode,
            'source' => $state['source'],
        ]);

        return [
            'allow'    => $mode !== 'enforce',
            'verified' => false,
            'reason'   => 'no_bearer_token',
            'mode'     => $mode,
        ];
    }

    $parsed = abdmM2JwtParseHeader($token);

    $env = abdmM2JwtEnvironment($conn);

    if ($env === null) {
        abdmM2JwtLog(['event' => 'environment_unresolved', 'mode' => $mode]);

        return [
            'allow'    => $mode !== 'enforce',
            'verified' => false,
            'reason'   => 'environment_unresolved',
            'mode'     => $mode,
        ];
    }

    if (empty($parsed['ok'])) {
        abdmM2JwtLog([
            'event' => 'header_rejected',
            'mode'  => $mode,
            'reason' => $parsed['reason'],
            'cm_id' => $env['cm_id'],
        ]);

        return [
            'allow'    => $mode !== 'enforce',
            'verified' => false,
            'reason'   => $parsed['reason'],
            'mode'     => $mode,
        ];
    }

    $verified = abdmM2JwtVerify($token, $parsed, $env['base_url'], $env['cm_id']);

    /*
     * The observation record. `alg` and `kid` are JWT header members, not secret
     * material; the token, signature and all claim values are never logged.
     */
    abdmM2JwtLog([
        'event'  => 'verify_attempt',
        'mode'   => $mode,
        'cm_id'  => $env['cm_id'],
        'alg'    => $parsed['alg'],
        'kid'    => $parsed['kid'] !== '' ? 'present' : 'absent',
        'result' => $verified['ok'] ? 'PASS' : 'FAIL',
        'reason' => $verified['reason'],
    ]);

    if (!$verified['ok']) {
        return [
            'allow'    => $mode !== 'enforce',
            'verified' => false,
            'reason'   => $verified['reason'],
            'mode'     => $mode,
        ];
    }

    return ['allow' => true, 'verified' => true, 'reason' => 'verified', 'mode' => $mode];
}