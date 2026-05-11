<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

set_time_limit(0);
ini_set('memory_limit', '-1');
date_default_timezone_set('Asia/Manila');

$basePath = dirname(__DIR__);
$timestamp = date('Ymd-His');

$options = parseCliOptions(array_slice($argv, 1));

if (isset($options['help'])) {
    printUsage();
    exit(0);
}

$databaseUrl = $options['database-url']
    ?? envValue('SUPABASE_DB_URL')
    ?? envValue('SUPABASE_DATABASE_URL')
    ?? envValue('DATABASE_URL')
    ?? envValue('POSTGRES_URL')
    ?? envValue('DB_URL')
    ?? buildDatabaseUrlFromEnv();
if ($databaseUrl === null || $databaseUrl === '') {
    fwrite(STDERR, "SUPABASE_DB_URL, SUPABASE_DATABASE_URL, DATABASE_URL, POSTGRES_URL, or DB_URL is required.\n");
    exit(1);
}

$exportRoot = $options['out-dir'] ?? ($basePath . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'postgres-export-' . $timestamp);
if (is_file($exportRoot)) {
    fwrite(STDERR, "Export path points to a file, not a directory: {$exportRoot}\n");
    exit(1);
}

$jsonPath = rtrim($exportRoot, '/\\') . DIRECTORY_SEPARATOR . 'backup.json';
$filesRoot = rtrim($exportRoot, '/\\') . DIRECTORY_SEPARATOR . 'files';
$manifestPath = rtrim($exportRoot, '/\\') . DIRECTORY_SEPARATOR . 'manifest.json';

ensureDirectory($exportRoot);
ensureDirectory($filesRoot);

$dbConfig = parseDatabaseUrl($databaseUrl);
$pdo = connectPostgres($dbConfig);

echo sprintf(
    "Connected to %s:%d/%s\n",
    $dbConfig['host'],
    $dbConfig['port'],
    $dbConfig['database']
);

$tables = fetchPublicTables($pdo);
echo 'Found ' . count($tables) . " public table(s).\n";

$jsonHandle = fopen($jsonPath, 'wb');
if ($jsonHandle === false) {
    $pdo->rollBack();
    throw new RuntimeException("Unable to open export file for writing: {$jsonPath}");
}

$manifest = [
    'createdAt' => gmdate(DATE_ATOM),
    'source' => [
        'driver' => 'pgsql',
        'host' => $dbConfig['host'],
        'port' => $dbConfig['port'],
        'database' => $dbConfig['database'],
        'sslmode' => $dbConfig['sslmode'],
        'channel_binding' => $dbConfig['channel_binding'],
        'options' => $dbConfig['options'],
    ],
    'backup' => [
        'json' => 'backup.json',
        'tableCount' => count($tables),
        'rowCount' => 0,
    ],
    'tables' => [],
    'files' => [
        'database_uploads' => [
            'directory' => 'files/database_uploads',
            'count' => 0,
            'errors' => [],
            'items' => [],
        ],
        'public_uploads' => [
            'directory' => 'files/public/uploads',
            'count' => 0,
            'source' => 'public/uploads',
        ],
        'images' => [
            'directory' => 'files/images',
            'count' => 0,
            'source' => 'images',
        ],
    ],
    'warnings' => [],
];

try {
    fwrite($jsonHandle, '{');
    fwrite($jsonHandle, '"createdAt":' . jsonEncodeValue($manifest['createdAt']) . ',');
    fwrite($jsonHandle, '"source":' . jsonEncodeValue($manifest['source']) . ',');
    fwrite($jsonHandle, '"tables":{');

    $tableIndex = 0;
    foreach ($tables as $tableName) {
        $columns = fetchTableColumns($pdo, $tableName);
        $rowCount = countTableRows($pdo, $tableName);
        $columnNames = array_map(static fn(array $column): string => $column['name'], $columns);
        $byteaColumns = [];
        foreach ($columns as $column) {
            if (($column['data_type'] ?? '') === 'bytea') {
                $byteaColumns[] = $column['name'];
            }
        }

        $manifest['tables'][$tableName] = [
            'columns' => $columnNames,
            'rowCount' => $rowCount,
        ];
        $manifest['backup']['rowCount'] += $rowCount;

        if ($tableIndex > 0) {
            fwrite($jsonHandle, ',');
        }
        $tableIndex++;

        echo sprintf("Exporting %-32s %d row(s)\n", $tableName, $rowCount);

        fwrite($jsonHandle, jsonEncodeValue($tableName));
        fwrite($jsonHandle, ':{');
        fwrite($jsonHandle, '"columns":' . jsonEncodeValue($columnNames) . ',');
        fwrite($jsonHandle, '"rowCount":' . (int) $rowCount . ',');
        fwrite($jsonHandle, '"rows":[');

        $rowCountWritten = exportTableRows(
            $pdo,
            $tableName,
            $columns,
            $byteaColumns,
            $filesRoot,
            $exportRoot,
            $jsonHandle,
            $manifest
        );

        if ($rowCountWritten !== $rowCount) {
            $manifest['warnings'][] = sprintf(
                'Row count mismatch for %s: expected %d, exported %d.',
                $tableName,
                $rowCount,
                $rowCountWritten
            );
        }

        fwrite($jsonHandle, ']}');
        fflush($jsonHandle);
    }

    fwrite($jsonHandle, '}}');
    fclose($jsonHandle);
} catch (Throwable $e) {
    if (is_resource($jsonHandle)) {
        fclose($jsonHandle);
    }
    throw $e;
}

try {
    $publicUploadsSource = $basePath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads';
    if (is_dir($publicUploadsSource)) {
        $manifest['files']['public_uploads']['count'] = copyDirectoryTree($publicUploadsSource, $filesRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads');
        echo 'Copied ' . $manifest['files']['public_uploads']['count'] . " local upload file(s).\n";
    } else {
        $manifest['warnings'][] = 'public/uploads folder not found, so legacy local uploads were not copied.';
    }
} catch (Throwable $e) {
    $manifest['warnings'][] = 'Failed to copy public/uploads: ' . $e->getMessage();
}

try {
    $imagesSource = $basePath . DIRECTORY_SEPARATOR . 'images';
    if (is_dir($imagesSource)) {
        $manifest['files']['images']['count'] = copyDirectoryTree($imagesSource, $filesRoot . DIRECTORY_SEPARATOR . 'images');
        echo 'Copied ' . $manifest['files']['images']['count'] . " image file(s).\n";
    } else {
        $manifest['warnings'][] = 'images folder not found, so image assets were not copied.';
    }
} catch (Throwable $e) {
    $manifest['warnings'][] = 'Failed to copy images: ' . $e->getMessage();
}

file_put_contents($manifestPath, jsonEncodePretty($manifest));

echo "Backup JSON: {$jsonPath}\n";
echo "Files root: {$filesRoot}\n";
echo "Manifest: {$manifestPath}\n";
echo "Done.\n";

function printUsage(): void
{
    $message = <<<TXT
Usage:
  php tools/export_neon_database.php [--database-url URL] [--out-dir PATH]

Options:
  --database-url URL   PostgreSQL connection string for Supabase or another Postgres database.
  --out-dir PATH       Export directory. Defaults to backups/postgres-export-YYYYMMDD-HHMMSS
  --help               Show this message.

Output:
  - backup.json
  - manifest.json
  - files/database_uploads/
  - files/public/uploads/
  - files/images/
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

        if (strpos($arg, '--database-url=') === 0) {
            $options['database-url'] = substr($arg, 15);
            continue;
        }

        if ($arg === '--database-url' && isset($args[$i + 1])) {
            $options['database-url'] = $args[++$i];
            continue;
        }

        if (strpos($arg, '--out-dir=') === 0) {
            $options['out-dir'] = substr($arg, 10);
            continue;
        }

        if ($arg === '--out-dir' && isset($args[$i + 1])) {
            $options['out-dir'] = $args[++$i];
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

function firstEnvValue(array $keys): ?string
{
    foreach ($keys as $key) {
        $value = envValue((string) $key);
        if ($value !== null) {
            return $value;
        }
    }

    return null;
}

function buildDatabaseUrlFromEnv(): ?string
{
    $projectId = firstEnvValue(['SUPABASE_PROJECT_ID']);
    $host = firstEnvValue(['DB_HOST', 'PGHOST', 'SUPABASE_DB_HOST', 'MYSQL_HOST']);
    if (($host === null || $host === '') && $projectId !== null && $projectId !== '') {
        $host = 'aws-1-ap-southeast-1.pooler.supabase.com';
    }

    $database = firstEnvValue(['DB_NAME', 'PGDATABASE', 'SUPABASE_DB_NAME', 'MYSQL_DATABASE']);
    if (($database === null || $database === '') && $projectId !== null && $projectId !== '') {
        $database = 'postgres';
    }

    $user = firstEnvValue(['DB_USER', 'PGUSER', 'SUPABASE_DB_USER', 'MYSQL_USER']);
    if (($user === null || $user === '') && $projectId !== null && $projectId !== '') {
        $user = 'postgres.' . $projectId;
    }

    if ($host === null || $database === null || $user === null) {
        return null;
    }

    $pass = firstEnvValue(['DB_PASS', 'PGPASSWORD', 'SUPABASE_DB_PASS', 'MYSQL_PASSWORD']) ?? '';
    $port = firstEnvValue(['DB_PORT', 'PGPORT', 'SUPABASE_DB_PORT', 'MYSQL_PORT']);
    if (($port === null || $port === '') && $projectId !== null && $projectId !== '') {
        $port = '6543';
    }

    $sslmode = firstEnvValue(['DB_SSL_MODE', 'SUPABASE_DB_SSL_MODE']);
    if (($sslmode === null || $sslmode === '') && (
        ($projectId !== null && $projectId !== '') || preg_match('/\.supabase\.co$/i', $host)
    )) {
        $sslmode = 'require';
    }

    $query = [];
    if ($sslmode !== null && $sslmode !== '') {
        $query[] = 'sslmode=' . rawurlencode($sslmode);
    }

    $channelBinding = firstEnvValue(['DB_CHANNEL_BINDING']);
    if ($channelBinding !== null && $channelBinding !== '') {
        $query[] = 'channel_binding=' . rawurlencode($channelBinding);
    }

    $options = firstEnvValue(['DB_PG_OPTIONS']);
    if ($options !== null && $options !== '') {
        $query[] = 'options=' . rawurlencode($options);
    }

    $dsn = 'postgresql://' . rawurlencode($user) . ':' . rawurlencode($pass) . '@' . $host;
    if ($port !== null && $port !== '') {
        $dsn .= ':' . (int) $port;
    }
    $dsn .= '/' . rawurlencode($database);

    if ($query !== []) {
        $dsn .= '?' . implode('&', $query);
    }

    return $dsn;
}

function parseDatabaseUrl(string $databaseUrl): array
{
    $parsedUrl = @parse_url($databaseUrl);
    if (!is_array($parsedUrl) || empty($parsedUrl['host'])) {
        throw new RuntimeException('Invalid PostgreSQL connection string.');
    }

    $queryParams = [];
    if (!empty($parsedUrl['query'])) {
        parse_str($parsedUrl['query'], $queryParams);
    }

    $scheme = strtolower((string) ($parsedUrl['scheme'] ?? ''));
    if (!in_array($scheme, ['postgres', 'postgresql', 'pgsql'], true)) {
        throw new RuntimeException('Only PostgreSQL connection strings are supported.');
    }

    $host = (string) $parsedUrl['host'];
    $port = isset($parsedUrl['port']) ? (int) $parsedUrl['port'] : 5432;
    $database = isset($parsedUrl['path']) ? ltrim((string) $parsedUrl['path'], '/') : '';
    if ($database === '') {
        throw new RuntimeException('The PostgreSQL connection string is missing a database name.');
    }

    $user = isset($parsedUrl['user']) ? rawurldecode((string) $parsedUrl['user']) : '';
    $pass = isset($parsedUrl['pass']) ? rawurldecode((string) $parsedUrl['pass']) : '';
    $sslmode = (string) ($queryParams['sslmode'] ?? 'require');
    $channelBinding = (string) ($queryParams['channel_binding'] ?? '');
    $options = (string) ($queryParams['options'] ?? '');

    return [
        'host' => $host,
        'port' => $port,
        'database' => $database,
        'user' => $user,
        'pass' => $pass,
        'sslmode' => $sslmode,
        'channel_binding' => $channelBinding,
        'options' => $options,
    ];
}

function connectPostgres(array $config): PDO
{
    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s',
        $config['host'],
        $config['port'],
        $config['database']
    );

    if (($config['sslmode'] ?? '') !== '') {
        $dsn .= ';sslmode=' . $config['sslmode'];
    }

    if (($config['options'] ?? '') !== '') {
        $dsn .= ';options=' . $config['options'];
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ];

    return new PDO($dsn, $config['user'], $config['pass'], $options);
}

function fetchPublicTables(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = 'public'
          AND table_type = 'BASE TABLE'
        ORDER BY table_name
    ");

    if ($stmt === false) {
        throw new RuntimeException('Unable to list public tables.');
    }

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function fetchTableColumns(PDO $pdo, string $tableName): array
{
    $sql = "
        SELECT column_name, data_type
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = " . quoteLiteral($tableName) . "
        ORDER BY ordinal_position
    ";

    $stmt = $pdo->query($sql);
    if ($stmt === false) {
        throw new RuntimeException("Unable to list columns for table {$tableName}.");
    }

    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columns[] = [
            'name' => (string) ($row['column_name'] ?? ''),
            'data_type' => (string) ($row['data_type'] ?? ''),
        ];
    }

    return $columns;
}

function countTableRows(PDO $pdo, string $tableName): int
{
    $stmt = $pdo->query('SELECT COUNT(*) FROM ' . quoteIdentifier($tableName));
    if ($stmt === false) {
        throw new RuntimeException("Unable to count rows for table {$tableName}.");
    }

    return (int) $stmt->fetchColumn();
}

function buildSelectSql(string $tableName, array $columns): string
{
    $parts = [];
    foreach ($columns as $column) {
        $columnName = (string) ($column['name'] ?? '');
        $dataType = strtolower((string) ($column['data_type'] ?? ''));

        if ($dataType === 'bytea') {
            $parts[] = 'encode(' . quoteIdentifier($columnName) . ", 'base64') AS " . quoteIdentifier($columnName);
            continue;
        }

        $parts[] = quoteIdentifier($columnName);
    }

    return 'SELECT ' . implode(', ', $parts) . ' FROM ' . quoteIdentifier($tableName);
}

function exportTableRows(
    PDO $pdo,
    string $tableName,
    array $columns,
    array $byteaColumns,
    string $filesRoot,
    string $exportRoot,
    $jsonHandle,
    array &$manifest
): int {
    $columnNames = array_map(static fn(array $column): string => $column['name'], $columns);
    $hasNumericId = in_array('id', $columnNames, true);
    $pageSize = $tableName === 'uploaded_files' ? 25 : 250;

    $rowsWritten = 0;

    if (!$hasNumericId) {
        $selectSql = buildSelectSql($tableName, $columns);
        $stmt = $pdo->query($selectSql);
        if ($stmt === false) {
            throw new RuntimeException("Failed to query table {$tableName}.");
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $normalizedRow = normalizeRow($row, $byteaColumns);
            if ($tableName === 'uploaded_files') {
                $fileInfo = exportDatabaseFile($normalizedRow, $filesRoot, $exportRoot);
                $manifest['files']['database_uploads']['items'][] = $fileInfo;
                $manifest['files']['database_uploads']['count']++;
                $normalizedRow['content_blob'] = [
                    'type' => 'Buffer',
                    'file' => $fileInfo['export_path'],
                ];
            }

            if ($rowsWritten > 0) {
                fwrite($jsonHandle, ',');
            }
            fwrite($jsonHandle, jsonEncodeValue($normalizedRow));
            $rowsWritten++;
        }

        return $rowsWritten;
    }

    $lastId = 0;
    while (true) {
        $selectSql = buildPaginatedSelectSql($tableName, $columns, $lastId, $pageSize);
        $stmt = $pdo->query($selectSql);
        if ($stmt === false) {
            throw new RuntimeException("Failed to query table {$tableName}.");
        }

        $pageRows = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $normalizedRow = normalizeRow($row, $byteaColumns);
            if ($tableName === 'uploaded_files') {
                $fileInfo = exportDatabaseFile($normalizedRow, $filesRoot, $exportRoot);
                $manifest['files']['database_uploads']['items'][] = $fileInfo;
                $manifest['files']['database_uploads']['count']++;
                $normalizedRow['content_blob'] = [
                    'type' => 'Buffer',
                    'file' => $fileInfo['export_path'],
                ];
            }

            if ($rowsWritten > 0) {
                fwrite($jsonHandle, ',');
            }
            fwrite($jsonHandle, jsonEncodeValue($normalizedRow));
            $rowsWritten++;
            $pageRows++;

            if (isset($row['id']) && is_numeric($row['id'])) {
                $lastId = max($lastId, (int) $row['id']);
            }
        }

        if ($pageRows < $pageSize) {
            break;
        }
    }

    return $rowsWritten;
}

function buildPaginatedSelectSql(string $tableName, array $columns, int $lastId, int $pageSize): string
{
    return buildSelectSql($tableName, $columns)
        . ' WHERE ' . quoteIdentifier('id') . ' > ' . (int) $lastId
        . ' ORDER BY ' . quoteIdentifier('id') . ' ASC'
        . ' LIMIT ' . (int) $pageSize;
}

function normalizeRow(array $row, array $byteaColumns): array
{
    foreach ($byteaColumns as $columnName) {
        if (!array_key_exists($columnName, $row) || $row[$columnName] === null) {
            continue;
        }

        $row[$columnName] = [
            'type' => 'Buffer',
            'base64' => (string) $row[$columnName],
        ];
    }

    return $row;
}

function exportDatabaseFile(array $row, string $filesRoot, string $exportRoot): array
{
    $storageKey = trim((string) ($row['storage_key'] ?? ''));
    $folderName = trim(str_replace('\\', '/', (string) ($row['folder_name'] ?? '')), '/');
    $originalName = sanitizeFileName((string) ($row['original_name'] ?? 'download'));
    $mimeType = (string) ($row['mime_type'] ?? 'application/octet-stream');
    $fileSize = (int) ($row['file_size'] ?? 0);
    $base64 = (string) (($row['content_blob']['base64'] ?? '') ?: '');

    $relativeDir = 'files/database_uploads';
    if ($folderName !== '') {
        foreach (explode('/', $folderName) as $segment) {
            $segment = sanitizePathSegment($segment);
            if ($segment !== '') {
                $relativeDir .= '/' . $segment;
            }
        }
    }

    $relativeName = ($storageKey !== '' ? $storageKey . '__' : '') . $originalName;
    $relativePath = $relativeDir . '/' . $relativeName;
    $absolutePath = rtrim($exportRoot, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

    ensureDirectory(dirname($absolutePath));

    $binary = $base64 === '' ? '' : base64_decode($base64, true);
    if ($binary === false) {
        $binary = '';
    }

    file_put_contents($absolutePath, $binary);

    return [
        'storage_key' => $storageKey,
        'folder_name' => $folderName,
        'original_name' => $originalName,
        'mime_type' => $mimeType,
        'file_size' => $fileSize,
        'export_path' => $relativePath,
        'written_bytes' => strlen($binary),
    ];
}

function sanitizePathSegment(string $value): string
{
    $value = trim(str_replace(["\0", "\r", "\n"], '', $value));
    $value = preg_replace('/[^A-Za-z0-9.\-_]/', '_', $value);
    $value = trim((string) $value, '._');

    return $value !== '' ? $value : 'files';
}

function sanitizeFileName(string $name): string
{
    $candidate = trim(str_replace(["\0", "\r", "\n"], '', $name));
    $baseName = basename($candidate !== '' ? $candidate : 'file');
    $safeFilename = preg_replace('/[^A-Za-z0-9.\-]/', '_', $baseName);
    $safeFilename = trim((string) $safeFilename, '._');

    return $safeFilename !== '' ? $safeFilename : 'file';
}

function copyDirectoryTree(string $source, string $destination): int
{
    if (!is_dir($source)) {
        return 0;
    }

    $sourceRoot = rtrim((string) realpath($source), '/\\');
    if ($sourceRoot === '') {
        return 0;
    }

    ensureDirectory($destination);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $copied = 0;
    foreach ($iterator as $item) {
        $targetPath = $destination . DIRECTORY_SEPARATOR . substr($item->getPathname(), strlen($sourceRoot) + 1);
        if ($item->isDir()) {
            ensureDirectory($targetPath);
            continue;
        }

        ensureDirectory(dirname($targetPath));
        if (!copy($item->getPathname(), $targetPath)) {
            throw new RuntimeException('Failed to copy file: ' . $item->getPathname());
        }
        $copied++;
    }

    return $copied;
}

function ensureDirectory(string $path): void
{
    if ($path === '') {
        return;
    }

    if (!is_dir($path) && !@mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException('Failed to create directory: ' . $path);
    }
}

function quoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function quoteLiteral(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

function jsonEncodeValue($value): string
{
    $encoded = json_encode(
        $value,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($encoded === false) {
        throw new RuntimeException('JSON encoding failed: ' . json_last_error_msg());
    }

    return $encoded;
}

function jsonEncodePretty($value): string
{
    $encoded = json_encode(
        $value,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($encoded === false) {
        throw new RuntimeException('JSON encoding failed: ' . json_last_error_msg());
    }

    return $encoded . PHP_EOL;
}
