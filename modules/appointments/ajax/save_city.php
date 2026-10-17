<?php
/*
 |--------------------------------------------------------------------------
 | SAVE CITY (appointment / registration master data)
 |--------------------------------------------------------------------------
 | Batch 1 security remediation.
 |
 | Previously this endpoint had NO authentication and NO authorization, and
 | interpolated the caller-supplied city name straight into both SQL strings:
 |
 |     INSERT IGNORE INTO cities (district_id,name) VALUES ($district,'$city')
 |     SELECT id FROM cities WHERE name='$city' AND district_id=$district
 |
 | It also imported includes/public_tenant.php, which lets a caller-supplied
 | tenant_id overwrite $_SESSION['tenant_id']. The tenant context now comes
 | from the session only, matching modules/appointments/ajax/get_patients_list.php.
 |
 | Response shape is unchanged: {"status":"OK"|"EXISTS","id":..,"name":..}
 | callers read d.id and d.name.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/permissions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Emit a refusal and stop. The message is deliberately coarse so a caller
 * cannot use it to probe for valid sessions, roles or tenants.
 */
function saveCityRefuse($httpCode, $message)
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'ERROR',
        'message' => $message
    ]);
    exit;
}

/* 1. Authenticated staff session. */
if (empty($_SESSION['user_id'])) {
    saveCityRefuse(401, 'Authentication required.');
}

/* 2. Tenant + connection come from the session via includes/db.php only.
   A caller-supplied tenant_id is deliberately ignored. */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';

if (!($conn instanceof mysqli)) {
    saveCityRefuse(403, 'Tenant context unavailable.');
}

/* 3. Permission - master-data write performed from the registration and
   appointment forms. Both of those modules grant 'create' to the same roles. */
if (!hasAnyPermission(['patient_registration', 'appointments'], 'create')) {
    saveCityRefuse(403, 'Access denied.');
}

/* 4. Only POST is accepted. */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    saveCityRefuse(405, 'Only POST method is allowed.');
}

header('Content-Type: application/json; charset=utf-8');

/* 5. Validate and normalise input. */
$districtId = (int)($_POST['district_id'] ?? 0);
$city       = trim((string)($_POST['city'] ?? ''));

if ($districtId <= 0) {
    saveCityRefuse(400, 'Invalid district ID.');
}

/* Strip control characters and collapse internal whitespace. */
$city = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $city);
$city = preg_replace('/\s+/u', ' ', (string)$city);
$city = trim($city);

if ($city === '') {
    saveCityRefuse(400, 'City name is required.');
}

if (mb_strlen($city, 'UTF-8') > 100) {
    saveCityRefuse(400, 'City name is too long.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    /* 6. Insert. The unique key on (district_id, name) makes INSERT IGNORE the
       correct way to detect an existing row without a read-then-write race. */
    $insertStmt = $conn->prepare(
        'INSERT IGNORE INTO cities (district_id, name) VALUES (?, ?)'
    );
    if ($insertStmt === false) {
        throw new RuntimeException('prepare failed');
    }
    $insertStmt->bind_param('is', $districtId, $city);
    $insertStmt->execute();
    $newId = (int)$insertStmt->insert_id;
    $insertStmt->close();

    if ($newId > 0) {
        echo json_encode(['status' => 'OK', 'id' => $newId, 'name' => $city]);
        exit;
    }

    /* 7. Already existed - look the id up with a prepared statement. */
    $selectStmt = $conn->prepare(
        'SELECT id FROM cities WHERE name = ? AND district_id = ? LIMIT 1'
    );
    if ($selectStmt === false) {
        throw new RuntimeException('prepare failed');
    }
    $selectStmt->bind_param('si', $city, $districtId);
    $selectStmt->execute();
    $existing = $selectStmt->get_result()->fetch_assoc();
    $selectStmt->close();

    echo json_encode([
        'status' => 'EXISTS',
        'id'     => $existing ? (int)$existing['id'] : 0,
        'name'   => $city
    ]);
} catch (Throwable $e) {
    error_log('save_city: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status'  => 'ERROR',
        'message' => 'Unable to save city.'
    ]);
}

exit;