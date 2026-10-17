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
        /*
         * The driver detail goes into the exception so it reaches the server
         * error log, never the HTTP response. index.php shows the operator a
         * generic message and logs the exception; the errno is what actually
         * identifies the cause.
         *
         *   1044 / 1049  user exists, but no GRANT on that schema
         *   1045         wrong password, or the host is not allowlisted
         *   2003 (110)   timed out - firewall or IP allowlist
         *   2003 (111)   connection refused - wrong port, nothing listening
         *   2005         DNS failure, or the host name is a typo
         *   2006         TLS handshake rejected - retry with HMS_DB_SSL=0
         *
         * host:port and the database name are included deliberately: on a
         * managed host they are not secrets, and without them a misconfigured
         * port cannot be diagnosed from the log at all. The password is never
         * included.
         */
        throw new RuntimeException(sprintf(
            'Tenant database connection failed for "%s" at %s:%d (errno %d): %s',
            (string) ($credentials['db'] ?? '(no database name)'),
            (string) ($credentials['host'] ?? 'localhost'),
            (int) ($credentials['port'] ?? 3306),
            (int) $conn->connect_errno,
            (string) $conn->connect_error
        ));
    }

    $conn->set_charset("utf8mb4");
    $conn->query("SET time_zone = '+05:30'");

    return $conn;
}