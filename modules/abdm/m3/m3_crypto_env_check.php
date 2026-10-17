<?php
header('Content-Type: application/json; charset=utf-8');
$result = [
    'success' => true,
    'php_version' => PHP_VERSION,
    'openssl_version' => defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : null,
    'openssl_aes_256_gcm' => in_array('aes-256-gcm', openssl_get_cipher_methods(), true),
    'openssl_x25519' => defined('OPENSSL_KEYTYPE_X25519'),
    'gmp_extension' => extension_loaded('gmp'),
    'gmp_version' => extension_loaded('gmp') ? phpversion('gmp') : null,
    'sodium_extension' => extension_loaded('sodium'),
    'random_bytes' => function_exists('random_bytes'),
    'error' => null
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
