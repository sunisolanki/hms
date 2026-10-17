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
 * Discrete variables
 * ------------------
 * Where control and tenant schemas sit on ONE MySQL service - the common Aiven
 * single-service layout - a single DATABASE_URL describes the control schema
 * only, and there is no equivalent URL for the second schema. hmsDatabaseEnv()
 * therefore also accepts discrete variables, in two families:
 *
 *   CONTROL_DB_HOST  CONTROL_DB_PORT  CONTROL_DB_NAME
 *   CONTROL_DB_USER  CONTROL_DB_PASSWORD
 *   TENANT_DB_HOST   TENANT_DB_PORT   TENANT_DB_NAME
 *   TENANT_DB_USER   TENANT_DB_PASSWORD
 *
 * The unprefixed DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASSWORD act as a
 * shared fallback for both families, so the single-service case needs only five
 * variables - plus CONTROL_DB_NAME, because DB_NAME names the TENANT schema and
 * must never be allowed to name the control schema too.
 *
 * hmsDatabaseEnv() resolves ONE prefix at a time and requires that prefix to be
 * complete, so an incomplete family is skipped whole rather than completed from
 * the next one. It returns null unless host, user and name are all present. A
 * missing password is legitimate and resolves to an empty string.
 *
 * HMS_TENANT_DB_ENV=0 forces hmsTenantCredentials() to ignore the tenant
 * variables and use the per-tenant `projects` row instead. A multi-tenant
 * deployment should set it: without it, a single set of DB_* variables would
 * collapse every tenant onto one database.
 *
 * Precedence
 *   control : HMS_ENV=local > DATABASE_URL > CONTROL_DB_* > (CONTROL_DB_NAME
 *             + DB_* connection parameters) > literals
 *   tenant  : HMS_ENV=local > TENANT_DB_* > DB_* > `projects` row
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
        // Managed providers expect TLS; see hmsMysqliFlags().
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
 * Read a single environment variable.
 *
 * $_SERVER is read first for the same reason hmsEnvMarker() does: under CGI and
 * FastCGI SAPIs PHP populates $_SERVER from the process environment, whereas
 * XAMPP's default variables_order omits "E" and leaves $_ENV empty. getenv() is
 * the CLI fallback.
 *
 * A value that is present but empty or whitespace-only is treated as ABSENT, not
 * as an empty string. That matters for credentials: an empty DB_PASSWORD is a
 * legitimate value, but an empty DB_HOST or DB_NAME always means "not
 * configured", and hmsDatabaseEnv() relies on null to make that distinction.
 *
 * @return string|null The trimmed value, or null when unset/blank.
 */
function hmsEnv(string $key): ?string
{
    $fromServer = $_SERVER[$key] ?? null;

    if (is_string($fromServer) && trim($fromServer) !== '') {
        return trim($fromServer);
    }

    $fromEnv = getenv($key);

    if (is_string($fromEnv) && trim($fromEnv) !== '') {
        return trim($fromEnv);
    }

    return null;
}

/**
 * Resolve ONE family of discrete DB_* variables.
 *
 * A family is identified by its prefix, e.g. 'DB' for DB_HOST, DB_PORT, DB_NAME,
 * DB_USER and DB_PASSWORD.
 *
 * THE FAMILY MUST BE COMPLETE. Host, user and name must all be present, or the
 * whole family is reported as absent. A missing or blank password is legitimate
 * and resolves to an empty string. A blank, non-numeric or out-of-range port
 * falls back to 3306 rather than failing later with a confusing error.
 *
 * WHY COMPLETENESS IS ENFORCED PER FAMILY AND NEVER MERGED
 * -------------------------------------------------------
 * Merging across families would let TENANT_DB_HOST combine with DB_NAME and
 * produce a credential array describing a database that exists in no
 * configuration anybody wrote - a connection to the right server and the right
 * user, aimed at a schema chosen by accident. Callers that want a fallback chain
 * ask for each family separately and use `??`, so an incomplete family is
 * skipped whole.
 *
 * Returning null is also what keeps this inert on XAMPP: an install that sets
 * none of these variables resolves exactly as it did before.
 *
 * @return array{host:string,db:string,user:string,pass:string,port:int}|null
 */
function hmsDatabaseEnv(string $prefix): ?array
{
    $host = hmsEnv($prefix . '_HOST');
    $user = hmsEnv($prefix . '_USER');
    $db   = hmsEnv($prefix . '_NAME');

    // Name is the one value that identifies WHICH schema this is. Without it
    // the connection would silently land on the control database, so an
    // incomplete set is discarded rather than guessed at.
    if ($host === null || $user === null || $db === null) {
        return null;
    }

    $port = hmsEnv($prefix . '_PORT');

    if ($port === null || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        $port = 3306;
    }

    return [
        'host' => $host,
        'db'   => $db,
        'user' => $user,
        'pass' => hmsEnv($prefix . '_PASSWORD') ?? '',
        'port' => (int) $port,
    ];
}

/**
 * Client flags for a MySQL connection.
 *
 * TLS is requested only for managed connections. Aiven terminates TLS and
 * expects it by default, and verification uses the system trust store, which
 * Aiven's public certificate chain satisfies.
 *
 * It must NOT be requested for the local XAMPP path. XAMPP's MySQL has no TLS,
 * and sending MYSQLI_CLIENT_SSL there aborts the handshake with errno 2006
 * "MySQL server has gone away", which hides the real failure (a 1045 access
 * error) and makes a perfectly good local install look broken.
 *
 * The default discriminator is the port. Managed providers essentially never
 * serve on 3306, while local installs always do, so a non-default port selects
 * TLS. After the tenant migration this is what makes the stored row use TLS,
 * without needing an extra schema column.
 *
 * HMS_DB_SSL overrides the default in both directions:
 *   1  force TLS on, for a provider on 3306 that does offer it
 *   0  force TLS off, for a managed host that turns out not to offer it
 *
 * @param array<string,mixed> $credentials
 */
function hmsMysqliFlags(array $credentials): int
{
    $override = $_SERVER['HMS_DB_SSL'] ?? getenv('HMS_DB_SSL');
    $override = is_string($override) ? strtolower(trim($override)) : '';

    $ssl = defined('MYSQLI_CLIENT_SSL') ? MYSQLI_CLIENT_SSL : 0;

    if ($override === '0') {
        return 0;
    }

    if ($override === '1') {
        return $ssl;
    }

    return (int) ($credentials['port'] ?? 3306) !== 3306 ? $ssl : 0;
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
 *     unhandled exception instead. That exception's message carries the
 *     hostname, so it must not reach the response - index.php and
 *     patient/login_process.php log it and display a generic message, and both
 *     now leave display_errors off unless HMS_DEBUG=1. $conn keeps its
 *     connect_errno and connect_error values, so the existing error handling
 *     works exactly as written.
 *
 *     Callers that want the errno should read $conn->connect_errno directly or
 *     let the RuntimeException carry it; see includes/tenant_db.php.
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
            hmsMysqliFlags($credentials)
        );
    } catch (mysqli_sql_exception $e) {
        // Intentionally swallowed; see the docblock. Connection details are
        // left on $conn->connect_error for the caller to handle.
    }

    return $conn;
}

/**
 * Does the connected control database's `projects` table carry `database_port`?
 *
 * WHY THIS PROBE IS NECESSARY
 * ---------------------------
 * Twelve files read `projects.database_port`. The column supports managed
 * tenancy, where the tenant database does not listen on 3306, but it was added
 * without a migration file. A control database restored from the pre-port dump
 * - which is what database_work/u336643111_control.sql is - does not have it.
 *
 * hmsTenantCredentials() reads the value defensively
 * (`$tenantRow['database_port'] ?? null`, defaulting to 3306), so the resolver
 * itself is already correct on both schema variants. The problem is upstream:
 * a SELECT that NAMES the column is rejected outright by MySQL before the row
 * is ever fetched.
 *
 * WHY THIS IS FATAL AND NOT COSMETIC
 * ----------------------------------
 * PHP 8.1+ defaults mysqli to MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, so
 * mysqli::prepare() THROWS mysqli_sql_exception ("Unknown column
 * 'database_port' in 'field list'") rather than returning false. Two
 * consequences:
 *
 *   1. The `if (!$stmt) return null;` guards that follow these SELECTs are
 *      unreachable dead code on every call path.
 *   2. On the staff login the throw surfaces at index.php as "Unable to connect
 *      to the selected Tenant database." even though the tenant database was
 *      never attempted - a control-database problem wearing a tenant-database
 *      label. hmsTenantColumnList() removes that ambiguity at the source.
 *
 * This mirrors the probe that api/v0.5/users/auth/on-confirm/index.php already
 * runs inline; it was generalised here so all twelve call sites share it.
 *
 * The column name is a literal in this file, never request input, so
 * interpolating it into the SHOW statement is safe.
 */
function hmsProjectsHasDatabasePort(mysqli $controlConn): bool
{
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    try {
        $probe = $controlConn->query(
            "SHOW COLUMNS FROM `projects` LIKE 'database_port'"
        );

        $cached = ($probe instanceof mysqli_result) && $probe->num_rows > 0;
    } catch (Throwable $e) {
        // A probe failure means "cannot rely on the column", which is the safe
        // answer: the SELECT then degrades to the pre-port behaviour.
        $cached = false;
    }

    return $cached;
}

/**
 * Build the `projects` column list for a tenant SELECT.
 *
 * Drops `database_port` when the connected control database has no such column
 * and the caller asked for it. Returns a comma-separated fragment ready to
 * splice into a SELECT, WITHOUT backticks, because several call sites pass
 * aliased expressions such as "client_name AS hospital_name" that cannot be
 * quoted as an identifier. Every entry is a literal in the calling source
 * file, never request input, so there is nothing to escape.
 *
 * @param  string[] $columns
 * @return string   e.g. "id, tenant_id, database_host, database_name"
 */
function hmsTenantColumnList(array $columns, mysqli $controlConn): string
{
    if (in_array('database_port', $columns, true)
        && !hmsProjectsHasDatabasePort($controlConn)
    ) {
        $columns = array_values(array_filter(
            $columns,
            static function (string $column): bool {
                return $column !== 'database_port';
            }
        ));
    }

    return implode(', ', $columns);
}

/**
 * Resolve control-database credentials.
 *
 * Resolution order:
 *   1. HMS_ENV=local        -> external local configuration file.
 *   2. DATABASE_URL present -> the managed Aiven connection.
 *   3. CONTROL_DB_*        -> discrete environment variables for the control
 *                             database.
 *   4. CONTROL_DB_NAME plus the shared DB_* connection parameters.
 *   5. otherwise            -> the caller-supplied production values, byte for
 *                              byte, which is the original behaviour.
 *
 * Steps 2 to 4 also add a 'port' key, consumed by hmsConnectMysqli(). Without
 * it every connection silently falls back to 3306, which managed hosts such as
 * Aiven do not serve.
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

        // No DATABASE_URL. Fall back to discrete variables before the literals,
        // which is what lets a Render service that was never wired to a
        // DATABASE_URL integration still reach a managed control database.
        $fromEnv = hmsDatabaseEnv('CONTROL_DB');

        if ($fromEnv === null) {
            /*
             * The shared DB_* family may supply the CONNECTION parameters, but
             * it must never name the control schema: DB_NAME is the TENANT
             * schema. Allowing it through would aim the control connection at
             * u336643111_hms, which would connect cleanly at the TCP and TLS
             * level and then run `SELECT ... FROM projects` against the wrong
             * database - a silent failure, because the connection itself looks
             * healthy. CONTROL_DB_NAME is therefore mandatory in this shape.
             */
            $shared      = hmsDatabaseEnv('DB');
            $controlDb   = hmsEnv('CONTROL_DB_NAME');
            $controlHost = hmsEnv('CONTROL_DB_HOST');
            $controlPort = hmsEnv('CONTROL_DB_PORT');

            if ($shared !== null && $controlDb !== null) {
                $portValid = $controlPort !== null
                    && ctype_digit($controlPort)
                    && (int) $controlPort >= 1
                    && (int) $controlPort <= 65535;

                $fromEnv = [
                    'host' => $controlHost ?? $shared['host'],
                    'user' => hmsEnv('CONTROL_DB_USER') ?? $shared['user'],
                    'pass' => hmsEnv('CONTROL_DB_PASSWORD') ?? $shared['pass'],
                    'port' => $portValid ? (int) $controlPort : $shared['port'],
                    'db'   => $controlDb,
                ];
            }
        }

        if ($fromEnv !== null) {
            return $fromEnv;
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
 * Resolution order:
 *   1. HMS_ENV=local              -> external local configuration file.
 *   2. TENANT_DB_* / DB_*         -> the environment, unless HMS_TENANT_DB_ENV=0.
 *   3. otherwise                  -> the `projects` row, exactly as before.
 *
 * 'port' comes from the optional `database_port` column, defaulting to 3306.
 * That column is added by devtools/migrate_tenant_db_to_managed.php and is
 * nullable, so rows that predate it - and any SELECT that does not yet name it -
 * keep resolving to 3306 and behave exactly as before. Managed hosts such as
 * Aiven do not listen on 3306, which is why the value has to reach mysqli() at
 * all: a four-argument new mysqli() silently forces 3306. Callers SELECT it
 * through hmsTenantColumnList() so a control database without the column still
 * works; see hmsProjectsHasDatabasePort() for why that is not optional.
 *
 * WHY THE ENVIRONMENT OUTRANKS THE ROW
 * ------------------------------------
 * A Render deployment carries one set of process-wide variables but hosts many
 * tenant rows. For a single-tenant service the variables are the only place the
 * Aiven host, port and credentials exist, because the row was written when the
 * database lived on XAMPP. Honouring them is what makes the deployment
 * connectable at all.
 *
 * That is also the risk, which is why HMS_TENANT_DB_ENV=0 exists: set it on a
 * genuinely multi-tenant production install and the variables are ignored, so
 * one stray DB_HOST cannot collapse every hospital onto a single schema.
 *
 * host/db may legitimately match between local and production, so the
 * production-reuse guard only inspects user and pass.
 *
 * @param  array<string,mixed> $tenantRow
 * @return array{host:string,db:string,user:string,pass:string,port:int}
 */
function hmsTenantCredentials(array $tenantRow): array
{
    $storedPort = $tenantRow['database_port'] ?? null;

    $production = [
        'host' => !empty($tenantRow['database_host'])
            ? $tenantRow['database_host']
            : 'localhost',
        'db'   => (string) ($tenantRow['database_name'] ?? ''),
        'user' => (string) ($tenantRow['database_user'] ?? ''),
        'pass' => (string) ($tenantRow['database_password'] ?? ''),
        'port' => ($storedPort === null || $storedPort === '' || (int) $storedPort < 1)
            ? 3306
            : (int) $storedPort,
    ];

    if (!hmsIsLocalMode()) {
        if (hmsEnv('HMS_TENANT_DB_ENV') !== '0') {
            // Each family is evaluated whole: an incomplete TENANT_DB_* set is
            // skipped rather than completed from DB_*.
            $fromEnv = hmsDatabaseEnv('TENANT_DB') ?? hmsDatabaseEnv('DB');

            if ($fromEnv !== null) {
                return $fromEnv;
            }
        }

        return $production;
    }

    $local = hmsLocalConfig();

    $credentials = [
        'host' => $local['tenant']['host'],
        'db'   => $local['tenant']['db'],
        'user' => $local['tenant']['user'],
        'pass' => $local['tenant']['pass'],
        // Local installs are XAMPP; the local config file carries no port.
        'port' => 3306,
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