<?php
require_once __DIR__ . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/abdm_helper.php';

function abdmGetAccessToken()
{
    global $conn;

    /*
     * GLOBAL ABDM SWITCH
     *
     * Central chokepoint for every outbound M1 call: all abha_*.php endpoints
     * obtain their token here. When the installation has ABDM switched off, or
     * when the setting cannot be read, no gateway request is made at all.
     */
    if (!abdmOutboundAllowed($conn)) {
        $switch = abdmGetGlobalSwitch($conn);

        return [
            'success' => false,
            'http_code' => 0,
            'error' => $switch['state'] === 'error'
                ? 'ABDM is unavailable because the Global ABDM setting could not be read.'
                : 'ABDM is disabled for this installation.',
            'response' => null
        ];
    }

    /*
     * PHP 8.1+ defaults mysqli to MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT,
     * so prepare()/execute()/get_result() THROW mysqli_sql_exception rather than
     * returning false on failure. Uncaught, a missing or renamed
     * `abdm_m1_settings` becomes a fatal 500 that names no cause, and the
     * `if (!$stmt)` guard below would be unreachable dead code.
     *
     * Caught, it degrades to the same structured failure every other branch
     * returns, which the callers log with a usable reason. This mirrors the
     * established handling in includes/abdm_helper.php (abdmReadMasterRow) and
     * modules/abdm/m2_jwt_verifier.php.
     */
    try {
        $stmt = $conn->prepare("
            SELECT environment, client_id, client_secret, benefit_name, status
            FROM abdm_m1_settings
            WHERE status = 'Active'
            ORDER BY id DESC
            LIMIT 1
        ");

        if ($stmt === false) {
            throw new RuntimeException('prepare_failed errno=' . (int)$conn->errno);
        }

        $stmt->execute();

        $result = $stmt->get_result();

        if (!($result instanceof mysqli_result)) {
            throw new RuntimeException('get_result_failed errno=' . (int)$conn->errno);
        }

        $settings = $result->fetch_assoc();
        @$stmt->close();
    } catch (Throwable $e) {
        // Server-side diagnostic only. No SQL text, credential or identifier.
        error_log('[ABDM-M1] abdm_m1_settings read failed: ' . $e->getMessage());

        return [
            'success' => false,
            'http_code' => 0,
            'error' => 'Unable to read ABDM M1 settings.',
            'response' => null
        ];
    }

    if (!$settings) {
        return [
            'success' => false,
            'http_code' => 0,
            'error' => 'Active ABDM M1 settings not found.',
            'response' => null
        ];
    }

    /*
     * Cast to string before trim(). A NULL column reaches trim() directly on
     * PHP 8.1+, which raises "Passing null to parameter #1 ($string) of type
     * string is deprecated" and yields ''. That deprecation is written to the
     * error log without a stack trace, so it reads as unrelated noise while
     * silently producing the misleading "Client ID or Client Secret is missing"
     * result below. (string) makes the NULL case explicit and quiet.
     */
    $clientId = trim((string)($settings['client_id'] ?? ''));
    $clientSecret = trim((string)($settings['client_secret'] ?? ''));

    if ($clientId === '' || $clientSecret === '') {
        error_log(
            '[ABDM-M1] stored credentials unusable'
            . ' | environment=' . (string)($settings['environment'] ?? '?')
            . ' | status=' . (string)($settings['status'] ?? '?')
            . ' | client_id=' . ($clientId === '' ? 'EMPTY' : 'set(' . strlen($clientId) . ')')
            . ' | client_secret=' . ($clientSecret === '' ? 'EMPTY' : 'set(' . strlen($clientSecret) . ')')
        );

        return [
            'success' => false,
            'http_code' => 0,
            'error' => 'ABDM M1 Client ID or Client Secret is missing.',
            'response' => null
        ];
    }

    $payload = [
        'clientId' => $clientId,
        'clientSecret' => $clientSecret
    ];

    $ch = curl_init(ABDM_SESSION_URL);

    /*
     * TLS VERIFICATION IS DELIBERATELY ON.
     *
     * CURLOPT_SSL_VERIFYPEER => true and CURLOPT_SSL_VERIFYHOST => 2 must not be
     * relaxed to false/0 to "fix" a production failure. This request carries the
     * clientSecret; disabling verification would turn any man-in-the-middle on
     * the network between Render and ABDM into full credential disclosure.
     *
     * If the container cannot verify ABDM's certificate, cURL reports errno 60
     * below and the correct fix is a real trust store - install ca-certificates
     * in the Dockerfile, or point CURLOPT_CAINFO at a bundle - not weaker checks.
     *
     *   6  = DNS resolution failed
     *   7  = connection refused (wrong port / nothing listening)
     *   28 = timeout (ABDM sandbox slow or unreachable)
     *   35 = TLS handshake rejected
     *   60 = CA bundle missing or invalid  <-- the trust store, not the code
     */
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => ABDM_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);

    curl_close($ch);

    /*
     * curl_errno() is what actually separates the failure modes. curl_error()
     * on its own is frequently a generic "SSL peer certificate or SSH remote key
     * was not OK" or "Operation timed out", which cannot be acted on.
     *
     * The body is logged on the FAILURE path ONLY. On success this same response
     * carries accessToken and refreshToken, which must never reach a log sink.
     * The clientSecret was sent in the request body and is never in the response,
     * but the truncation is kept as defence in depth.
     */
    if ($response === false || $curlErrno !== 0) {
        error_log(
            '[ABDM-M1] session token request failed'
            . ' | url=' . ABDM_SESSION_URL
            . ' | curl_errno=' . (int)$curlErrno
            . ' | curl_error=' . $curlError
            . ' | http_code=' . (int)$httpCode
            . ' | body=' . substr((string)$response, 0, 500)
        );

        return [
            'success' => false,
            'http_code' => $httpCode,
            'error' => 'cURL Error ' . (int)$curlErrno . ': ' . $curlError,
            'response' => null
        ];
    }

    $data = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && is_array($data) && !empty($data['accessToken'])) {
        return [
            'success' => true,
            'http_code' => $httpCode,
            'access_token' => $data['accessToken'],
            'expires_in' => $data['expiresIn'] ?? null,
            'refresh_token' => $data['refreshToken'] ?? null,
            'refresh_expires_in' => $data['refreshExpiresIn'] ?? null,
            'token_type' => $data['tokenType'] ?? 'bearer',
            'response' => $data
        ];
    }

    /*
     * The transport succeeded but ABDM refused to issue a token: the stored
     * client_id / client_secret are wrong, revoked, or not registered against
     * this facility. That distinction matters - it is a credentials/registration
     * problem, not a network one - so it gets its own log line.
     *
     * Only the gateway's error code and message are logged. The request body
     * holding the clientSecret is never written here, and the response of a
     * failed /sessions call contains no token.
     */
    error_log(
        '[ABDM-M1] session token rejected'
        . ' | http_code=' . (int)$httpCode
        . ' | error=' . substr(
            (string)($data['error'] ?? $data['message'] ?? $data['error_description'] ?? 'unknown'),
            0,
            300
        )
        . ' | body=' . substr((string)$response, 0, 500)
    );

    return [
        'success' => false,
        'http_code' => $httpCode,
        'error' => $data['error'] ?? $data['message'] ?? 'ABDM authentication failed.',
        'response' => $data ?: $response
    ];
}