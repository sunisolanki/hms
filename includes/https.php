<?php
/**
 * Shared HTTPS / reverse-proxy normalisation.
 *
 * Render terminates TLS at its edge and forwards each request to the
 * container over plain HTTP, so the SAPI never populates $_SERVER['HTTPS'].
 * Every entry point used to work around that with:
 *
 *     if (empty($_SERVER['HTTPS'])) {
 *         header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
 *         exit;
 *     }
 *
 * That cannot terminate. Render answers the https:// URL, the SAPI still
 * reports no HTTPS, and the app redirects again - ERR_TOO_MANY_REDIRECTS.
 *
 * This file derives the real scheme from the proxy headers instead, so the
 * rest of the application (session cookie flags, TCPDF's is_secure_protocol(),
 * OAuth redirect URIs, absolute URL building) sees the connection as it
 * actually is.
 *
 * It deliberately emits NO Location header. Enforcing HTTP -> HTTPS is
 * Render's job at the edge; doing it in application code behind an SSL-
 * offloading proxy is what caused the loop in the first place.
 *
 * Include with require_once. Must run before session_set_cookie_params().
 */

// Render sends a single X-Forwarded-Proto value, but chained proxies can
// append a comma separated list. Only the left-most (client facing) hop counts.
$websplendForwardedProto = null;
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $websplendForwardedProto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
} elseif (isset($_SERVER['HTTP_X_FORWARDED_SSL'])) {
    // Legacy proxy convention: header is the literal 'on' / 'off'.
    $websplendForwardedProto = strtolower(trim($_SERVER['HTTP_X_FORWARDED_SSL'])) === 'on' ? 'https' : null;
}

$websplendIsHttps = in_array($websplendForwardedProto, ['https', 'on'], true)
    || (isset($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== ''
        && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

// Mirror Apache's semantics so downstream checks that read 'off' behave the
// same whether or not the proxy was in front of the request.
$_SERVER['HTTPS'] = $websplendIsHttps ? 'on' : 'off';

if ($websplendIsHttps) {
    $_SERVER['SERVER_PORT'] = '443';
    $_SERVER['REQUEST_SCHEME'] = 'https';
}

unset($websplendForwardedProto);

define('WEBSPLEND_HTTPS_REQUEST', $websplendIsHttps);

/**
 * Whether the request that reached PHP arrived over TLS, accounting for the
 * Render reverse proxy in front of the app.
 */
function websplend_request_is_https(): bool
{
    return WEBSPLEND_HTTPS_REQUEST;
}
