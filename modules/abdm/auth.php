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

    $stmt = $conn->prepare("
        SELECT environment, client_id, client_secret, benefit_name, status
        FROM abdm_m1_settings
        WHERE status = 'Active'
        ORDER BY id DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return [
            'success' => false,
            'http_code' => 0,
            'error' => 'Unable to prepare M1 settings query.',
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
            'error' => 'Active ABDM M1 settings not found.',
            'response' => null
        ];
    }

    $clientId = trim($settings['client_id']);
    $clientSecret = trim($settings['client_secret']);

    if ($clientId === '' || $clientSecret === '') {
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

    return [
        'success' => false,
        'http_code' => $httpCode,
        'error' => $data['error'] ?? $data['message'] ?? 'ABDM authentication failed.',
        'response' => $data ?: $response
    ];
}