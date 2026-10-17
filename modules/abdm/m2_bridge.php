<?php
require_once __DIR__ . '/m2_auth.php';

function abdmM2UpdateBridgeUrl($callbackUrl = null)
{
    global $conn;

    /*
     * GLOBAL ABDM SWITCH
     *
     * Bridge URL updates are an outbound M2 gateway operation. They are refused
     * while ABDM is off or while the setting cannot be read. The function's
     * existing return shape ('success' / 'http_code' / 'error') is preserved.
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
        SELECT bridge_id, callback_url, status
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

    if ($callbackUrl === null) {
        $callbackUrl = trim($settings['callback_url'] ?? '');
    } else {
        $callbackUrl = trim($callbackUrl);
    }

    if ($callbackUrl === '') {
        return [
            'success' => false,
            'http_code' => 0,
            'error' => 'M2 Callback URL is missing.',
            'response' => null
        ];
    }

    $auth = abdmM2GetAccessToken();

    if (empty($auth['success'])) {
        return $auth;
    }

    $requestId = abdmM2GenerateRequestId();
    $timestamp = gmdate('Y-m-d\TH:i:s.v\Z');

    $payload = [
        'url' => $callbackUrl
    ];

    $ch = curl_init(ABDM_M2_BRIDGE_URL);

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $auth['access_token'],
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

    if ($httpCode >= 200 && $httpCode < 300) {
        return [
            'success' => true,
            'http_code' => $httpCode,
            'message' => 'ABDM bridge URL updated successfully.',
            'callback_url' => $callbackUrl,
            'request_id' => $requestId,
            'response' => $data ?: $response
        ];
    }

    return [
        'success' => false,
        'http_code' => $httpCode,
        'error' => $data['error'] ?? $data['message'] ?? 'ABDM bridge URL update failed.',
        'request_id' => $requestId,
        'response' => $data ?: $response
    ];
}

function abdmM2FindBridgeByServiceId()
{
    global $conn;

    /* GLOBAL ABDM SWITCH - refuse before any gateway request. */
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
        SELECT service_id, status
        FROM abdm_m2_settings
        WHERE status = 'Active'
        ORDER BY id DESC
        LIMIT 1
    ");

    if (!$stmt) {
        return [
            'success' => false,
            'http_code' => 0,
            'error' => 'Unable to prepare M2 Service ID query.',
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

    $serviceId = trim($settings['service_id'] ?? '');

    if ($serviceId === '') {
        return [
            'success' => false,
            'http_code' => 0,
            'error' => 'M2 Service ID is missing.',
            'response' => null
        ];
    }

    $auth = abdmM2GetAccessToken();

    if (empty($auth['success'])) {
        return $auth;
    }

    $requestId = abdmM2GenerateRequestId();
    $timestamp = gmdate('Y-m-d\TH:i:s.v\Z');

    $url = ABDM_M2_BASE_URL . '/hiecm/gateway/v3/bridge-service/serviceId/' . rawurlencode($serviceId);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $auth['access_token'],
            'REQUEST-ID: ' . $requestId,
            'TIMESTAMP: ' . $timestamp,
            'X-CM-ID: ' . ABDM_M2_CM_ID
        ],
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
            'service_id' => $serviceId,
            'response' => null
        ];
    }

    $data = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        return [
            'success' => true,
            'http_code' => $httpCode,
            'message' => 'Bridge details fetched successfully.',
            'service_id' => $serviceId,
            'request_id' => $requestId,
            'response' => $data ?: $response
        ];
    }

    return [
        'success' => false,
        'http_code' => $httpCode,
        'error' => $data['error'] ?? $data['message'] ?? 'Unable to fetch bridge details.',
        'service_id' => $serviceId,
        'request_id' => $requestId,
        'response' => $data ?: $response
    ];
}

function abdmM2FindServicesByBridgeId()
{
    global $conn;

    /* GLOBAL ABDM SWITCH - refuse before any gateway request. */
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
        SELECT bridge_id, status
        FROM abdm_m2_settings
        WHERE status = 'Active'
        ORDER BY id DESC
        LIMIT 1
    ");

    if (!$stmt) {
        return [
            'success' => false,
            'http_code' => 0,
            'error' => 'Unable to prepare M2 Bridge ID query.',
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

    $bridgeId = trim($settings['bridge_id'] ?? '');

    if ($bridgeId === '') {
        return [
            'success' => false,
            'http_code' => 0,
            'error' => 'M2 Bridge ID is missing.',
            'response' => null
        ];
    }

    $auth = abdmM2GetAccessToken();

    if (empty($auth['success'])) {
        return $auth;
    }

    $requestId = abdmM2GenerateRequestId();
    $timestamp = gmdate('Y-m-d\TH:i:s.v\Z');

    $url = ABDM_M2_BASE_URL . '/hiecm/gateway/v3/bridge-services';

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $auth['access_token'],
            'REQUEST-ID: ' . $requestId,
            'TIMESTAMP: ' . $timestamp,
            'X-CM-ID: ' . ABDM_M2_CM_ID,
            'X-BRIDGE-ID: ' . $bridgeId
        ],
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
            'bridge_id' => $bridgeId,
            'response' => null
        ];
    }

    $data = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        return [
            'success' => true,
            'http_code' => $httpCode,
            'message' => 'Bridge services fetched successfully.',
            'bridge_id' => $bridgeId,
            'request_id' => $requestId,
            'response' => $data ?: $response
        ];
    }

    return [
        'success' => false,
        'http_code' => $httpCode,
        'error' => $data['error'] ?? $data['message'] ?? 'Unable to fetch bridge services.',
        'bridge_id' => $bridgeId,
        'request_id' => $requestId,
        'response' => $data ?: $response
    ];
}
?>