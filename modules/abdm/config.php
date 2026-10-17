<?php
defined('ABDM_CONFIG_LOADED') || define('ABDM_CONFIG_LOADED', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/abdm_helper.php';

/*
 * GLOBAL ABDM SWITCH
 *
 * Exposed as a constant so any M1 entry point can consult it cheaply. The
 * authoritative refusal happens in abdmGetAccessToken() (modules/abdm/auth.php),
 * which every abha_*.php endpoint calls before any gateway request, and in each
 * endpoint itself before any input is processed.
 *
 * The M1 base URLs and the loaded credential values below are still defined when
 * ABDM is off so that including this file can never become a PHP fatal error.
 * No outbound request is possible while ABDM_M1_GLOBAL_ENABLED is 0.
 */
define('ABDM_M1_GLOBAL_ENABLED', abdmOutboundAllowed($conn) ? 1 : 0);

$m1Environment = 'Sandbox';
$abdmClientId = '';
$abdmClientSecret = '';

if (isset($conn) && $conn instanceof mysqli) {
    $stmt = $conn->prepare("
        SELECT environment, client_id, client_secret
        FROM abdm_m1_settings
        WHERE status = 'Active'
        ORDER BY id DESC
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $m1Settings = $result->fetch_assoc();
        $stmt->close();

        if (!empty($m1Settings['environment'])) {
            $m1Environment = $m1Settings['environment'];
        }

        if (!empty($m1Settings['client_id'])) {
            $abdmClientId = trim($m1Settings['client_id']);
        }

        if (!empty($m1Settings['client_secret'])) {
            $abdmClientSecret = trim($m1Settings['client_secret']);
        }
    }
}

if ($m1Environment === 'Production') {
    define('ABDM_BASE_URL', 'https://apis.abdm.gov.in');
    define('ABHA_BASE_URL', 'https://abha.abdm.gov.in/api/abha');
    define('ABHA_PHR_WEB_BASE_URL', 'https://phr.abdm.gov.in/api/phr/web/v3');
} else {
    define('ABDM_BASE_URL', 'https://dev.abdm.gov.in');
    define('ABHA_BASE_URL', 'https://abhasbx.abdm.gov.in/abha/api');
    define('ABHA_PHR_WEB_BASE_URL', 'https://abhasbx.abdm.gov.in/abha/api/v3/phr/web');
}

define('ABDM_CM_ID', $m1Environment === 'Production' ? 'abdm' : 'sbx');

define(
    'ABDM_SESSION_URL',
    ABDM_BASE_URL . '/gateway/v0.5/sessions'
);

define('ABDM_TIMEOUT', 30);

define('ABDM_CLIENT_ID', $abdmClientId);
define('ABDM_CLIENT_SECRET', $abdmClientSecret);