<?php
defined('ABDM_M2_CONFIG_LOADED') || define('ABDM_M2_CONFIG_LOADED', true);
if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/abdm_helper.php';

/*
 * GLOBAL ABDM SWITCH
 *
 * Exposed as a constant so that any M2 entry point can consult it cheaply.
 * The authoritative refusal happens in the shared M2 functions
 * (abdmM2GetAccessToken, abdmM2UpdateBridgeUrl, abdmM2FindBridgeByServiceId,
 * abdmM2FindServicesByBridgeId, abdmM2LinkCareContext, abdmM2GenerateLinkToken),
 * which all fail closed before any gateway request is issued.
 *
 * The M2 URLs below are still defined when ABDM is off so that including this
 * file can never turn a disabled switch into a PHP fatal error.
 */
define('ABDM_M2_GLOBAL_ENABLED', abdmOutboundAllowed($conn) ? 1 : 0);

$m2Environment = 'Sandbox';
$m2SettingsLookupOk = false;
if (isset($conn) && $conn instanceof mysqli) {
    $stmt = $conn->prepare("
        SELECT environment
        FROM abdm_m2_settings
        WHERE status = 'Active'
        ORDER BY id DESC
        LIMIT 1
    ");

    /*
     * M2 Phase 0 / 0.6 - make configuration failures observable.
     *
     * Previously a failed connection check or a failed prepare() was silently
     * swallowed and the environment silently stayed at the 'Sandbox' default,
     * which changes BOTH ABDM_M2_BASE_URL and ABDM_M2_CM_ID. That could send
     * traffic intended for production to the sandbox (or vice versa) with no
     * trace. The default is deliberately preserved so this file can never
     * become fatal while the global switch is OFF (see the note above) - but
     * the failure is now logged so it is diagnosable.
     */
    if (!$stmt) {
        error_log(
            'ABDM_M2_CONFIG | Active M2 settings lookup could not be prepared; '
            . 'falling back to the default environment.'
        );
    } else {
        $stmt->execute();
        $result = $stmt->get_result();
        $m2Settings = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!empty($m2Settings['environment'])) {
            $m2Environment = $m2Settings['environment'];
            $m2SettingsLookupOk = true;
        } else {
            error_log(
                'ABDM_M2_CONFIG | No active M2 environment row was returned; '
                . 'falling back to the default environment.'
            );
        }
    }
} else {
    error_log(
        'ABDM_M2_CONFIG | No usable database connection for M2 settings; '
        . 'falling back to the default environment.'
    );
}
if ($m2Environment === 'Production') {
    define('ABDM_M2_BASE_URL', 'https://apis.abdm.gov.in/api');
    define('ABDM_M2_CM_ID', 'abdm');
} else {
    define('ABDM_M2_BASE_URL', 'https://dev.abdm.gov.in/api');
    define('ABDM_M2_CM_ID', 'sbx');
}
define(
    'ABDM_M2_SESSION_URL',
    ABDM_M2_BASE_URL . '/hiecm/gateway/v3/sessions'
);
define(
    'ABDM_M2_BRIDGE_URL',
    ABDM_M2_BASE_URL . '/hiecm/gateway/v3/bridge/url'
);
define('ABDM_M2_TIMEOUT', 30);

/*
 * Development-only diagnostic logging.
 *
 * When disabled (the default) abdmM2GetAccessToken() performs no file writes
 * at all, so normal token generation is completely unaffected and nothing is
 * appended to the web-served modules/abdm/m2_auth_test.log.
 *
 * The block it guards records only non-identifying diagnostics: HTTP status,
 * a YES/NO token-received flag, token type, expiry, error code, gateway
 * message and request id. It never records an access token, a client secret,
 * an OTP, patient data, or a raw gateway response body.
 *
 * Enable only on a local development box, and never in production.
 */
defined('ABDM_DIAGNOSTIC_LOG_ENABLED') || define('ABDM_DIAGNOSTIC_LOG_ENABLED', false);