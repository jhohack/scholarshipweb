<?php
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';

$storageKey = trim($_GET['key'] ?? '');

if ($storageKey === '') {
    http_response_code(404);
    exit('File not found.');
}

try {
    ensureUploadedFilesTable($pdo);

    $stmt = $pdo->prepare("
        SELECT original_name, mime_type, file_size, content_blob
        FROM uploaded_files
        WHERE storage_key = ?
        LIMIT 1
    ");
    $stmt->execute([$storageKey]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        http_response_code(404);
        exit('File not found.');
    }

    $originalName = $file['original_name'] ?? 'download';
    $mimeType = $file['mime_type'] ?? 'application/octet-stream';
    $fileSize = (int) ($file['file_size'] ?? 0);
    $isInline = preg_match('#^(image/|application/pdf$)#i', $mimeType) === 1;

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Disposition: ' . ($isInline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($originalName) . '"');
    echo $file['content_blob'];
    exit();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Unable to load file.');
}

