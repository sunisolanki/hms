<?php
function sendSMS($mobile, $message)
{
    global $conn;

    $cfg = mysqli_fetch_assoc(
        mysqli_query($conn, "SELECT * FROM sms_settings WHERE is_active=1 LIMIT 1")
    );

    if (!$cfg) return false;

    if (empty($cfg['api_url']) || empty($cfg['api_key']) || empty($cfg['sender_id'])) {
        error_log('SMS | provider configuration incomplete; refusing to send.');
        return false;
    }

    $url = $cfg['api_url'] .
        "?apikey={$cfg['api_key']}" .
        "&sender={$cfg['sender_id']}" .
        "&to={$mobile}" .
        "&message=" . urlencode($message);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response  = curl_exec($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $provider = (string)($cfg['provider'] ?? 'unknown');

    // The URL carries apikey and the destination mobile and the body carries the
    // OTP, so neither is ever written to a log. Provider name and HTTP status only.
    if ($response === false || $curlError !== '') {
        error_log(
            'SMS | transport failure | provider=' . $provider .
            ' | http_code=' . $httpCode .
            ' | curl_error=' . ($curlError !== '' ? 'YES' : 'NO')
        );
        return false;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log(
            'SMS | provider rejected request | provider=' . $provider .
            ' | http_code=' . $httpCode
        );
        return false;
    }

    error_log('SMS | dispatched | provider=' . $provider . ' | http_code=' . $httpCode);

    return true;
}
