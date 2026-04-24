<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

set_time_limit(0);
ini_set('memory_limit', '-1');

$basePath = dirname(__DIR__);
$defaultBackupPath = $basePath . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'neon-before-replace-20260411-003956.json';
$defaultSchemaPath = $basePath . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'postgres_schema.sql';

$options = parseCliOptions(array_slice($argv, 1));

if (isset($options['help'])) {
    printUsage();
    exit(0);
}

$backupPath = $options['backup'] ?? $defaultBackupPath;
$schemaPath = $options['schema'] ?? $defaultSchemaPath;
$databaseUrl = $options['database-url'] ?? envValue('DATABASE_URL') ?? envValue('POSTGRES_URL') ?? envValue('DB_URL');

if ($databaseUrl === null || $databaseUrl === '') {
    fwrite(STDERR, "DATABASE_URL, POSTGRES_URL, or DB_URL is required.\n");
    exit(1);
}

if (!is_file($backupPath)) {
    fwrite(STDERR, "Backup file not found: {$backupPath}\n");
    exit(1);
}

if (!is_file($schemaPath)) {
    fwrite(STDERR, "Schema file not found: {$schemaPath}\n");
    exit(1);
}

[$pdo, $connectionInfo] = connectPostgres($databaseUrl);
echo 'Connected to ' . $connectionInfo['host'] . ':' . $connectionInfo['port'] . '/' . $connectionInfo['database'] . PHP_EOL;

echo "Applying PostgreSQL schema..." . PHP_EOL;
executeSqlFile($pdo, $schemaPath);
echo "Schema ready." . PHP_EOL;

$backup = json_decode(file_get_contents($backupPath), true, 512, JSON_THROW_ON_ERROR);
$tables = $backup['tables'] ?? null;
if (!is_array($tables)) {
    fwrite(STDERR, "The backup file does not contain a tables object.\n");
    exit(1);
}

$truncate = array_key_exists('truncate', $options);
if ($truncate) {
    $truncateTables = array_keys($tables);
    $truncateSql = 'TRUNCATE TABLE ' . implode(', ', array_map('quoteIdentifier', $truncateTables)) . ' RESTART IDENTITY CASCADE';
    $pdo->exec($truncateSql);
    echo "Existing rows cleared." . PHP_EOL;
} else {
    if (databaseHasAnyRows($pdo, array_keys($tables))) {
        fwrite(STDERR, "The target database already has data. Re-run with --truncate to replace it, or restore into a fresh database.\n");
        exit(1);
    }
}

bootstrapBackupTables($pdo, $tables);

$pdo->beginTransaction();
$pdo->exec('SET CONSTRAINTS ALL DEFERRED');

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

$sequenceMaximums = [];

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
        }

        $stmt->execute($params);
        $restoredCount++;

        if ($tableName !== 'uploaded_files' && isset($row['id']) && is_numeric($row['id'])) {
            $sequenceMaximums[$tableName] = max($sequenceMaximums[$tableName] ?? 0, (int) $row['id']);
        }
    }

    echo sprintf("Restored %s: %d row(s).", $tableName, $restoredCount) . PHP_EOL;
}

$pdo->commit();

foreach ($sequenceMaximums as $tableName => $maxId) {
    setIdentitySequence($pdo, $tableName, $maxId);
}

echo "Restore complete." . PHP_EOL;

function printUsage(): void
{
    $message = <<<TXT
Usage:
  php tools/restore_neon_backup_json.php [--backup PATH] [--schema PATH] [--database-url URL] [--truncate]

Defaults:
  --backup  backups/neon-before-replace-20260411-003956.json
  --schema  sql/postgres_schema.sql

Notes:
  - The target must be a PostgreSQL database.
  - Run with --truncate if the target already has data and you want to replace it.
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

        if (strpos($arg, '--backup=') === 0) {
            $options['backup'] = substr($arg, 9);
            continue;
        }

        if ($arg === '--backup' && isset($args[$i + 1])) {
            $options['backup'] = $args[++$i];
            continue;
        }

        if (strpos($arg, '--schema=') === 0) {
            $options['schema'] = substr($arg, 9);
            continue;
        }

        if ($arg === '--schema' && isset($args[$i + 1])) {
            $options['schema'] = $args[++$i];
            continue;
        }

        if (strpos($arg, '--database-url=') === 0) {
            $options['database-url'] = substr($arg, 15);
            continue;
        }

        if ($arg === '--database-url' && isset($args[$i + 1])) {
            $options['database-url'] = $args[++$i];
            continue;
        }
    }

    return $options;
}

function envValue(string $key): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null) {
        return null;
    }

    $value = trim((string) $value);
    return $value === '' ? null : $value;
}

function connectPostgres(string $databaseUrl): array
{
    $parsed = parse_url($databaseUrl);
    if (!is_array($parsed)) {
        throw new RuntimeException('Invalid DATABASE_URL value.');
    }

    $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
    if (!in_array($scheme, ['postgres', 'postgresql', 'pgsql'], true)) {
        throw new RuntimeException('This restore tool only supports PostgreSQL connection strings.');
    }

    $query = [];
    if (!empty($parsed['query'])) {
        parse_str($parsed['query'], $query);
    }

    $host = $parsed['host'] ?? '';
    $port = (string) ($parsed['port'] ?? '5432');
    $database = ltrim((string) ($parsed['path'] ?? ''), '/');
    $user = isset($parsed['user']) ? rawurldecode($parsed['user']) : '';
    $pass = isset($parsed['pass']) ? rawurldecode($parsed['pass']) : '';

    if ($host === '' || $database === '' || $user === '') {
        throw new RuntimeException('The DATABASE_URL value is missing host, database, or user information.');
    }

    $dsn = 'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $database;
    if (!empty($query['sslmode'])) {
        $dsn .= ';sslmode=' . $query['sslmode'];
    }
    if (!empty($query['channel_binding'])) {
        $dsn .= ';channel_binding=' . $query['channel_binding'];
    }
    if (!empty($query['options'])) {
        $dsn .= ';options=' . $query['options'];
    }

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET TIME ZONE 'Asia/Manila'");

    return [$pdo, [
        'host' => $host,
        'port' => $port,
        'database' => $database,
    ]];
}

function executeSqlFile(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Failed to read SQL file: ' . $path);
    }

    foreach (splitSqlStatements($sql) as $statement) {
        $pdo->exec($statement);
    }
}

function splitSqlStatements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inString = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];

        if ($inString) {
            $buffer .= $char;

            if ($char === "'" && ($i + 1) < $length && $sql[$i + 1] === "'") {
                $buffer .= "'";
                $i++;
                continue;
            }

            if ($char === "'") {
                $inString = false;
            }

            continue;
        }

        if ($char === '-' && ($i + 1) < $length && $sql[$i + 1] === '-') {
            while ($i < $length && $sql[$i] !== "\n") {
                $i++;
            }
            continue;
        }

        if ($char === "'") {
            $inString = true;
            $buffer .= $char;
            continue;
        }

        if ($char === ';') {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $statement = trim($buffer);
    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
}

function quoteIdentifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
        throw new InvalidArgumentException('Invalid identifier: ' . $identifier);
    }

    return '"' . str_replace('"', '""', $identifier) . '"';
}

function tableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = current_schema()
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
            id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
            storage_key VARCHAR(80) NOT NULL UNIQUE,
            folder_name VARCHAR(120),
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(120) NOT NULL,
            file_size INTEGER NOT NULL DEFAULT 0,
            content_blob BYTEA NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
}

function createGenericBackupTable(PDO $pdo, string $tableName, array $columns): void
{
    $definitions = [];

    foreach ($columns as $columnName) {
        $definitions[] = '    ' . quoteIdentifier($columnName) . ' ' . inferBackupColumnType($columnName);
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS ' . quoteIdentifier($tableName) . " (\n" .
        implode(",\n", $definitions) .
        "\n)"
    );
}

function inferBackupColumnType(string $columnName): string
{
    if ($columnName === 'id') {
        return 'INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY';
    }

    if ($columnName === 'content_blob') {
        return 'BYTEA NOT NULL';
    }

    if (in_array($columnName, ['application_id', 'student_id', 'user_id', 'scholarship_id', 'form_id', 'ticket_id', 'conversation_id', 'sender_id', 'document_id', 'admin_id', 'question_id', 'submission_id', 'announcement_id'], true) || preg_match('/_id$/', $columnName)) {
        return 'INTEGER NULL';
    }

    if (in_array($columnName, ['created_at', 'updated_at', 'submitted_at', 'resolved_at', 'email_verified_at', 'start_time', 'end_time', 'uploaded_at'], true) || preg_match('/_at$/', $columnName)) {
        return 'TIMESTAMP NULL';
    }

    if (in_array($columnName, ['deadline', 'birthdate', 'date_of_birth', 'end_of_term'], true) || preg_match('/_date$/', $columnName)) {
        return 'DATE NULL';
    }

    if (in_array($columnName, ['file_size', 'available_slots', 'passing_grade', 'passing_score', 'exam_duration'], true) || preg_match('/_count$/', $columnName) || preg_match('/^is_/', $columnName) || preg_match('/^has_/', $columnName) || preg_match('/_slots$/', $columnName) || preg_match('/_duration$/', $columnName) || preg_match('/_score$/', $columnName) || preg_match('/_grade$/', $columnName)) {
        return 'INTEGER NULL';
    }

    return 'TEXT';
}

function prepareInsertStatement(PDO $pdo, string $tableName, array $columns): PDOStatement
{
    $placeholders = [];

    foreach ($columns as $columnName) {
        $placeholders[] = $columnName === 'content_blob' ? "decode(?,'hex')" : '?';
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

function setIdentitySequence(PDO $pdo, string $tableName, int $maxId): void
{
    if ($maxId <= 0) {
        return;
    }

    $sequenceStmt = $pdo->prepare("SELECT pg_get_serial_sequence(?, 'id')");
    $sequenceStmt->execute([$tableName]);
    $sequence = $sequenceStmt->fetchColumn();

    if (!$sequence) {
        return;
    }

    $setvalStmt = $pdo->prepare('SELECT setval(?, ?, true)');
    $setvalStmt->execute([$sequence, $maxId]);
}
