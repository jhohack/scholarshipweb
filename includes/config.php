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

if (!function_exists('loadLocalEnvFile')) {
    function loadLocalEnvFile(string $path, bool $overrideExisting = true): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, ';') === 0) {
                continue;
            }

            if (strpos($line, 'export ') === 0) {
                $line = trim(substr($line, 7));
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0]);
            $value = trim($parts[1]);

            if ($name === '' || preg_match('/\s/', $name)) {
                continue;
            }

            if (!$overrideExisting) {
                $existingValue = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
                if ($existingValue !== false && $existingValue !== null && $existingValue !== '') {
                    continue;
                }
            }

            $valueLength = strlen($value);
            if ($valueLength >= 2) {
                $first = $value[0];
                $last = $value[$valueLength - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }
    }
}

loadLocalEnvFile(dirname(__DIR__) . '/.env', false);
loadLocalEnvFile(dirname(__DIR__) . '/.env.local');

$httpHost = $_SERVER['HTTP_HOST'] ?? '';
$isLocalHost = $httpHost === '' || strpos($httpHost, 'localhost') === 0 || strpos($httpHost, '127.0.0.1') === 0;
$requestScheme = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
) ? 'https' : 'http';
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

$supabaseProjectId = env_config('SUPABASE_PROJECT_ID', '');
$supabaseProjectUrl = $supabaseProjectId !== ''
    ? 'https://' . $supabaseProjectId . '.supabase.co'
    : env_config('SUPABASE_URL', '');
$supabaseDbHost = env_config(
    'SUPABASE_DB_HOST',
    $supabaseProjectId !== '' ? 'aws-1-ap-southeast-1.pooler.supabase.com' : ''
);
$supabaseDbName = env_config(
    'SUPABASE_DB_NAME',
    $supabaseProjectId !== '' ? 'postgres' : ''
);
$supabaseDbUser = env_config(
    'SUPABASE_DB_USER',
    $supabaseProjectId !== '' ? 'postgres.' . $supabaseProjectId : ''
);
$supabaseDbPort = (int) env_config(
    'SUPABASE_DB_PORT',
    $supabaseProjectId !== '' ? 6543 : 0
);

$supabaseDatabaseUrl = env_config(
    'SUPABASE_DB_URL',
    env_config('SUPABASE_DATABASE_URL', null)
);
$databaseUrl = env_config(
    'DB_URL',
    env_config(
        'DATABASE_URL',
        env_config(
            'POSTGRES_URL',
            $supabaseDatabaseUrl
        )
    )
);
$parsedDatabaseUrl = null;
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

$resolvedDbDriver = strtolower((string) env_config(
    'DB_DRIVER',
    $parsedDatabaseUrl['driver'] ?? (($supabaseProjectId !== '' || $supabaseDatabaseUrl !== null) ? 'pgsql' : 'mysql')
));
$resolvedDbHost = env_config(
    'DB_HOST',
    env_config(
        $resolvedDbDriver === 'pgsql' ? 'PGHOST' : 'MYSQL_HOST',
        $parsedDatabaseUrl['host'] ?? ($supabaseDbHost !== '' ? $supabaseDbHost : $legacyDbHost)
    )
);
$resolvedDbName = env_config(
    'DB_NAME',
    env_config(
        $resolvedDbDriver === 'pgsql' ? 'PGDATABASE' : 'MYSQL_DATABASE',
        $parsedDatabaseUrl['name'] ?? ($supabaseDbName !== '' ? $supabaseDbName : $legacyDbName)
    )
);
$resolvedDbUser = env_config(
    'DB_USER',
    env_config(
        $resolvedDbDriver === 'pgsql' ? 'PGUSER' : 'MYSQL_USER',
        $parsedDatabaseUrl['user'] ?? ($supabaseDbUser !== '' ? $supabaseDbUser : $legacyDbUser)
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
        $parsedDatabaseUrl['port'] ?? ($supabaseDbPort !== 0 ? $supabaseDbPort : ($resolvedDbDriver === 'pgsql' ? 5432 : 3306))
    )
);
$resolvedDbSslMode = env_config(
    'DB_SSL_MODE',
    $parsedDatabaseUrl['sslmode'] ?? (($resolvedDbDriver === 'pgsql' && (!$isLocalHost || $supabaseProjectId !== '' || $supabaseDatabaseUrl !== null)) ? 'require' : '')
);
$resolvedDbChannelBinding = env_config(
    'DB_CHANNEL_BINDING',
    $parsedDatabaseUrl['channel_binding'] ?? ''
);

$resolvedDbPgOptions = env_config(
    'DB_PG_OPTIONS',
    $parsedDatabaseUrl['options'] ?? ''
);

define('DB_DRIVER', $resolvedDbDriver);
define('DB_HOST', $resolvedDbHost);
define('DB_NAME', $resolvedDbName);
define('DB_USER', $resolvedDbUser);
define('DB_PASS', $resolvedDbPass);
define('DB_PORT', $resolvedDbPort);
define('DB_SSL_MODE', $resolvedDbSslMode);
define('DB_CHANNEL_BINDING', $resolvedDbChannelBinding);
define('DB_PG_OPTIONS', $resolvedDbPgOptions);
define('SUPABASE_PROJECT_ID', $supabaseProjectId);
define('SUPABASE_PROJECT_URL', $supabaseProjectUrl);
define('BASE_URL', rtrim(env_config('BASE_URL', $legacyBaseUrl), '/'));

define('GOOGLE_CLIENT_ID', env_config('GOOGLE_CLIENT_ID', '127649949023-se8oo6060ho0amkk852h2lk0atms23vj.apps.googleusercontent.com'));

// Enforce the shared project mailbox for every outbound email.
define('SMTP_USER', 'dvcscholarship@dvci-edu.com');
define('SMTP_HOST', env_config('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PASS', env_config('SMTP_PASS', ''));
define('SMTP_PORT', (int) env_config('SMTP_PORT', 587));
define('SMTP_SECURE', env_config('SMTP_SECURE', 'tls'));
define('SMTP_FROM_EMAIL', 'dvcscholarship@dvci-edu.com');
define('SMTP_FROM_NAME', env_config('SMTP_FROM_NAME', env_config('MAIL_FROM_NAME', 'DVC Scholarship Hub')));

define('UPLOAD_DRIVER', env_config('UPLOAD_DRIVER', IS_VERCEL ? 'database' : 'local'));
define('UPLOAD_MAX_BYTES', (int) env_config('UPLOAD_MAX_BYTES', IS_VERCEL ? 4194304 : 5242880));

$displayErrors = env_config('APP_DEBUG', $isLocalHost ? '1' : '0') === '1';
error_reporting(E_ALL);
ini_set('display_errors', $displayErrors ? '1' : '0');
