<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

putenv('UPLOAD_DRIVER=database');
$_ENV['UPLOAD_DRIVER'] = 'database';
$_SERVER['UPLOAD_DRIVER'] = 'database';

$basePath = dirname(__DIR__);
require_once $basePath . '/includes/config.php';
require_once $basePath . '/includes/db.php';
require_once $basePath . '/includes/functions.php';

$dryRun = in_array('--dry-run', $argv, true);

$targets = [
    [
        'label' => 'User profile pictures',
        'table' => 'users',
        'id_column' => 'id',
        'path_column' => 'profile_picture_path',
        'name_column' => null,
        'folder' => 'avatars',
    ],
    [
        'label' => 'Application documents',
        'table' => 'documents',
        'id_column' => 'id',
        'path_column' => 'file_path',
        'name_column' => 'file_name',
        'folder' => 'documents',
    ],
    [
        'label' => 'Announcement attachments',
        'table' => 'announcement_attachments',
        'id_column' => 'id',
        'path_column' => 'file_path',
        'name_column' => 'file_name',
        'folder' => 'announcements',
    ],
    [
        'label' => 'Message attachments',
        'table' => 'messages',
        'id_column' => 'id',
        'path_column' => 'attachment_path',
        'name_column' => null,
        'folder' => 'messages',
    ],
];

echo $dryRun
    ? "Dry run: legacy uploads will be checked but not updated.\n\n"
    : "Migrating legacy uploads into database storage.\n\n";

foreach ($targets as $target) {
    $columns = [$target['id_column'], $target['path_column']];
    if ($target['name_column']) {
        $columns[] = $target['name_column'];
    }

    $sql = sprintf(
        "SELECT %s FROM %s WHERE %s LIKE 'uploads/%%' ORDER BY %s ASC",
        implode(', ', $columns),
        $target['table'],
        $target['path_column'],
        $target['id_column']
    );

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $converted = 0;
    $missing = 0;
    $skipped = 0;

    echo '[' . $target['label'] . "]\n";

    foreach ($rows as $row) {
        $relativePath = $row[$target['path_column']] ?? '';
        if ($relativePath === '') {
            $skipped++;
            continue;
        }

        $absolutePath = $basePath . '/public/' . ltrim($relativePath, '/');
        $originalName = $target['name_column']
            ? ($row[$target['name_column']] ?? basename($relativePath))
            : basename($relativePath);

        if (!is_file($absolutePath)) {
            $missing++;
            echo '  - Missing file for ' . $target['table'] . '#' . $row[$target['id_column']] . ': ' . $relativePath . "\n";
            continue;
        }

        if ($dryRun) {
            $converted++;
            echo '  - Would migrate ' . $target['table'] . '#' . $row[$target['id_column']] . ': ' . $relativePath . "\n";
            continue;
        }

        $result = storeExistingFileFromDisk($pdo, $absolutePath, $originalName, $target['folder'], $basePath);
        if (!$result['success']) {
            $missing++;
            echo '  - Failed ' . $target['table'] . '#' . $row[$target['id_column']] . ': ' . ($result['error'] ?? 'Unknown error') . "\n";
            continue;
        }

        $update = sprintf(
            "UPDATE %s SET %s = ? WHERE %s = ?",
            $target['table'],
            $target['path_column'],
            $target['id_column']
        );
        $stmt = $pdo->prepare($update);
        $stmt->execute([$result['path'], $row[$target['id_column']]]);
        $converted++;

        echo '  - Migrated ' . $target['table'] . '#' . $row[$target['id_column']] . ' => ' . $result['path'] . "\n";
    }

    echo '  Summary: ' . $converted . ' converted, ' . $missing . ' missing/failed, ' . $skipped . " skipped.\n\n";
}

echo "Done.\n";
