<?php
require_once __DIR__ . '/m2_config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/abdm_helper.php';
function abdmM2GenerateRequestId()
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
function abdmM2GetAccessToken()
{
    global $conn;

    /*
     * GLOBAL ABDM SWITCH
     *
     * Central chokepoint for every outbound M2 call. When the installation has
     * ABDM switched off, or when the setting cannot be read, no gateway request
     * is made at all.
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

    $stmt = $conn->prepare("
        SELECT environment, bridge_id, status
        FROM abdm_m2_settings
        WHERE status = 'Active'
        ORDER BY id DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return [
            'success' => false,
            'http_code' => 0,
            'error' => 'Unable to prepare M2 settings query.',
            'response' => null
        ];
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $settings = $result->fetch_assoc();
    $stmt->close();
    if (!$settings) {
        return [
            'success' => false,
            'http_code' => 0,
            'error' => 'Active ABDM M2 settings not found.',
            'response' => null
        ];
    }
    if (empty($settings['bridge_id'])) {
        return [
            'success' => false,
            'http_code' => 0,
            'error' => 'M2 Bridge ID / Client ID is missing.',
            'response' => null
        ];
    }
    $m1Stmt = $conn->prepare("
        SELECT client_id, client_secret
        FROM abdm_m1_settings
        WHERE status = 'Active'
        ORDER BY id DESC
        LIMIT 1
    ");
    if (!$m1Stmt) {
        return [
            'success' => false,
            'http_code' => 0,
            'error' => 'Unable to prepare M1 credentials query.',
            'response' => null
        ];
    }
    $m1Stmt->execute();
    $m1Result = $m1Stmt->get_result();
    $m1Settings = $m1Result->fetch_assoc();
    $m1Stmt->close();
    if (!$m1Settings) {
        return [
            'success' => false,
            'http_code' => 0,
            'error' => 'Active M1 credentials not found.',
            'response' => null
        ];
    }
    $clientId = trim($m1Settings['client_id']);
    $clientSecret = trim($m1Settings['client_secret']);
    if ($clientId === '' || $clientSecret === '') {
        return [
            'success' => false,
            'http_code' => 0,
            'error' => 'M1 Client ID or Client Secret is missing.',
            'response' => null
        ];
    }
    $requestId = abdmM2GenerateRequestId();
    $timestamp = gmdate('Y-m-d\TH:i:s.v\Z');
    $payload = [
        'clientId' => $clientId,
        'clientSecret' => $clientSecret,
        'grantType' => 'client_credentials'
    ];
    $ch = curl_init(ABDM_M2_SESSION_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'REQUEST-ID: ' . $requestId,
            'TIMESTAMP: ' . $timestamp,
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
            'error' => 'cURL Error: ' . $curlError,
            'response' => null
        ];
    }
    $data = json_decode($response, true);
    if (
        $httpCode >= 200 &&
        $httpCode < 300 &&
        is_array($data) &&
        !empty($data['accessToken'])
    ) {
        /*
         * Development-only diagnostics. Guarded by ABDM_DIAGNOSTIC_LOG_ENABLED
         * (default false). When disabled this entire block is skipped, no file
         * is written, and the successful return below is reached exactly as it
         * always was - token generation is otherwise unchanged.
         *
         * Nothing sensitive is recorded: no access token, no client secret, no
         * OTP, no patient data and no raw gateway response body.
         */
        if (ABDM_DIAGNOSTIC_LOG_ENABLED) {
            file_put_contents(
                __DIR__ . '/m2_auth_test.log',
                date('Y-m-d H:i:s') .
                "\nHTTP CODE: " . $httpCode .
                "\nTOKEN RECEIVED: " . (!empty($data['accessToken']) ? 'YES' : 'NO') .
                "\nTOKEN TYPE: " . ($data['tokenType'] ?? '') .
                "\nEXPIRES IN: " . ($data['expiresIn'] ?? '') .
                "\nERROR: " . ($data['error'] ?? '') .
                "\nMESSAGE: " . ($data['message'] ?? '') .
                "\nREQUEST ID: " . $requestId .
                "\n--------------------\n",
                FILE_APPEND
            );
        }
        return [
            'success' => true,
            'http_code' => $httpCode,
            'access_token' => $data['accessToken'],
            'expires_in' => $data['expiresIn'] ?? null,
            'token_type' => $data['tokenType'] ?? 'bearer',
            'request_id' => $requestId,
            'response' => $data
        ];
    }
    return [
        'success' => false,
        'http_code' => $httpCode,
        'error' => $data['error'] ?? $data['message'] ?? 'ABDM M2 authentication failed.',
        'request_id' => $requestId,
        'response' => $data ?: $response
    ];
}
?>