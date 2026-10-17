<?php
declare(strict_types=1);

/*
 * HMS Tenant Helper
 *
 * Retrieves tenant information from the central Control database.
 */

/* Control Database */
require_once __DIR__ . '/db_credentials.php';
$controlCredentials = hmsControlCredentials([
    'host' => "localhost",
    'db'   => "u336643111_control",
    'user' => "u336643111_control",
    'pass' => "Rohtha@751"
]);
$controlHost = $controlCredentials['host'];
$controlDb   = $controlCredentials['db'];
$controlUser = $controlCredentials['user'];
$controlPass = $controlCredentials['pass'];

/* Create Control Database Connection */
$controlConn = hmsConnectMysqli($controlCredentials);

/* Check Connection */
if ($controlConn->connect_error) {
    die("Control database connection failed.");
}

/* Set Charset */
$controlConn->set_charset("utf8mb4");

/* Set MySQL Timezone */
$controlConn->query("SET time_zone = '+05:30'");

/**
 * Get tenant information from the central projects table.
 *
 * @param string $tenantId
 * @return array|null
 */
function getTenant(string $tenantId): ?array
{
    global $controlConn;

    $stmt = $controlConn->prepare("
        SELECT
            id,
            tenant_id,
            hospital_id,
            client_name AS hospital_name,
            database_host,
            database_port,
            database_name,
            database_user,
            database_password,
            deployment_type,
            domain,
            access_status
        FROM projects
        WHERE tenant_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("s", $tenantId);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $stmt->close();
        return $row;
    }

    $stmt->close();

    return null;
}
