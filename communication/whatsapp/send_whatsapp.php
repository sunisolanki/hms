<?php
function sendWhatsApp($to, $message)
{
    global $conn;

    $cfg = mysqli_fetch_assoc(
        mysqli_query($conn, "SELECT * FROM whatsapp_settings WHERE is_active=1 LIMIT 1")
    );

    if (!$cfg) return false;

    $payload = [
        "messaging_product" => "whatsapp",
        "to" => $to,
        "type" => "text",
        "text" => ["body" => $message]
    ];

    $ch = curl_init($cfg['api_url']);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$cfg['access_token']}",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);

    return true;
}
