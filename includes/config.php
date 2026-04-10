<?php

if (!function_exists('env_config')) {
    function env_config(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if (is_string($value)) {
            $value = trim($value);

            $length = strlen($value);
            if ($length >= 2) {
                $first = $value[0];
                $last = $value[$length - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = trim(substr($value, 1, -1));
                }
            }
        }

        return ($value === false || $value === null || $value === '') ? $default : $value;
    }
}

$httpHost = $_SERVER['HTTP_HOST'] ?? '';
$isLocalHost = $httpHost === '' || strpos($httpHost, 'localhost') === 0 || strpos($httpHost, '127.0.0.1') === 0;
$requestScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$defaultBasePath = env_config('APP_BASE_PATH', $isLocalHost ? '/websitescholarship/scholarship-portal' : '');
$derivedBaseUrl = $httpHost !== '' ? $requestScheme . '://' . $httpHost . $defaultBasePath : 'http://localhost/websitescholarship/scholarship-portal';

define('APP_ENV', env_config('APP_ENV', env_config('VERCEL_ENV', $isLocalHost ? 'local' : 'production')));
define('IS_VERCEL', env_config('VERCEL', '0') === '1' || env_config('VERCEL_ENV') !== null);

if ($httpHost === 'dvc.infinityfree.me' && env_config('DB_HOST') === null) {
    $legacyDbHost = 'sql312.infinityfree.com';
    $legacyDbName = 'if0_39584519_scholarship_db';
    $legacyDbUser = 'if0_39584519';
    $legacyDbPass = 'firmeza04';
    $legacyBaseUrl = 'https://dvc.infinityfree.me/websitescholarship/scholarship-portal';
} else {
    $legacyDbHost = 'localhost';
    $legacyDbName = 'scholarship_db';
    $legacyDbUser = 'root';
    $legacyDbPass = '';
    $legacyBaseUrl = $derivedBaseUrl;
}

$parsedDatabaseUrl = null;
$databaseUrl = env_config('DB_URL', env_config('DATABASE_URL', env_config('POSTGRES_URL', null)));
if ($databaseUrl) {
    $parsedUrl = @parse_url($databaseUrl);
    if (is_array($parsedUrl) && !empty($parsedUrl['host'])) {
        $queryParams = [];
        if (!empty($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);
        }

        $scheme = strtolower((string) ($parsedUrl['scheme'] ?? ''));
        $parsedDatabaseUrl = [
            'driver' => in_array($scheme, ['postgres', 'postgresql', 'pgsql'], true) ? 'pgsql' : 'mysql',
            'host' => $parsedUrl['host'] ?? null,
            'port' => $parsedUrl['port'] ?? null,
            'name' => isset($parsedUrl['path']) ? ltrim($parsedUrl['path'], '/') : null,
            'user' => isset($parsedUrl['user']) ? rawurldecode($parsedUrl['user']) : null,
            'pass' => isset($parsedUrl['pass']) ? rawurldecode($parsedUrl['pass']) : null,
            'sslmode' => $queryParams['sslmode'] ?? null,
            'options' => $queryParams['options'] ?? null,
            'channel_binding' => $queryParams['channel_binding'] ?? null,
        ];
    }
}

$resolvedDbDriver = strtolower((string) env_config('DB_DRIVER', $parsedDatabaseUrl['driver'] ?? 'mysql'));
$resolvedDbHost = env_config(
    'DB_HOST',
    env_config(
        $resolvedDbDriver === 'pgsql' ? 'PGHOST' : 'MYSQL_HOST',
        $parsedDatabaseUrl['host'] ?? $legacyDbHost
    )
);
$resolvedDbName = env_config(
    'DB_NAME',
    env_config(
        $resolvedDbDriver === 'pgsql' ? 'PGDATABASE' : 'MYSQL_DATABASE',
        $parsedDatabaseUrl['name'] ?? $legacyDbName
    )
);
$resolvedDbUser = env_config(
    'DB_USER',
    env_config(
        $resolvedDbDriver === 'pgsql' ? 'PGUSER' : 'MYSQL_USER',
        $parsedDatabaseUrl['user'] ?? $legacyDbUser
    )
);
$resolvedDbPass = env_config(
    'DB_PASS',
    env_config(
        $resolvedDbDriver === 'pgsql' ? 'PGPASSWORD' : 'MYSQL_PASSWORD',
        $parsedDatabaseUrl['pass'] ?? $legacyDbPass
    )
);
$resolvedDbPort = (int) env_config(
    'DB_PORT',
    env_config(
        $resolvedDbDriver === 'pgsql' ? 'PGPORT' : 'MYSQL_PORT',
        $parsedDatabaseUrl['port'] ?? ($resolvedDbDriver === 'pgsql' ? 5432 : 3306)
    )
);
$resolvedDbSslMode = env_config(
    'DB_SSL_MODE',
    $parsedDatabaseUrl['sslmode'] ?? ($resolvedDbDriver === 'pgsql' && !$isLocalHost ? 'require' : '')
);
$resolvedDbChannelBinding = env_config(
    'DB_CHANNEL_BINDING',
    ''
);
$resolvedDbNeonEndpoint = env_config('DB_NEON_ENDPOINT', '');
if ($resolvedDbNeonEndpoint === '' && $resolvedDbDriver === 'pgsql' && is_string($resolvedDbHost)) {
    if (preg_match('/^([^.]+)\..*\\.neon\\.tech$/i', $resolvedDbHost, $matches)) {
        $resolvedDbNeonEndpoint = preg_replace('/-pooler$/i', '', $matches[1]);
    }
}

$resolvedDbPgOptions = env_config(
    'DB_PG_OPTIONS',
    $parsedDatabaseUrl['options'] ?? ''
);
if ($resolvedDbPgOptions === '' && $resolvedDbNeonEndpoint !== '') {
    $resolvedDbPgOptions = 'endpoint=' . $resolvedDbNeonEndpoint;
}

define('DB_DRIVER', $resolvedDbDriver);
define('DB_HOST', $resolvedDbHost);
define('DB_NAME', $resolvedDbName);
define('DB_USER', $resolvedDbUser);
define('DB_PASS', $resolvedDbPass);
define('DB_PORT', $resolvedDbPort);
define('DB_SSL_MODE', $resolvedDbSslMode);
define('DB_CHANNEL_BINDING', $resolvedDbChannelBinding);
define('DB_NEON_ENDPOINT', $resolvedDbNeonEndpoint);
define('DB_PG_OPTIONS', $resolvedDbPgOptions);
define('BASE_URL', rtrim(env_config('BASE_URL', $legacyBaseUrl), '/'));

define('GOOGLE_CLIENT_ID', env_config('GOOGLE_CLIENT_ID', '127649949023-se8oo6060ho0amkk852h2lk0atms23vj.apps.googleusercontent.com'));

define('SMTP_HOST', env_config('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_USER', env_config('SMTP_USER', ''));
define('SMTP_PASS', env_config('SMTP_PASS', ''));
define('SMTP_PORT', (int) env_config('SMTP_PORT', 587));
define('SMTP_SECURE', env_config('SMTP_SECURE', 'tls'));
define('SMTP_FROM_EMAIL', env_config('SMTP_FROM_EMAIL', env_config('MAIL_FROM_ADDRESS', SMTP_USER)));
define('SMTP_FROM_NAME', env_config('SMTP_FROM_NAME', env_config('MAIL_FROM_NAME', 'DVC Scholarship Hub')));

define('UPLOAD_DRIVER', env_config('UPLOAD_DRIVER', IS_VERCEL ? 'database' : 'local'));
define('UPLOAD_MAX_BYTES', (int) env_config('UPLOAD_MAX_BYTES', IS_VERCEL ? 4194304 : 5242880));

$displayErrors = env_config('APP_DEBUG', $isLocalHost ? '1' : '0') === '1';
error_reporting(E_ALL);
ini_set('display_errors', $displayErrors ? '1' : '0');
