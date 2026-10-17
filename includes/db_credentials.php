<?php
declare(strict_types=1);

/*
 * HMS Database Credential Resolver
 * --------------------------------
 * Single, explicit point of truth for how control-database and tenant-database
 * credentials are resolved.
 *
 * THIS FILE CONTAINS NO CREDENTIALS.
 *
 * Mode selection
 * --------------
 * Local mode  : HMS_ENV=local. Credentials are read from the external file
 *               named by HMS_LOCAL_DB_CONFIG. That file must live outside the
 *               application root and outside the web document root. If any
 *               required value is missing the request fails closed with HTTP
 *               500 BEFORE any database connection is attempted. Local mode
 *               never falls back to the production literals.
 *
 * Production  : HMS_ENV unset, or set to anything other than "local". The
 *               production literals supplied by each calling file are returned
 *               unchanged. This is the default and matches the original
 *               behaviour exactly.
 *
 * Environment marker
 * ------------------
 * $_SERVER is read first: under mod_php, Apache SetEnv is contractually merged
 * into $_SERVER. getenv() is a secondary source used for CLI. $_ENV is
 * deliberately NOT used, because XAMPP's default variables_order=GPCS omits
 * "E" and therefore leaves $_ENV empty.
 *
 * Deployability
 * -------------
 * This file is safe to deploy to production: it is inert unless HMS_ENV=local.
 */

/**
 * Read the HMS_ENV marker from the environment.
 *
 * @return string|null
 */
function hmsEnvMarker(): ?string
{
    $fromServer = $_SERVER['HMS_ENV'] ?? null;

    if (is_string($fromServer) && $fromServer !== '') {
        return $fromServer;
    }

    $fromEnv = getenv('HMS_ENV');

    if (is_string($fromEnv) && $fromEnv !== '') {
        return $fromEnv;
    }

    return null;
}

/**
 * True only when local mode has been explicitly enabled.
 */
function hmsIsLocalMode(): bool
{
    return strtolower((string) hmsEnvMarker()) === 'local';
}

/**
 * Terminate the request safely. Credential values are never written to the log
 * or to the response body.
 */
function hmsLocalFail(string $reason): void
{
    error_log('HMS local database configuration error: ' . $reason);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }

    exit(
        "Local database configuration is unavailable. The request was stopped "
        . "before any database connection was attempted.\n"
    );
}

/**
 * Normalised document root, with forward slashes and no trailing slash.
 */
function hmsDocumentRoot(): string
{
    $root = $_SERVER['DOCUMENT_ROOT'] ?? '';

    return is_string($root) ? rtrim(str_replace('\\', '/', $root), '/') : '';
}

/**
 * Load and validate the external local configuration file.
 *
 * Fails closed on every error condition. The result is cached per request.
 *
 * @return array{control: array<string,string>, tenant: array<string,string>}
 */
function hmsLocalConfig(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $configPath = $_SERVER['HMS_LOCAL_DB_CONFIG'] ?? null;

    if (!is_string($configPath) || $configPath === '') {
        $configPath = getenv('HMS_LOCAL_DB_CONFIG');
    }

    if (!is_string($configPath) || trim($configPath) === '') {
        hmsLocalFail('HMS_LOCAL_DB_CONFIG is not set.');
    }

    $configPath = trim($configPath);

    if (!is_file($configPath) || !is_readable($configPath)) {
        hmsLocalFail(
            'HMS_LOCAL_DB_CONFIG points to a file that is missing or unreadable.'
        );
    }

    $real = realpath($configPath);

    if ($real === false) {
        hmsLocalFail('HMS_LOCAL_DB_CONFIG could not be resolved.');
    }

    $realNorm = rtrim(str_replace('\\', '/', $real), '/');

    // Defence in depth: the secrets file must never sit inside the web root.
    $docRoot = hmsDocumentRoot();

    if ($docRoot !== '') {
        if ($realNorm === $docRoot || strpos($realNorm, $docRoot . '/') === 0) {
            hmsLocalFail(
                'HMS_LOCAL_DB_CONFIG resolves inside the web document root.'
            );
        }
    }

    // Defence in depth: nor inside the application root.
    $appRoot = rtrim(str_replace('\\', '/', __DIR__), '/');

    if ($realNorm === $appRoot || strpos($realNorm, $appRoot . '/') === 0) {
        hmsLocalFail(
            'HMS_LOCAL_DB_CONFIG resolves inside the application root.'
        );
    }

    $config = require $real;

    if (!is_array($config)) {
        hmsLocalFail('The local configuration file did not return an array.');
    }

    foreach (['control', 'tenant'] as $section) {
        if (!isset($config[$section]) || !is_array($config[$section])) {
            hmsLocalFail('Missing configuration section: ' . $section . '.');
        }

        foreach (['host', 'db', 'user', 'pass'] as $key) {
            $value = $config[$section][$key] ?? null;

            if (!is_string($value) || trim($value) === '') {
                hmsLocalFail(
                    'Missing or empty value for ' . $section . '.' . $key . '.'
                );
            }

            // Guard against an unreplaced scaffold placeholder being used.
            if (strpos($value, '<') === 0 && substr($value, -1) === '>') {
                hmsLocalFail(
                    'Unreplaced placeholder detected for ' . $section . '.' . $key . '.'
                );
            }
        }
    }

    $cache = $config;

    return $cache;
}

/**
 * Resolve control-database credentials.
 *
 * In production this returns the caller-supplied production values byte for
 * byte. In local mode it returns the external local values and never touches
 * the production ones.
 *
 * host/db may legitimately match between local and production, so the
 * production-reuse guard only inspects user and pass.
 *
 * @param  array{host:string,db:string,user:string,pass:string} $production
 * @return array{host:string,db:string,user:string,pass:string}
 */
function hmsControlCredentials(array $production): array
{
    if (!hmsIsLocalMode()) {
        return $production;
    }

    $local = hmsLocalConfig();

    $credentials = [
        'host' => $local['control']['host'],
        'db'   => $local['control']['db'],
        'user' => $local['control']['user'],
        'pass' => $local['control']['pass'],
    ];

    foreach (['user', 'pass'] as $key) {
        if (isset($production[$key])
            && $production[$key] !== ''
            && $credentials[$key] === $production[$key]
        ) {
            hmsLocalFail(
                'Local control.' . $key . ' matches the production value.'
            );
        }
    }

    return $credentials;
}

/**
 * Resolve tenant-database credentials from a `projects` row.
 *
 * In production the stored row values are returned exactly as before. In local
 * mode the external local values are used and the stored credentials are never
 * read.
 *
 * host/db may legitimately match between local and production, so the
 * production-reuse guard only inspects user and pass.
 *
 * @param  array<string,mixed> $tenantRow
 * @return array{host:string,db:string,user:string,pass:string}
 */
function hmsTenantCredentials(array $tenantRow): array
{
    $production = [
        'host' => !empty($tenantRow['database_host'])
            ? $tenantRow['database_host']
            : 'localhost',
        'db'   => (string) ($tenantRow['database_name'] ?? ''),
        'user' => (string) ($tenantRow['database_user'] ?? ''),
        'pass' => (string) ($tenantRow['database_password'] ?? ''),
    ];

    if (!hmsIsLocalMode()) {
        return $production;
    }

    $local = hmsLocalConfig();

    $credentials = [
        'host' => $local['tenant']['host'],
        'db'   => $local['tenant']['db'],
        'user' => $local['tenant']['user'],
        'pass' => $local['tenant']['pass'],
    ];

    foreach (['user', 'pass'] as $key) {
        if ($production[$key] !== '' && $credentials[$key] === $production[$key]) {
            hmsLocalFail(
                'Local tenant.' . $key . ' matches the stored production value.'
            );
        }
    }

    return $credentials;
}