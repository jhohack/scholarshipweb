<?php
// This file requires config.php to be included first to get the DB constants.

try {
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    if (defined('DB_DRIVER') && DB_DRIVER === 'pgsql') {
        $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
        if (defined('DB_SSL_MODE') && DB_SSL_MODE !== '') {
            $dsn .= ";sslmode=" . DB_SSL_MODE;
        }
        if (defined('DB_CHANNEL_BINDING') && DB_CHANNEL_BINDING !== '') {
            $dsn .= ";channel_binding=" . DB_CHANNEL_BINDING;
        }
        if (defined('DB_PG_OPTIONS') && DB_PG_OPTIONS !== '') {
            $dsn .= ";options=" . DB_PG_OPTIONS;
        }
    } else {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    }

    // Create a new PDO instance
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    // Set Timezone to Philippines (Asia/Manila)
    date_default_timezone_set('Asia/Manila');
    if (defined('DB_DRIVER') && DB_DRIVER === 'pgsql') {
        $pdo->exec("SET TIME ZONE 'Asia/Manila'");
    } else {
        $pdo->exec("SET time_zone = '+08:00'");
    }

} catch (PDOException $e) {
    // Handle connection error
    $logMessage = "Database connection failed: " . $e->getMessage();
    error_log($logMessage);
    file_put_contents('php://stderr', $logMessage . PHP_EOL, FILE_APPEND);

    $isDebug = defined('APP_ENV') && APP_ENV === 'local';
    $message = $isDebug
        ? "Database connection failed: " . $e->getMessage()
        : "Database connection failed. Please check the deployment environment settings.";

    http_response_code(500);
    die($message);
}

require_once __DIR__ . '/db_schema.php';
?>
