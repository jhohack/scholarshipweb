<?php
// Ensure no output buffering interferes with binary file delivery
if (ob_get_level() > 0) {
    ob_end_clean();
}

// Disable output buffering for binary content
ini_set('output_buffering', 'off');

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

    if (dbIsPgsql($pdo)) {
        $stmt = $pdo->prepare("
            SELECT original_name, mime_type, file_size, encode(content_blob, 'base64') AS content_blob_base64
            FROM uploaded_files
            WHERE storage_key = ?
            LIMIT 1
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT original_name, mime_type, file_size, content_blob
            FROM uploaded_files
            WHERE storage_key = ?
            LIMIT 1
        ");
    }
    $stmt->execute([$storageKey]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        http_response_code(404);
        exit('File not found.');
    }

    $originalName = $file['original_name'] ?? 'download';
    $mimeType = $file['mime_type'] ?? 'application/octet-stream';
    $fileSize = (int) ($file['file_size'] ?? 0);
    if (dbIsPgsql($pdo)) {
        $blobContent = base64_decode((string) ($file['content_blob_base64'] ?? ''), true);
        if ($blobContent === false) {
            throw new RuntimeException('Failed to decode stored document bytes.');
        }
    } else {
        $blobContent = $file['content_blob'] ?? '';
        if (is_resource($blobContent)) {
            $blobContent = stream_get_contents($blobContent);
        }
    }

    if (!is_string($blobContent)) {
        throw new RuntimeException('Unexpected document blob type: ' . gettype($blobContent));
    }

    // Validate blob is not empty
    if (empty($blobContent)) {
        error_log("WARNING: Document blob is empty for storage key: {$storageKey}");
        http_response_code(500);
        exit('File data is corrupted or empty.');
    }

    // Validate blob size matches recorded size
    $actualSize = strlen($blobContent);
    if ($actualSize === 0) {
        error_log("ERROR: Document blob has zero length for storage key: {$storageKey}, recorded size: {$fileSize}");
        http_response_code(500);
        exit('File data is empty or corrupted.');
    }

    if ($actualSize !== $fileSize) {
        error_log("WARNING: Size mismatch for storage key: {$storageKey}. Recorded: {$fileSize}, Actual: {$actualSize}");
    }

    $isInline = preg_match('#^(image/|application/pdf$)#i', $mimeType) === 1;

    // Set proper headers for binary content
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . $actualSize);
    header('Cache-Control: public, max-age=31536000, immutable');
    header(
        'Content-Disposition: '
        . ($isInline ? 'inline' : 'attachment')
        . '; filename="' . addcslashes($originalName, "\"\\") . '"'
        . "; filename*=UTF-8''" . rawurlencode($originalName)
    );
    header('Accept-Ranges: bytes');
    
    // Flush output before sending binary data
    if (function_exists('flush')) {
        flush();
    }

    // Output binary content safely
    echo $blobContent;
    exit();
} catch (Throwable $e) {
    error_log('File retrieval error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load file.');
}
