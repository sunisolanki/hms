<?php
function connectTenantDatabase(array $tenant): mysqli
{
    require_once __DIR__ . '/db_credentials.php';

    $credentials = hmsTenantCredentials($tenant);

    $host = $credentials['host'];
    $database = $credentials['db'];
    $username = $credentials['user'];
    $password = $credentials['pass'];

    $conn = hmsConnectMysqli([
        'host' => $host,
        'db'   => $database,
        'user' => $username,
        'pass' => $password,
        'port' => $credentials['port'] ?? 3306,
    ]);

    if ($conn->connect_error) {
        throw new RuntimeException(
            "Tenant database connection failed."
        );
    }

    $conn->set_charset("utf8mb4");
    $conn->query("SET time_zone = '+05:30'");

    return $conn;
}