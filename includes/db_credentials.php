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
 * Managed hosting (Render / Aiven)
 * -------------------------------
 * When HMS_ENV is unset (the normal production case) and DATABASE_URL is
 * present in the environment, the control-database credentials are taken from
 * that URL instead of the production literals. DATABASE_URL is supplied by
 * Render's Aiven integration and carries host, user, password, database name
 * and port. If DATABASE_URL is absent the production literals are returned
 * unchanged, so XAMPP-style local installs behave exactly as before.
 *
 * Precedence is HMS_ENV=local > DATABASE_URL > production literals.
 *
 * Why this file parses DATABASE_URL instead of each caller doing it
 * ------------------------------------------------------------------
 * Two reasons, both of which caused real production outages:
 *
 *   1. parse_url() is not safe for real database passwords. Aiven routinely
 *      generates passwords containing reserved characters. parse_url() returns
 *      false outright when the password contains "/" or "#", and it does not
 *      percent-decode, so "P%40ssw0rd" arrives verbatim as the password.
 *
 *   2. Aiven does not listen on 3306. Every call site in this application
 *      calls new mysqli($host, $user, $pass, $db) with four arguments, which
 *      silently defaults to port 3306. Overriding only host/user/pass/db
 *      therefore still fails to connect, just with a different error.
 *      hmsConnectMysqli() threads the port through.
 *
 * Deployability
 * -------------
 * This file is safe to deploy to production: it is inert unless HMS_ENV=local
 * or DATABASE_URL is set.
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
 * Terminate the request safely when DATABASE_URL is set but unusable.
 * Credential values are never written to the log or to the response body.
 */
function hmsDatabaseUrlFail(string $reason): void
{
    error_log('HMS database URL configuration error: ' . $reason);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }

    exit(
        "Database configuration is unavailable. The request was stopped before "
        . "any database connection was attempted.\n"
    );
}

/**
 * Parse a DATABASE_URL into connection parameters.
 *
 * Deliberately does not use parse_url(). See the file header for why.
 *
 * @return array{host:string,db:string,user:string,pass:string,port:int}
 */
function hmsDatabaseUrl(string $url): array
{
    $url = trim($url);

    if (preg_match('#^([a-zA-Z][a-zA-Z0-9+.\-]*)://#', $url, $schemeMatch) === 1) {
        if (!in_array(strtolower($schemeMatch[1]), ['mysql', 'mysql2', 'mysqli'], true)) {
            hmsDatabaseUrlFail('Unsupported URL scheme: ' . $schemeMatch[1] . '.');
        }

        $url = substr($url, strlen($schemeMatch[0]));
    }

    // The userinfo is delimited by the LAST '@'. Splitting on the first one
    // corrupts any password containing '@', which Aiven does generate.
    $atPosition = strrpos($url, '@');

    if ($atPosition === false) {
        hmsDatabaseUrlFail('The URL has no credentials section.');
    }

    $userInfo = substr($url, 0, $atPosition);
    $hostAndPath = substr($url, $atPosition + 1);

    // User and password are separated by the FIRST ':'; the password itself
    // may contain further colons.
    $colonPosition = strpos($userInfo, ':');

    if ($colonPosition === false) {
        $user = $userInfo;
        $pass = '';
    } else {
        $user = substr($userInfo, 0, $colonPosition);
        $pass = substr($userInfo, $colonPosition + 1);
    }

    // Percent-decoding is required. Render stores the URL verbatim, so a
    // password containing '@' or '/' arrives as '%40' or '%2F'.
    $user = rawurldecode($user);
    $pass = rawurldecode($pass);

    // Split host[:port] from the database path at the FIRST '/' following the
    // authority. Any '/' inside the password was already consumed above.
    $slashPosition = strpos($hostAndPath, '/');

    if ($slashPosition === false) {
        $hostPort = $hostAndPath;
        $database = '';
    } else {
        $hostPort = substr($hostAndPath, 0, $slashPosition);
        $database = substr($hostAndPath, $slashPosition + 1);
    }

    // Discard any query string or fragment, e.g. "?ssl-mode=REQUIRED".
    $database = preg_split('/[?#]/', $database, 2)[0];
    $database = rawurldecode(ltrim($database, '/'));

    $host = '';
    $port = null;

    if ($hostPort !== '' && $hostPort[0] === '[') {
        // Bracketed IPv6 literal, e.g. [::1]:3306
        $closePosition = strpos($hostPort, ']');

        if ($closePosition === false) {
            hmsDatabaseUrlFail('Malformed IPv6 host in the URL.');
        }

        $host = substr($hostPort, 1, $closePosition - 1);
        $remainder = substr($hostPort, $closePosition + 1);

        if ($remainder !== '' && $remainder[0] === ':') {
            $port = substr($remainder, 1);
        }
    } else {
        $lastColon = strrpos($hostPort, ':');

        if ($lastColon !== false) {
            $candidate = substr($hostPort, $lastColon + 1);

            if ($candidate !== '' && ctype_digit($candidate)) {
                $host = substr($hostPort, 0, $lastColon);
                $port = $candidate;
            } else {
                $host = $hostPort;
            }
        } else {
            $host = $hostPort;
        }
    }

    $host = rawurldecode($host);

    if ($host === '') {
        hmsDatabaseUrlFail('The URL has no host.');
    }

    if ($user === '') {
        hmsDatabaseUrlFail('The URL has no user.');
    }

    if ($database === '') {
        hmsDatabaseUrlFail('The URL has no database name.');
    }

    if ($port !== null) {
        $port = (int) $port;

        if ($port < 1 || $port > 65535) {
            hmsDatabaseUrlFail('The URL port is out of range.');
        }
    } else {
        $port = 3306;
    }

    return [
        'host' => $host,
        'db'   => $database,
        'user' => $user,
        'pass' => $pass,
        'port' => $port,
    ];
}

/**
 * Read DATABASE_URL from the environment, or null when it is not configured.
 *
 * $_SERVER is read first for the same reason hmsEnvMarker() does: under CGI and
 * FastCGI SAPIs PHP populates $_SERVER from the process environment, whereas
 * XAMPP's default variables_order omits "E" and leaves $_ENV empty. getenv() is
 * the CLI fallback.
 *
 * @return array{host:string,db:string,user:string,pass:string,port:int}|null
 */
function hmsDatabaseUrlFromEnv(): ?array
{
    $url = $_SERVER['DATABASE_URL'] ?? null;

    if (!is_string($url) || trim($url) === '') {
        $url = getenv('DATABASE_URL');
    }

    if (!is_string($url) || trim($url) === '') {
        return null;
    }

    return hmsDatabaseUrl($url);
}

/**
 * Client flags for a managed MySQL connection.
 *
 * Aiven terminates TLS and expects it by default. Verification uses the system
 * trust store, which Aiven's public certificate chain satisfies. Set HMS_DB_SSL=0
 * to disable if a provider turns out not to offer TLS.
 */
function hmsMysqliFlags(): int
{
    $disabled = $_SERVER['HMS_DB_SSL'] ?? getenv('HMS_DB_SSL');

    if (is_string($disabled) && trim($disabled) === '0') {
        return 0;
    }

    return defined('MYSQLI_CLIENT_SSL') ? MYSQLI_CLIENT_SSL : 0;
}

/**
 * Open a mysqli connection from a resolved credential array.
 *
 * Three behaviours matter here:
 *
 *   - The port comes from the credential array when present. Aiven does not
 *     listen on 3306, and the historical four-argument new mysqli() call
 *     silently forced 3306, so a correct host with a dropped port still fails.
 *
 *   - The connection is made with mysqli_init() + real_connect() rather than
 *     the constructor, because mysqli::__construct() has no $flags parameter.
 *     The TLS flags managed hosts expect can only be supplied to
 *     real_connect(), which takes them as its seventh argument.
 *
 *   - The mysqli_sql_exception is caught rather than allowed to propagate.
 *     PHP 8.1+ defaults to MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, so
 *     without this every "if ($conn->connect_error)" handler in this
 *     application is unreachable dead code and the caller receives an
 *     unhandled exception instead. index.php runs with display_errors on,
 *     which would also leak the hostname into the response. $conn keeps its
 *     connect_errno and connect_error values, so the existing error handling
 *     works exactly as written.
 *
 * @param array<string,mixed> $credentials
 */
function hmsConnectMysqli(array $credentials): mysqli
{
    $conn = mysqli_init();

    if ($conn === false) {
        throw new RuntimeException('Unable to allocate a MySQL connection.');
    }

    try {
        // The @ suppresses the diagnostic warning, which would otherwise be
        // printed verbatim wherever display_errors is on and would expose the
        // hostname. The same information is already available to the caller on
        // $conn->connect_error, and the exception is caught immediately below.
        @$conn->real_connect(
            (string) ($credentials['host'] ?? 'localhost'),
            (string) ($credentials['user'] ?? ''),
            (string) ($credentials['pass'] ?? ''),
            (string) ($credentials['db'] ?? ''),
            (int) ($credentials['port'] ?? 3306),
            null,
            hmsMysqliFlags()
        );
    } catch (mysqli_sql_exception $e) {
        // Intentionally swallowed; see the docblock. Connection details are
        // left on $conn->connect_error for the caller to handle.
    }

    return $conn;
}

/**
 * Resolve control-database credentials.
 *
 * Resolution order:
 *   1. HMS_ENV=local        -> external local configuration file.
 *   2. DATABASE_URL present -> the managed Aiven connection.
 *   3. otherwise            -> the caller-supplied production values, byte for
 *                              byte, which is the original behaviour.
 *
 * When DATABASE_URL supplies the credentials the returned array also carries a
 * 'port' key, consumed by hmsConnectMysqli().
 *
 * host/db may legitimately match between modes, so the local production-reuse
 * guard only inspects user and pass.
 *
 * @param  array{host:string,db:string,user:string,pass:string} $production
 * @return array{host:string,db:string,user:string,pass:string,port?:int}
 */
function hmsControlCredentials(array $production): array
{
    if (!hmsIsLocalMode()) {
        $fromUrl = hmsDatabaseUrlFromEnv();

        if ($fromUrl !== null) {
            return $fromUrl;
        }

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