<?php
date_default_timezone_set('Asia/Kolkata');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$tenantId = $_SESSION['tenant_id'] ?? '';
if ($tenantId === '') {
    $conn = null;
    return;
}
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
$controlConn = hmsConnectMysqli($controlCredentials);
if ($controlConn->connect_error) {
    die("Control database connection failed.");
}
$controlConn->set_charset("utf8mb4");
$controlConn->query("SET time_zone = '+05:30'");
$stmt = $controlConn->prepare("
    SELECT
        tenant_id,
        hospital_id,
        database_host,
        database_name,
        database_user,
        database_password,
        access_status
    FROM projects
    WHERE tenant_id = ?
    LIMIT 1
");
if (!$stmt) {
    $controlConn->close();
    die("Tenant lookup failed.");
}
$stmt->bind_param("s", $tenantId);
$stmt->execute();
$result = $stmt->get_result();
$tenant = $result->fetch_assoc();
$stmt->close();
$controlConn->close();
if (!$tenant) {
    die("Invalid Tenant.");
}
if ($tenant['access_status'] !== 'Allowed') {
    $blockedTenantId = $tenant['tenant_id'];
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
    header(
        "Location: /login/tenant_blocked.php?tenant_id=" .
        urlencode($blockedTenantId)
    );
    exit;
}
$tenantCredentials = hmsTenantCredentials($tenant);
$databaseHost = $tenantCredentials['host'];
$conn = new mysqli(
    $databaseHost,
    $tenantCredentials['user'],
    $tenantCredentials['pass'],
    $tenantCredentials['db']
);
if ($conn->connect_error) {
    die("Tenant database connection failed.");
}
$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+05:30'");