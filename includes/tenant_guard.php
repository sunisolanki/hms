<?php

function checkTenantAccess(): array
{
    if (empty($_SESSION['tenant_id'])) {
        return [
            'allowed' => false,
            'message' => 'Tenant session not found.'
        ];
    }

    $tenantId = $_SESSION['tenant_id'];

    /*
     * Credentials are resolved through the shared resolver. This function
     * previously carried its own copy of the production literals, so it ignored
     * both HMS_ENV=local and DATABASE_URL and could never reach the managed
     * control database.
     */
    require_once __DIR__ . '/db_credentials.php';

    $controlCredentials = hmsControlCredentials([
        'host' => "localhost",
        'db'   => "u336643111_control",
        'user' => "u336643111_control",
        'pass' => "Rohtha@751"
    ]);

    $controlConn = hmsConnectMysqli($controlCredentials);

    if ($controlConn->connect_error) {
        return [
            'allowed' => false,
            'message' => 'Unable to connect to Control database.'
        ];
    }

    $controlConn->set_charset("utf8mb4");
    $controlConn->query("SET time_zone = '+05:30'");

    // Column list resolved defensively; see hmsTenantColumnList().
    $tenantColumns = hmsTenantColumnList([
        'tenant_id',
        'hospital_id',
        'client_name AS hospital_name',
        'access_status',
        'database_host',
        'database_port',
        'database_name',
        'database_user',
        'database_password',
    ], $controlConn);

    $stmt = $controlConn->prepare(
        'SELECT ' . $tenantColumns . '
         FROM projects
         WHERE tenant_id = ?
         LIMIT 1'
    );

    if (!$stmt) {
        $controlConn->close();

        return [
            'allowed' => false,
            'message' => 'Tenant verification failed.'
        ];
    }

    $stmt->bind_param("s", $tenantId);
    $stmt->execute();

    $result = $stmt->get_result();
    $tenant = $result->fetch_assoc();

    $stmt->close();
    $controlConn->close();

    if (!$tenant) {
        return [
            'allowed' => false,
            'message' => 'Tenant not found.'
        ];
    }

    if ($tenant['access_status'] !== 'Allowed') {
        return [
            'allowed' => false,
            'message' => 'HMS access is currently blocked.'
        ];
    }

    return [
        'allowed' => true,
        'tenant' => $tenant
    ];
}
