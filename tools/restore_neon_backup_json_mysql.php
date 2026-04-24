<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

set_time_limit(0);
ini_set('memory_limit', '-1');

$basePath = dirname(__DIR__);
$defaultBackupPath = $basePath . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'neon-before-replace-20260411-003956.json';

$options = parseCliOptions(array_slice($argv, 1));

if (isset($options['help'])) {
    printUsage();
    exit(0);
}

$backupPath = $options['backup'] ?? $defaultBackupPath;
$databaseName = $options['database-name'] ?? 'scholarship_old_data';
$host = $options['host'] ?? '127.0.0.1';
$port = (int) ($options['port'] ?? 3306);
$user = $options['user'] ?? 'root';
$pass = $options['password'] ?? '';
$truncate = array_key_exists('truncate', $options);
$fresh = array_key_exists('fresh', $options);
$prepareAppSchema = !array_key_exists('no-prepare-app-schema', $options);

if (!is_file($backupPath)) {
    fwrite(STDERR, "Backup file not found: {$backupPath}\n");
    exit(1);
}

$backup = json_decode(file_get_contents($backupPath), true, 512, JSON_THROW_ON_ERROR);
$tables = $backup['tables'] ?? null;
if (!is_array($tables)) {
    fwrite(STDERR, "The backup file does not contain a tables object.\n");
    exit(1);
}

[$serverPdo, $serverInfo] = connectMySqlServer($host, $port, $user, $pass);
echo 'Connected to MySQL server at ' . $serverInfo['host'] . ':' . $serverInfo['port'] . PHP_EOL;

if ($fresh) {
    dropDatabase($serverPdo, $databaseName);
    echo "Dropped database if it existed: {$databaseName}" . PHP_EOL;
}

createDatabase($serverPdo, $databaseName);
echo "Database ready: {$databaseName}" . PHP_EOL;

[$pdo, $connectionInfo] = connectMySqlDatabase($host, $port, $user, $pass, $databaseName);
echo 'Connected to ' . $connectionInfo['host'] . ':' . $connectionInfo['port'] . '/' . $connectionInfo['database'] . PHP_EOL;

echo "Creating tables from backup snapshot..." . PHP_EOL;
bootstrapBackupTables($pdo, $tables);
echo "Backup tables ready." . PHP_EOL;

if ($truncate) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (array_keys($tables) as $tableName) {
        if (!tableExists($pdo, $tableName)) {
            continue;
        }
        $pdo->exec('TRUNCATE TABLE ' . quoteIdentifier($tableName));
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    echo "Existing rows cleared." . PHP_EOL;
} else {
    if (databaseHasAnyRows($pdo, array_keys($tables))) {
        fwrite(STDERR, "The target database already has data. Re-run with --truncate, --fresh, or point the script at an empty database.\n");
        exit(1);
    }
}

$pdo->beginTransaction();

$restoreOrder = [
    'users',
    'students',
    'scholarships',
    'announcements',
    'forms',
    'form_fields',
    'applications',
    'application_exams',
    'application_responses',
    'documents',
    'exam_questions',
    'exam_submissions',
    'exam_answers',
    'notifications',
    'password_resets',
    'announcement_attachments',
    'conversations',
    'messages',
    'support_tickets',
    'support_messages',
    'uploaded_files',
];

$remainingTables = array_values(array_diff(array_keys($tables), $restoreOrder));
sort($remainingTables, SORT_NATURAL | SORT_FLAG_CASE);
$tableOrder = array_merge($restoreOrder, $remainingTables);

$autoIncrementMaximums = [];

foreach ($tableOrder as $tableName) {
    if (!isset($tables[$tableName])) {
        continue;
    }

    $tableInfo = $tables[$tableName];
    $columns = $tableInfo['columns'] ?? [];
    if (!is_array($columns) || $columns === []) {
        $firstRow = $tableInfo['rows'][0] ?? null;
        if (is_array($firstRow)) {
            $columns = array_keys($firstRow);
        }
    }

    if ($columns === []) {
        echo "Skipping {$tableName} (no columns)." . PHP_EOL;
        continue;
    }

    $stmt = prepareInsertStatement($pdo, $tableName, $columns);
    $rows = $tableInfo['rows'] ?? [];
    $restoredCount = 0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $params = [];
        foreach ($columns as $columnName) {
            $params[] = normalizeBackupValue($row[$columnName] ?? null);

            if ($columnName === 'id' && isset($row[$columnName]) && is_numeric($row[$columnName])) {
                $autoIncrementMaximums[$tableName] = max($autoIncrementMaximums[$tableName] ?? 0, (int) $row[$columnName]);
            }
        }

        $stmt->execute($params);
        $restoredCount++;
    }

    echo sprintf("Restored %s: %d row(s).", $tableName, $restoredCount) . PHP_EOL;
}

$pdo->commit();

foreach ($autoIncrementMaximums as $tableName => $maxId) {
    setAutoIncrementValue($pdo, $tableName, $maxId);
}

if ($prepareAppSchema) {
    bootstrapAppSchema($pdo);
    echo "Application schema helpers ran." . PHP_EOL;
}

echo "Restore complete." . PHP_EOL;

function printUsage(): void
{
    $message = <<<TXT
Usage:
  php tools/restore_neon_backup_json_mysql.php [options]

Options:
  --backup PATH             Backup JSON file to restore
  --host HOST               MySQL host (default: 127.0.0.1)
  --port PORT               MySQL port (default: 3306)
  --user USER               MySQL user (default: root)
  --password PASS           MySQL password (default: empty)
  --database-name NAME      Target database name (default: scholarship_old_data)
  --fresh                   Drop and recreate the target database first
  --truncate                Clear existing rows in the target database tables
  --no-prepare-app-schema   Skip the app schema bootstrap helpers
  --help, -h                Show this help

Notes:
  - The target must be a reachable MySQL/MariaDB server.
  - By default the script creates or reuses scholarship_old_data.
  - Use --fresh if you want to replace the whole target database.
TXT;

    echo $message . PHP_EOL;
}

function parseCliOptions(array $args): array
{
    $options = [];

    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];

        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }

        if ($arg === '--truncate') {
            $options['truncate'] = true;
            continue;
        }

        if ($arg === '--fresh') {
            $options['fresh'] = true;
            continue;
        }

        if ($arg === '--no-prepare-app-schema') {
            $options['no-prepare-app-schema'] = true;
            continue;
        }

        if (strpos($arg, '--backup=') === 0) {
            $options['backup'] = substr($arg, 9);
            continue;
        }

        if ($arg === '--backup' && isset($args[$i + 1])) {
            $options['backup'] = $args[++$i];
            continue;
        }

        if (strpos($arg, '--host=') === 0) {
            $options['host'] = substr($arg, 7);
            continue;
        }

        if ($arg === '--host' && isset($args[$i + 1])) {
            $options['host'] = $args[++$i];
            continue;
        }

        if (strpos($arg, '--port=') === 0) {
            $options['port'] = substr($arg, 7);
            continue;
        }

        if ($arg === '--port' && isset($args[$i + 1])) {
            $options['port'] = $args[++$i];
            continue;
        }

        if (strpos($arg, '--user=') === 0) {
            $options['user'] = substr($arg, 7);
            continue;
        }

        if ($arg === '--user' && isset($args[$i + 1])) {
            $options['user'] = $args[++$i];
            continue;
        }

        if (strpos($arg, '--password=') === 0) {
            $options['password'] = substr($arg, 11);
            continue;
        }

        if ($arg === '--password' && isset($args[$i + 1])) {
            $options['password'] = $args[++$i];
            continue;
        }

        if (strpos($arg, '--database-name=') === 0) {
            $options['database-name'] = substr($arg, 16);
            continue;
        }

        if ($arg === '--database-name' && isset($args[$i + 1])) {
            $options['database-name'] = $args[++$i];
            continue;
        }
    }

    return $options;
}

function connectMySqlServer(string $host, int $port, string $user, string $pass): array
{
    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return [$pdo, [
        'host' => $host,
        'port' => $port,
    ]];
}

function connectMySqlDatabase(string $host, int $port, string $user, string $pass, string $databaseName): array
{
    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $databaseName . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+08:00'");

    return [$pdo, [
        'host' => $host,
        'port' => $port,
        'database' => $databaseName,
    ]];
}

function createDatabase(PDO $pdo, string $databaseName): void
{
    $pdo->exec(
        'CREATE DATABASE IF NOT EXISTS ' . quoteIdentifier($databaseName)
        . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
    );
}

function dropDatabase(PDO $pdo, string $databaseName): void
{
    $pdo->exec('DROP DATABASE IF EXISTS ' . quoteIdentifier($databaseName));
}

function quoteIdentifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
        throw new InvalidArgumentException('Invalid identifier: ' . $identifier);
    }

    return '`' . str_replace('`', '``', $identifier) . '`';
}

function tableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
        AND table_name = ?
    ");
    $stmt->execute([$tableName]);

    return (int) $stmt->fetchColumn() > 0;
}

function tableHasRows(PDO $pdo, string $tableName): bool
{
    if (!tableExists($pdo, $tableName)) {
        return false;
    }

    $stmt = $pdo->query('SELECT 1 FROM ' . quoteIdentifier($tableName) . ' LIMIT 1');
    return $stmt->fetchColumn() !== false;
}

function databaseHasAnyRows(PDO $pdo, array $tables): bool
{
    foreach ($tables as $tableName) {
        if (tableHasRows($pdo, $tableName)) {
            return true;
        }
    }

    return false;
}

function bootstrapBackupTables(PDO $pdo, array $tables): void
{
    foreach ($tables as $tableName => $tableInfo) {
        if (tableExists($pdo, $tableName)) {
            continue;
        }

        $columns = $tableInfo['columns'] ?? [];
        if (!is_array($columns) || $columns === []) {
            $firstRow = $tableInfo['rows'][0] ?? null;
            if (is_array($firstRow)) {
                $columns = array_keys($firstRow);
            }
        }

        if ($columns === []) {
            continue;
        }

        if ($tableName === 'uploaded_files') {
            createUploadedFilesTable($pdo);
            continue;
        }

        createGenericBackupTable($pdo, $tableName, $columns);
    }
}

function createUploadedFilesTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS uploaded_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            storage_key VARCHAR(80) NOT NULL UNIQUE,
            folder_name VARCHAR(120) NULL,
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(120) NOT NULL,
            file_size INT NOT NULL DEFAULT 0,
            content_blob LONGBLOB NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci
    ");
}

function createGenericBackupTable(PDO $pdo, string $tableName, array $columns): void
{
    $definitions = [];
    $hasId = in_array('id', $columns, true);

    foreach ($columns as $columnName) {
        $definitions[] = '    ' . quoteIdentifier($columnName) . ' ' . inferBackupColumnType($columnName);
    }

    if ($hasId) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . quoteIdentifier($tableName) . " (\n" .
            implode(",\n", $definitions) .
            "\n) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
        );
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS ' . quoteIdentifier($tableName) . " (\n" .
        implode(",\n", $definitions) .
        "\n) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
    );
}

function inferBackupColumnType(string $columnName): string
{
    if ($columnName === 'id') {
        return 'INT AUTO_INCREMENT PRIMARY KEY';
    }

    if ($columnName === 'content_blob') {
        return 'LONGBLOB NOT NULL';
    }

    if (in_array($columnName, ['application_id', 'student_id', 'user_id', 'scholarship_id', 'form_id', 'ticket_id', 'conversation_id', 'sender_id', 'document_id', 'admin_id', 'question_id', 'submission_id', 'announcement_id'], true) || preg_match('/_id$/', $columnName)) {
        return 'INT NULL';
    }

    if (in_array($columnName, ['created_at', 'updated_at', 'submitted_at', 'resolved_at', 'email_verified_at', 'start_time', 'end_time', 'uploaded_at'], true) || preg_match('/_at$/', $columnName)) {
        return 'TIMESTAMP NULL';
    }

    if (in_array($columnName, ['deadline', 'birthdate', 'date_of_birth', 'end_of_term'], true) || preg_match('/_date$/', $columnName)) {
        return 'DATE NULL';
    }

    if (in_array($columnName, ['file_size', 'available_slots', 'passing_grade', 'passing_score', 'exam_duration'], true) || preg_match('/_count$/', $columnName) || preg_match('/^is_/', $columnName) || preg_match('/^has_/', $columnName) || preg_match('/_slots$/', $columnName) || preg_match('/_duration$/', $columnName) || preg_match('/_score$/', $columnName) || preg_match('/_grade$/', $columnName)) {
        return 'INT NULL';
    }

    return 'TEXT';
}

function prepareInsertStatement(PDO $pdo, string $tableName, array $columns): PDOStatement
{
    $placeholders = [];

    foreach ($columns as $columnName) {
        $placeholders[] = $columnName === 'content_blob' ? 'UNHEX(?)' : '?';
    }

    $sql = 'INSERT INTO ' . quoteIdentifier($tableName)
        . ' (' . implode(', ', array_map('quoteIdentifier', $columns)) . ') VALUES ('
        . implode(', ', $placeholders)
        . ')';

    return $pdo->prepare($sql);
}

function normalizeBackupValue($value)
{
    if ($value === null) {
        return null;
    }

    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    if (is_array($value)) {
        if (($value['type'] ?? null) === 'Buffer' && isset($value['data']) && is_array($value['data'])) {
            $binary = '';
            foreach ($value['data'] as $byte) {
                $binary .= chr((int) $byte);
            }

            return bin2hex($binary);
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    return $value;
}

function setAutoIncrementValue(PDO $pdo, string $tableName, int $maxId): void
{
    if ($maxId <= 0) {
        return;
    }

    $next = $maxId + 1;
    $pdo->exec('ALTER TABLE ' . quoteIdentifier($tableName) . ' AUTO_INCREMENT = ' . (int) $next);
}

function bootstrapAppSchema(PDO $pdo): void
{
    $schemaPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_schema.php';
    $sessionPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'session_handler.php';

    require_once $schemaPath;
    require_once $sessionPath;

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

    try {
        if (function_exists('dbEnsureUserStudentSyncSchema')) {
            dbEnsureUserStudentSyncSchema($pdo);
        }

        if (function_exists('dbEnsureScholarshipColumns')) {
            dbEnsureScholarshipColumns($pdo);
        }

        if (function_exists('dbEnsureApplicationsSchema')) {
            dbEnsureApplicationsSchema($pdo);
        }

        if (function_exists('dbEnsureDocumentReviewSchema')) {
            dbEnsureDocumentReviewSchema($pdo);
        }

        if (function_exists('dbEnsureFormsSchema')) {
            dbEnsureFormsSchema($pdo);
        }

        if (function_exists('dbEnsureExamSchema')) {
            dbEnsureExamSchema($pdo);
        }

        if (function_exists('dbEnsureNotificationsTable')) {
            dbEnsureNotificationsTable($pdo);
        }

        if (function_exists('dbEnsureAnnouncementsSchema')) {
            dbEnsureAnnouncementsSchema($pdo);
        }

        if (function_exists('dbEnsureMessagingSchema')) {
            dbEnsureMessagingSchema($pdo);
        }

        if (function_exists('dbEnsureSessionStoreSchema')) {
            dbEnsureSessionStoreSchema($pdo);
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
