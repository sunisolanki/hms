<?php
/**
 * Save handler for the GLOBAL ABDM ON/OFF switch.
 *
 * Authorization
 * -------------
 * Uses the application's existing permission model: checkPermission() from
 * includes/permissions.php against the existing 'abdm_settings' module slug.
 * No new permission module is created and no existing permission row is
 * modified. Only a role holding abdm_settings/update may change this setting,
 * which today means Super Admin.
 *
 * CSRF
 * ----
 * Uses the application's established token pattern from index.php
 * ($_SESSION['csrf'], bin2hex(random_bytes(32))), verified with hash_equals().
 * No new authentication or token framework is introduced.
 *
 * Storage
 * -------
 * Single row in abdm_master_settings inside this installation's own HMS
 * database. The table is created with IF NOT EXISTS by this handler so that an
 * installation which has not yet had the approved migration applied can still
 * save the setting. The INSERT and the audit log run in one transaction.
 */

session_start();

require $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/permissions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/abdm_helper.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_log.php';

$redirect = '/modules/abdm/settings/abdm_settings.php';

/**
 * Send the browser back to the ABDM settings page with a flash message.
 *
 * @param string $key
 * @param string $message
 * @return void
 */
function abdmGlobalSwitchRedirect($key, $message)
{
    global $redirect;

    $_SESSION[$key] = $message;

    header('Location: ' . $redirect);
    exit;
}

/* POST only. A GET must never be able to change the setting. */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    abdmGlobalSwitchRedirect('error', 'Invalid request method. The ABDM setting was not changed.');
}

/* Authenticated staff session required. */
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

/* Authorization: abdm_settings update right. */
if (!checkPermission('abdm_settings', 'update')) {
    header('Location: /access_denied.php');
    exit;
}

/* CSRF: established session token, compared in constant time. */
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if (!isset($_POST['csrf']) || !is_string($_POST['csrf']) || $_POST['csrf'] === '') {
    abdmGlobalSwitchRedirect('error', 'Security token missing. The ABDM setting was not changed.');
}

if (!hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    abdmGlobalSwitchRedirect('error', 'Invalid security token. The ABDM setting was not changed.');
}

/*
 * Strict value validation. Only the exact literals 'on' and 'off' are accepted;
 * anything else (including '1', 'true', 'ON' from an unexpected client) is
 * rejected without touching the database.
 */
$submitted = $_POST['abdm_enabled'] ?? null;

if (!is_string($submitted)) {
    abdmGlobalSwitchRedirect('error', 'Invalid ABDM setting value. The ABDM setting was not changed.');
}

$submitted = strtolower(trim($submitted));

if ($submitted === 'on') {
    $newValue = 1;
} elseif ($submitted === 'off') {
    $newValue = 0;
} else {
    abdmGlobalSwitchRedirect('error', 'Invalid ABDM setting value. The ABDM setting was not changed.');
}

/* The database connection must be usable before anything is written. */
if (!($conn instanceof mysqli)) {
    abdmGlobalSwitchRedirect('error', 'Database connection unavailable. The ABDM setting was not changed.');
}

/* Capture the previous effective value for the audit record. */
$previousValue = null;
$previousState = abdmGetGlobalSwitch($conn);
if ($previousState['available'] === true) {
    $previousValue = $previousState['enabled'] ? 'on' : 'off';
}

/*
 * Additive-only DDL, attempted ONLY when the table is genuinely absent.
 *
 * The approved migration creates this table out of band. The application
 * database account is deliberately least-privilege and has no CREATE right, so
 * a normal save must never attempt DDL. CREATE TABLE IF NOT EXISTS is additive
 * and can never alter or overwrite an existing table.
 */
if ($previousState['source'] === 'table_missing') {
    $createSql = "
        CREATE TABLE IF NOT EXISTS `abdm_master_settings` (
          `id` int(11) NOT NULL DEFAULT 1,
          `abdm_enabled` tinyint(1) NOT NULL DEFAULT 1,
          `abdm_enabled_by` int(11) DEFAULT NULL,
          `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    try {
        $created = $conn->query($createSql);
    } catch (mysqli_sql_exception $e) {
        $created = false;
        abdmLogGlobalSwitchProblem('create_table', (int)$e->getCode());
    }

    if (empty($created)) {
        abdmLogGlobalSwitchProblem('create_table', (int)$conn->errno);
        abdmGlobalSwitchRedirect(
            'error',
            'The ABDM setting table is missing and could not be created by this account. '
            . 'Apply the approved migration, then reload this page. The ABDM setting was not changed.'
        );
    }
}

$userId = (int)$_SESSION['user_id'];

$conn->begin_transaction();

try {
    /*
     * REPLACE would delete then re-insert and is unnecessary for a single
     * known primary key; an explicit upsert is used instead. The id is fixed at
     * 1 so there can never be more than one row.
     */
    $stmt = $conn->prepare("
        INSERT INTO abdm_master_settings (id, abdm_enabled, abdm_enabled_by)
        VALUES (1, ?, ?)
        ON DUPLICATE KEY UPDATE
            abdm_enabled = VALUES(abdm_enabled),
            abdm_enabled_by = VALUES(abdm_enabled_by)
    ");

    if (!$stmt) {
        throw new RuntimeException('prepare_failed');
    }

    $stmt->bind_param('ii', $newValue, $userId);

    if (!$stmt->execute()) {
        throw new RuntimeException('execute_failed');
    }

    $stmt->close();

    addAuditLog(
        'ABDM Settings',
        'UPDATE',
        'Global ABDM switch changed from ' . ($previousValue === null ? 'unset' : strtoupper($previousValue))
            . ' to ' . ($newValue === 1 ? 'ON' : 'OFF'),
        1,
        $previousValue,
        $newValue === 1 ? 'on' : 'off',
        'SUCCESS'
    );

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    abdmLogGlobalSwitchProblem('save_failed_' . $e->getMessage());

    abdmGlobalSwitchRedirect(
        'error',
        'The ABDM setting could not be saved. The ABDM setting was not changed.'
    );
}

abdmGlobalSwitchRedirect(
    'success',
    'Global ABDM is now ' . ($newValue === 1 ? 'ON' : 'OFF') . ' for this installation.'
);