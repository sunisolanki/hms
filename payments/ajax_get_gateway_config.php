<?php
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$gateway = $_GET['gateway'] ?? '';

if (!$gateway) {
    echo json_encode(null);
    exit;
}

$stmt = $conn->prepare("
    SELECT 
        gateway,
        mode,
        merchant_key,
        merchant_secret,
        api_key,
        api_secret,
        webhook_secret,
        is_active
    FROM payment_gateway_config
    WHERE gateway = ?
    LIMIT 1
");

$stmt->bind_param("s", $gateway);
$stmt->execute();
$res = $stmt->get_result();

$data = $res->fetch_assoc();
$stmt->close();

echo json_encode($data ?: null);
