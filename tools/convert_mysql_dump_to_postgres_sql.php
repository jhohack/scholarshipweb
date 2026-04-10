<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$basePath = dirname(__DIR__);
$inputPath = $argv[1] ?? ($basePath . DIRECTORY_SEPARATOR . 'scholarship_db (2).sql');
$outputPath = $argv[2] ?? ($basePath . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'postgres_data_from_mysql.sql');

if (!is_file($inputPath)) {
    fwrite(STDERR, "MySQL dump not found: {$inputPath}\n");
    exit(1);
}

$dump = file_get_contents($inputPath);
if ($dump === false) {
    fwrite(STDERR, "Failed to read MySQL dump: {$inputPath}\n");
    exit(1);
}

$insertPattern = '/INSERT INTO\s+`([^`]+)`\s*\((.*?)\)\s*VALUES\s*(.*?);/si';
if (!preg_match_all($insertPattern, $dump, $matches, PREG_SET_ORDER)) {
    fwrite(STDERR, "No INSERT statements were found in {$inputPath}\n");
    exit(1);
}

$convertedStatements = [];
$identityMaximums = [];
$dynamicTables = [];

foreach ($matches as $match) {
    $table = $match[1];
    $columns = parseMysqlColumnList($match[2]);
    $rows = parseMysqlValuesBlock($match[3]);

    if ($rows === []) {
        continue;
    }

    if (preg_match('/^scholarship_submissions_\d+$/', $table)) {
        $dynamicTables[$table] = $columns;
    }

    $convertedRows = [];
    foreach ($rows as $rowTokens) {
        if (count($rowTokens) !== count($columns)) {
            fwrite(
                STDERR,
                "Column/value mismatch for table {$table}. Expected " . count($columns) . ' values but found ' . count($rowTokens) . ".\n"
            );
            exit(1);
        }

        $convertedValues = [];
        foreach ($rowTokens as $index => $token) {
            $column = $columns[$index];
            $convertedValues[] = mysqlValueToPostgresLiteral($token);

            if ($column === 'id') {
                $numericValue = mysqlNumericValue($token);
                if ($numericValue !== null) {
                    $identityMaximums[$table] = max($identityMaximums[$table] ?? 0, $numericValue);
                }
            }
        }

        $convertedRows[] = '(' . implode(', ', $convertedValues) . ')';
    }

    $convertedStatements[$table][] = [
        'columns' => $columns,
        'rows' => $convertedRows,
    ];
}

$tableOrder = [
    'users',
    'students',
    'announcements',
    'scholarships',
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
];

$remainingTables = array_values(array_diff(array_keys($convertedStatements), $tableOrder));
sort($remainingTables, SORT_NATURAL | SORT_FLAG_CASE);
$tableOrder = array_merge($tableOrder, $remainingTables);

$output = [];
$output[] = '-- Generated from ' . basename($inputPath) . ' on ' . date('Y-m-d H:i:s');
$output[] = '-- Import this after sql/postgres_schema.sql';
$output[] = 'BEGIN;';
$output[] = 'SET search_path TO public;';
$output[] = 'SET CONSTRAINTS ALL DEFERRED;';
$output[] = '';

foreach ($dynamicTables as $table => $columns) {
    $output[] = renderDynamicScholarshipTableSql($table, $columns);
    $output[] = '';
}

foreach ($tableOrder as $table) {
    if (!isset($convertedStatements[$table])) {
        continue;
    }

    foreach ($convertedStatements[$table] as $statement) {
        $quotedColumns = array_map('pgQuoteIdentifier', $statement['columns']);
        $output[] = 'INSERT INTO ' . pgQuoteIdentifier($table) . ' (' . implode(', ', $quotedColumns) . ') VALUES';
        $output[] = '    ' . implode(",\n    ", $statement['rows']) . ';';
        $output[] = '';
    }
}

$output[] = '-- Backfill newer application columns that do not exist in the older MySQL dump.';
$output[] = "UPDATE applications
SET year_level = NULLIF(split_part(year_program, ' - ', 1), '')
WHERE (year_level IS NULL OR year_level = '')
  AND year_program IS NOT NULL
  AND position(' - ' in year_program) > 0;";
$output[] = '';
$output[] = "UPDATE applications
SET program = NULLIF(split_part(year_program, ' - ', 2), '')
WHERE (program IS NULL OR program = '')
  AND year_program IS NOT NULL
  AND position(' - ' in year_program) > 0;";
$output[] = '';
$output[] = "UPDATE applications
SET application_type = CASE
    WHEN lower(COALESCE(applicant_type, 'New')) = 'renewal' THEN 'renewal'
    ELSE COALESCE(NULLIF(application_type, ''), 'new')
END
WHERE application_type IS NULL
   OR application_type = ''
   OR lower(COALESCE(applicant_type, 'New')) = 'renewal';";
$output[] = '';
$output[] = "UPDATE applications
SET student_status = CASE
    WHEN lower(COALESCE(applicant_type, 'New')) = 'renewal' THEN 'Renewal Student'
    ELSE 'Continuing Student'
END
WHERE student_status IS NULL OR student_status = '';";
$output[] = '';
$output[] = "UPDATE applications AS a
SET scholarship_name = s.name
FROM scholarships AS s
WHERE a.scholarship_id = s.id
  AND (a.scholarship_name IS NULL OR a.scholarship_name = '');";
$output[] = '';
$output[] = "UPDATE notifications AS n
SET student_id = s.id
FROM students AS s
WHERE n.student_id IS NULL
  AND n.user_id IS NOT NULL
  AND s.user_id = n.user_id;";
$output[] = '';

foreach ($identityMaximums as $table => $maxId) {
    $output[] = sprintf(
        "SELECT setval(pg_get_serial_sequence('%s', 'id'), %d, true);",
        'public.' . $table,
        $maxId
    );
}

$output[] = 'COMMIT;';
$output[] = '';

$outputDir = dirname($outputPath);
if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Failed to create output directory: {$outputDir}\n");
    exit(1);
}

if (file_put_contents($outputPath, implode(PHP_EOL, $output)) === false) {
    fwrite(STDERR, "Failed to write PostgreSQL output: {$outputPath}\n");
    exit(1);
}

echo 'Converted ' . count($matches) . " INSERT statement(s)." . PHP_EOL;
echo 'Generated PostgreSQL data file: ' . $outputPath . PHP_EOL;
if ($dynamicTables !== []) {
    echo 'Dynamic submission tables included: ' . implode(', ', array_keys($dynamicTables)) . PHP_EOL;
}

function parseMysqlColumnList(string $columnList): array
{
    if (!preg_match_all('/`([^`]+)`/', $columnList, $matches)) {
        return [];
    }

    return $matches[1];
}

function parseMysqlValuesBlock(string $valuesBlock): array
{
    $rows = [];
    $buffer = '';
    $depth = 0;
    $inString = false;
    $escapeNext = false;
    $length = strlen($valuesBlock);

    for ($index = 0; $index < $length; $index++) {
        $char = $valuesBlock[$index];

        if ($inString) {
            $buffer .= $char;

            if ($escapeNext) {
                $escapeNext = false;
                continue;
            }

            if ($char === '\\') {
                $escapeNext = true;
                continue;
            }

            if ($char === "'") {
                if (($index + 1) < $length && $valuesBlock[$index + 1] === "'") {
                    $buffer .= "'";
                    $index++;
                    continue;
                }

                $inString = false;
            }

            continue;
        }

        if ($char === "'") {
            $inString = true;
            if ($depth > 0) {
                $buffer .= $char;
            }
            continue;
        }

        if ($char === '(') {
            if ($depth > 0) {
                $buffer .= $char;
            }
            $depth++;
            continue;
        }

        if ($char === ')') {
            $depth--;
            if ($depth === 0) {
                $rows[] = splitMysqlRowValues($buffer);
                $buffer = '';
                continue;
            }

            $buffer .= $char;
            continue;
        }

        if ($depth > 0) {
            $buffer .= $char;
        }
    }

    return $rows;
}

function splitMysqlRowValues(string $row): array
{
    $values = [];
    $buffer = '';
    $inString = false;
    $escapeNext = false;
    $length = strlen($row);

    for ($index = 0; $index < $length; $index++) {
        $char = $row[$index];

        if ($inString) {
            $buffer .= $char;

            if ($escapeNext) {
                $escapeNext = false;
                continue;
            }

            if ($char === '\\') {
                $escapeNext = true;
                continue;
            }

            if ($char === "'") {
                if (($index + 1) < $length && $row[$index + 1] === "'") {
                    $buffer .= "'";
                    $index++;
                    continue;
                }

                $inString = false;
            }

            continue;
        }

        if ($char === "'") {
            $inString = true;
            $buffer .= $char;
            continue;
        }

        if ($char === ',') {
            $values[] = trim($buffer);
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    if (trim($buffer) !== '' || $row === '') {
        $values[] = trim($buffer);
    }

    return $values;
}

function mysqlValueToPostgresLiteral(string $token): string
{
    $trimmed = trim($token);
    if ($trimmed === '') {
        return 'NULL';
    }

    if (strcasecmp($trimmed, 'NULL') === 0) {
        return 'NULL';
    }

    if (preg_match('/^-?\d+(?:\.\d+)?$/', $trimmed)) {
        return $trimmed;
    }

    if ($trimmed[0] === "'" && substr($trimmed, -1) === "'") {
        $decoded = decodeMysqlString(substr($trimmed, 1, -1));
        return pgQuoteString($decoded);
    }

    return pgQuoteString($trimmed);
}

function mysqlNumericValue(string $token): ?int
{
    $trimmed = trim($token);
    if (!preg_match('/^-?\d+$/', $trimmed)) {
        return null;
    }

    return (int) $trimmed;
}

function decodeMysqlString(string $value): string
{
    $decoded = '';
    $length = strlen($value);

    for ($index = 0; $index < $length; $index++) {
        $char = $value[$index];

        if ($char === '\\' && ($index + 1) < $length) {
            $index++;
            $next = $value[$index];

            switch ($next) {
                case '0':
                    break;
                case 'n':
                    $decoded .= "\n";
                    break;
                case 'r':
                    $decoded .= "\r";
                    break;
                case 't':
                    $decoded .= "\t";
                    break;
                case 'Z':
                    break;
                case '\\':
                    $decoded .= '\\';
                    break;
                case "'":
                    $decoded .= "'";
                    break;
                case '"':
                    $decoded .= '"';
                    break;
                default:
                    $decoded .= $next;
                    break;
            }

            continue;
        }

        $decoded .= $char;
    }

    return str_replace("\0", '', $decoded);
}

function pgQuoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function pgQuoteString(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

function renderDynamicScholarshipTableSql(string $table, array $columns): string
{
    $definitions = [];

    foreach ($columns as $column) {
        if ($column === 'id') {
            $definitions[] = '    ' . pgQuoteIdentifier($column) . ' INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY';
            continue;
        }

        if ($column === 'application_id') {
            $definitions[] = '    ' . pgQuoteIdentifier($column) . ' INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED';
            continue;
        }

        if ($column === 'student_id') {
            $definitions[] = '    ' . pgQuoteIdentifier($column) . ' INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED';
            continue;
        }

        if ($column === 'submitted_at') {
            $definitions[] = '    ' . pgQuoteIdentifier($column) . ' TIMESTAMP DEFAULT CURRENT_TIMESTAMP';
            continue;
        }

        $definitions[] = '    ' . pgQuoteIdentifier($column) . ' TEXT';
    }

    return "CREATE TABLE IF NOT EXISTS " . pgQuoteIdentifier($table) . " (\n"
        . implode(",\n", $definitions)
        . "\n);";
}
