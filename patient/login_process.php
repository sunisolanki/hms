<?php
declare(strict_types=1);

/*
 * Error display is OFF unless HMS_DEBUG=1 is set in the environment.
 *
 * display_errors=1 printed absolute paths and SQL with every stack trace on a
 * public endpoint. hmsEnv() decides, so db_credentials.php is loaded first; it
 * has no side effects on include.
 */
require_once __DIR__ . '/../includes/db_credentials.php';

$hmsDebug = hmsEnv('HMS_DEBUG') === '1';

ini_set('display_errors', $hmsDebug ? '1' : '0');
ini_set('display_startup_errors', $hmsDebug ? '1' : '0');
error_reporting(E_ALL);

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '1');
ini_set('session.use_strict_mode', '1');

require_once __DIR__ . '/../includes/https.php';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);

session_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/tenant.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /patient/login.php');
    exit;
}

$hospitalId = trim($_POST['hospital_id'] ?? '');
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($hospitalId === '' || $username === '' || $password === '') {
    $_SESSION['patient_login_error'] = 'Hospital ID, mobile number and password are required.';
    header('Location: /patient/login.php');
    exit;
}

try {
    /*
     * Find hospital/tenant from the same central projects table
     * used by the existing hospital login.
     */
    $tenant = null;

    // Column list resolved defensively; see hmsTenantColumnList().
    $tenantColumns = hmsTenantColumnList([
        'id',
        'tenant_id',
        'hospital_id',
        'client_name AS hospital_name',
        'database_host',
        'database_port',
        'database_name',
        'database_user',
        'database_password',
        'deployment_type',
        'domain',
        'access_status',
    ], $controlConn);

    $stmt = $controlConn->prepare(
        'SELECT ' . $tenantColumns . '
         FROM projects
         WHERE hospital_id = ?
         LIMIT 1'
    );

    $stmt->bind_param("s", $hospitalId);
    $stmt->execute();

    $result = $stmt->get_result();
    $tenant = $result->fetch_assoc();

    $stmt->close();

    if (!$tenant) {
        $_SESSION['patient_login_error'] = 'Invalid Hospital ID.';
        header('Location: /patient/login.php');
        exit;
    }

    if ($tenant['access_status'] !== 'Allowed') {
        $_SESSION['patient_login_error'] = 'Hospital access is currently unavailable.';
        header('Location: /patient/login.php');
        exit;
    }

    /*
     * Connect to the selected hospital database.
     */
    require_once __DIR__ . '/../includes/db_credentials.php';
    $tenantCredentials = hmsTenantCredentials($tenant);

    $databaseHost = $tenantCredentials['host'];

    $conn = hmsConnectMysqli($tenantCredentials);

    if ($conn->connect_error) {
        throw new RuntimeException(sprintf(
            'Hospital database connection failed for "%s" at %s:%d (errno %d): %s',
            (string) ($tenantCredentials['db'] ?? '(no database name)'),
            (string) ($tenantCredentials['host'] ?? 'localhost'),
            (int) ($tenantCredentials['port'] ?? 3306),
            (int) $conn->connect_errno,
            (string) $conn->connect_error
        ));
    }

    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '+05:30'");

    /*
     * Patient username is the registered mobile number.
     */
    $stmt = $conn->prepare("
        SELECT
            id,
            uhid,
            title,
            first_name,
            last_name,
            mobile,
            email,
            username,
            password
        FROM patient_master
        WHERE username = ?
        AND mobile = ?
        LIMIT 1
    ");

    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();

    $result = $stmt->get_result();
    $patient = $result->fetch_assoc();

    $stmt->close();

    if (!$patient) {
        $conn->close();

        $_SESSION['patient_login_error'] = 'Invalid mobile number or password.';
        header('Location: /patient/login.php');
        exit;
    }

    /*
     * Verify the hashed patient password.
     */
    if (empty($patient['password']) || !password_verify($password, $patient['password'])) {
        $conn->close();

        $_SESSION['patient_login_error'] = 'Invalid mobile number or password.';
        header('Location: /patient/login.php');
        exit;
    }

    /*
     * Successful patient login.
     */
    session_regenerate_id(true);

    $_SESSION['patient_id'] = (int)$patient['id'];
    $_SESSION['patient_uhid'] = $patient['uhid'];
    $_SESSION['patient_name'] = trim(
        $patient['first_name'] . ' ' . $patient['last_name']
    );
    $_SESSION['patient_mobile'] = $patient['mobile'];
    $_SESSION['patient_email'] = $patient['email'];
    $_SESSION['patient_username'] = $patient['username'];

    /*
     * Keep tenant information in the patient session.
     */
    $_SESSION['patient_tenant_id'] = $tenant['tenant_id'];
    $_SESSION['patient_hospital_id'] = $tenant['hospital_id'];
    $_SESSION['patient_hospital_name'] = $tenant['hospital_name'];

    $conn->close();

    header('Location: /patient/dashboard.php');
    exit;

} catch (Throwable $e) {
    error_log(
        'PATIENT LOGIN ERROR | Hospital ID: ' .
        $hospitalId .
        ' | Username: ' .
        $username .
        ' | Error: ' .
        $e->getMessage()
    );

    $_SESSION['patient_login_error'] = 'Unable to process login. Please try again.';
    header('Location: /patient/login.php');
    exit;
}
