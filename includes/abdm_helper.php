<?php
/**
 * Global ABDM ON/OFF switch - central reader.
 *
 * Purpose
 * -------
 * WebSplend HMS is installed at multiple client sites and not every site uses
 * ABDM. This file provides the single place that answers "is ABDM enabled for
 * this installation?" so that both the UI and every server-side ABDM call site
 * share one decision.
 *
 * Storage and tenancy
 * -------------------
 * The setting lives in `abdm_master_settings` (single row, id = 1) inside the
 * installation's OWN HMS database. Tenancy in WebSplend HMS is one database per
 * client, resolved per request through `projects` in the control database
 * (see includes/db.php). A row in that database is therefore already isolated
 * per client: one installation changing the setting cannot affect another
 * installation. No tenant discriminator column is required or added, and this
 * file does not touch tenant resolution.
 *
 * States (do not collapse these)
 * ------------------------------
 *   state = 'ok'      the row was read. `enabled` is the stored value.
 *   state = 'missing' the table or the row does not exist yet. This is an
 *                     installation that predates this feature, so ABDM is
 *                     treated as ENABLED for backward compatibility. Ordinary
 *                     registration, patient editing and prescription saving
 *                     must keep working on such an installation.
 *   state = 'error'   the setting could not be evaluated: no database
 *                     connection, permission failure, connection failure, or
 *                     malformed stored value. ABDM actions FAIL CLOSED. This
 *                     is deliberately not treated as ENABLED, because silently
 *                     enabling outbound ABDM traffic on a read error is exactly
 *                     what the switch exists to prevent.
 *
 * A read error never blocks ordinary HMS work: callers only use this file to
 * decide whether to render ABDM controls or to make an outbound ABDM call.
 *
 * Compatibility
 * -------------
 * Returns the same array shape as the codebase convention used by the ABDM
 * library (auth.php / m2_auth.php): ['success' => bool, 'message' => string].
 * No SQL text, credential, token, ABHA number or patient identifier is ever
 * included in a return value or written to the log.
 *
 * Architecture constraint: procedural PHP, mysqli only, no framework, no ORM.
 * Tenant resolution is untouched.
 */

defined('ABDM_MASTER_HELPER_LOADED') || define('ABDM_MASTER_HELPER_LOADED', true);

/**
 * MySQL/MariaDB error numbers this reader treats as "the feature is not
 * installed yet" rather than "the setting could not be read".
 *
 * 1146 = ER_NO_SUCH_TABLE
 * 1142 = ER_TABLEACCESS_DENIED_ERROR
 * 1044 = ER_DBACCESS_DENIED_ERROR
 * 1045 = ER_ACCESS_DENIED_ERROR
 * 2002 = CR_CONNECTION_ERROR
 * 2003 = CR_CONN_HOST_ERROR
 * 2006 = CR_SERVER_GONE_ERROR
 * 2013 = CR_SERVER_LOST
 */
if (!defined('ABDM_MASTER_ERRNOS')) {
    define('ABDM_MASTER_ERRNOS', [
        'missing_table' => [1146],
        'access'        => [1044, 1045, 1142],
        'connection'    => [2002, 2003, 2006, 2013],
    ]);
}

/**
 * Read the single setting row, converting every driver failure into a
 * classified result instead of an uncaught exception.
 *
 * PHP 8.1 and later default to MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, so a
 * failing prepare()/execute() THROWS mysqli_sql_exception. The @ operator does
 * not suppress exceptions. Without this wrapper a missing table would fatal the
 * entire registration page instead of degrading to the backward-compatible
 * default, so the exception must be caught and mapped to a driver error number.
 *
 * On PHP 7.x there is no exception and prepare() simply returns false; both
 * paths are handled.
 *
 * @param mysqli $conn
 * @return array{status:string, errno:int, row:array|null}
 *   status: 'row' | 'no_row' | 'errno'
 */
function abdmReadMasterRow(mysqli $conn)
{
    $sql = 'SELECT abdm_enabled FROM abdm_master_settings WHERE id = 1 LIMIT 1';

    try {
        $stmt = $conn->prepare($sql);
    } catch (mysqli_sql_exception $e) {
        return ['status' => 'errno', 'errno' => abdmErrnoFromException($e, $conn), 'row' => null];
    }

    if ($stmt === false) {
        return ['status' => 'errno', 'errno' => (int)$conn->errno, 'row' => null];
    }

    try {
        $executed = $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        $errno = abdmErrnoFromException($e, $conn);
        @$stmt->close();
        return ['status' => 'errno', 'errno' => $errno, 'row' => null];
    }

    if ($executed === false) {
        $errno = (int)$stmt->errno;
        @$stmt->close();
        return ['status' => 'errno', 'errno' => $errno, 'row' => null];
    }

    try {
        $result = $stmt->get_result();
    } catch (mysqli_sql_exception $e) {
        $errno = abdmErrnoFromException($e, $conn);
        @$stmt->close();
        return ['status' => 'errno', 'errno' => $errno, 'row' => null];
    }

    if (!($result instanceof mysqli_result)) {
        $errno = (int)$conn->errno;
        @$stmt->close();
        return ['status' => 'errno', 'errno' => $errno, 'row' => null];
    }

    $row = $result->fetch_assoc();
    @$stmt->close();

    if (!is_array($row) || !array_key_exists('abdm_enabled', $row)) {
        return ['status' => 'no_row', 'errno' => 0, 'row' => null];
    }

    return ['status' => 'row', 'errno' => 0, 'row' => $row];
}

/**
 * Extract the driver error number from a caught mysqli_sql_exception.
 *
 * @param mysqli_sql_exception $e
 * @param mysqli               $conn
 * @return int
 */
function abdmErrnoFromException($e, $conn)
{
    $code = (int)$e->getCode();

    if ($code !== 0) {
        return $code;
    }

    return (int)$conn->errno;
}

/**
 * Read the raw global ABDM switch for this request.
 *
 * @param mysqli|null $conn Optional. Defaults to the global $conn.
 * @return array{
 *     state:     string, // 'ok' | 'missing' | 'error'
 *     available: bool,   // true only when the stored value was actually read
 *     enabled:   bool,   // effective decision; true only when ON or missing
 *     source:    string, // 'row' | 'table_missing' | 'row_missing' | 'query_error' | 'no_connection' | 'malformed'
 *     message:   string  // sanitized, safe to display and to log
 * }
 */
function abdmGetGlobalSwitch($conn = null)
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    if ($conn === null) {
        global $conn;
    }

    if (!($conn instanceof mysqli)) {
        $cache = [
            'state'     => 'error',
            'available' => false,
            'enabled'   => false,
            'source'    => 'no_connection',
            'message'   => 'Database connection unavailable; ABDM is treated as disabled for this request.',
        ];

        return $cache;
    }

    $read = abdmReadMasterRow($conn);

    if ($read['status'] === 'errno') {
        if (in_array($read['errno'], ABDM_MASTER_ERRNOS['missing_table'], true)) {
            // The feature is not installed on this database yet. Treat as ON so
            // an existing installation keeps working exactly as it did before.
            $cache = [
                'state'     => 'missing',
                'available' => false,
                'enabled'   => true,
                'source'    => 'table_missing',
                'message'   => 'Global ABDM setting table is not present; ABDM is treated as ON (default).',
            ];

            return $cache;
        }

        $cache = abdmErrorState('query_error', $read['errno']);

        return $cache;
    }

    if ($read['status'] === 'no_row') {
        // Table exists but the single row has not been written yet.
        $cache = [
            'state'     => 'missing',
            'available' => false,
            'enabled'   => true,
            'source'    => 'row_missing',
            'message'   => 'Global ABDM setting row is not present; ABDM is treated as ON (default).',
        ];

        return $cache;
    }

    $raw = $read['row']['abdm_enabled'];

    // Strict validation: only the integer 0 or 1 (or its string form) is valid.
    // Anything else - including NULL - is malformed data and fails closed
    // rather than being guessed at.
    if ($raw === 0 || $raw === '0') {
        $enabled = false;
    } elseif ($raw === 1 || $raw === '1') {
        $enabled = true;
    } else {
        $cache = [
            'state'     => 'error',
            'available' => false,
            'enabled'   => false,
            'source'    => 'malformed',
            'message'   => 'Global ABDM setting holds an invalid value; ABDM is treated as disabled for this request.',
        ];
        abdmLogGlobalSwitchProblem('malformed_value');

        return $cache;
    }

    $cache = [
        'state'     => 'ok',
        'available' => true,
        'enabled'   => $enabled,
        'source'    => 'row',
        'message'   => $enabled
            ? 'Global ABDM is ON for this installation.'
            : 'Global ABDM is OFF for this installation.',
    ];

    return $cache;
}

/**
 * Build an error state and write a sanitized diagnostic.
 *
 * Only the state label and the driver error number are logged. No SQL text,
 * credential, token, ABHA number or patient identifier is ever written.
 *
 * @param string $source
 * @param int    $errno
 * @return array
 */
function abdmErrorState($source, $errno)
{
    $class = 'query_error';

    if (in_array((int)$errno, ABDM_MASTER_ERRNOS['access'], true)) {
        $class = 'access_denied';
    } elseif (in_array((int)$errno, ABDM_MASTER_ERRNOS['connection'], true)) {
        $class = 'connection_failed';
    }

    abdmLogGlobalSwitchProblem($class . '/' . $source, (int)$errno);

    return [
        'state'     => 'error',
        'available' => false,
        'enabled'   => false,
        'source'    => $source,
        'message'   => 'The Global ABDM setting could not be read; ABDM is treated as disabled for this request.',
    ];
}

/**
 * Write a sanitized diagnostic to the PHP error log.
 *
 * @param string $label
 * @param int    $errno
 * @return void
 */
function abdmLogGlobalSwitchProblem($label, $errno = 0)
{
    $line = '[ABDM-GLOBAL-SWITCH] state=' . $label;
    if ($errno !== 0) {
        $line .= ' errno=' . $errno;
    }
    error_log($line);
}

/**
 * Is ABDM enabled for this installation?
 *
 * TRUE  - the stored setting is ON, or the setting is absent on a
 *         installation that predates the feature (backward compatible default).
 * FALSE - the setting is OFF, or it could not be read (fail closed).
 *
 * Callers that must distinguish OFF from UNREADABLE should use
 * abdmGetGlobalSwitch() and inspect 'state'.
 *
 * @param mysqli|null $conn
 * @return bool
 */
function abdmIsAbdmGloballyEnabled($conn = null)
{
    $state = abdmGetGlobalSwitch($conn);

    return (bool)$state['enabled'];
}

/**
 * May this request perform an outbound ABDM operation?
 *
 * Equivalent to abdmIsAbdmGloballyEnabled() today, but named separately so that
 * the call site reads as an enforcement decision rather than a UI question.
 *
 * @param mysqli|null $conn
 * @return bool
 */
function abdmOutboundAllowed($conn = null)
{
    return abdmIsAbdmGloballyEnabled($conn);
}

/**
 * Standard refusal payload in the shape used by the ABDM library.
 *
 * @param string|null $reason Optional override message.
 * @return array
 */
function abdmDisabledResponse($reason = null)
{
    $state = abdmGetGlobalSwitch();

    return [
        'success' => false,
        'message' => $reason !== null && trim($reason) !== ''
            ? $reason
            : 'ABDM is disabled for this installation.',
        'abdm_enabled' => false,
        'abdm_state' => $state['state'],
    ];
}

/**
 * True when the switch is readable and explicitly OFF.
 *
 * @param mysqli|null $conn
 * @return bool
 */
function abdmIsExplicitlyOff($conn = null)
{
    $state = abdmGetGlobalSwitch($conn);

    return $state['state'] === 'ok' && $state['enabled'] === false;
}

/**
 * True when the setting could not be evaluated at all.
 *
 * @param mysqli|null $conn
 * @return bool
 */
function abdmSwitchUnavailable($conn = null)
{
    $state = abdmGetGlobalSwitch($conn);

    return $state['state'] === 'error';
}

/**
 * Reset the request-scoped cache. Intended for tests only.
 *
 * @return void
 */
function abdmResetGlobalSwitchCache()
{
    // Static caches cannot be reset from outside a function, so this exists as
    // an explicit marker for the test harness rather than a real reset. Tests
    // run each state in a separate PHP process.
}