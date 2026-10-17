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

    $controlHost = "localhost";
    $controlDb = "u336643111_control";
    $controlUser = "u336643111_control";
    $controlPass = "Rohtha@751";

    $controlConn = new mysqli(
        $controlHost,
        $controlUser,
        $controlPass,
        $controlDb
    );

    if ($controlConn->connect_error) {
        return [
            'allowed' => false,
            'message' => 'Unable to connect to Control database.'
        ];
    }

    $controlConn->set_charset("utf8mb4");
    $controlConn->query("SET time_zone = '+05:30'");

    $stmt = $controlConn->prepare("
        SELECT
            tenant_id,
            hospital_id,
            client_name AS hospital_name,
            access_status,
            database_host,
            database_name,
            database_user,
            database_password
        FROM projects
        WHERE tenant_id = ?
        LIMIT 1
    ");

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