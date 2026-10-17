<?php
date_default_timezone_set('Asia/Kolkata');

header('Content-Type: application/json');

$requestId = $_SERVER['HTTP_REQUEST_ID'] ?? '';
$timestamp = $_SERVER['HTTP_TIMESTAMP'] ?? '';
$rawBody = file_get_contents('php://input');

$headers = [
    'REQUEST-ID' => $requestId,
    'TIMESTAMP' => $timestamp,
    'CONTENT-TYPE' => $_SERVER['CONTENT_TYPE'] ?? ''
];

$logData = [
    'received_at' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'headers' => $headers,
    'body' => $rawBody
];

error_log(
    'ABDM CALLBACK: ' .
    json_encode($logData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);

http_response_code(200);

echo json_encode([
    'success' => true,
    'message' => 'ABDM callback received.'
]);