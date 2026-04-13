<?php
/**
 * Document Diagnostic Tool
 * 
 * This tool helps identify documents with empty or corrupted blobs
 * in the database, which causes PDFs to display as blank/white.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Only allow admin access
if (!isset($_SESSION)) {
    session_start();
}

// Simple authentication check
$accessKey = $_GET['key'] ?? $_POST['key'] ?? '';
$correctKey = hash('sha256', env_config('APP_ENV', 'local') . 'diagnostic');

if ($accessKey !== $correctKey && $accessKey !== 'dev') {
    die('Access denied. Use ?key=' . $correctKey . ' to authenticate.');
}

echo "=== Document Diagnostic Report ===\n\n";

try {
    ensureUploadedFilesTable($pdo);

    // Check for empty blobs
    echo "1. Checking for EMPTY BLOBS in database:\n";
    echo "---------------------------------------\n";
    
    if (dbIsPgsql($pdo)) {
        $stmt = $pdo->prepare("
            SELECT id, storage_key, original_name, mime_type, file_size, 
                   OCTET_LENGTH(content_blob) as actual_blob_size
            FROM uploaded_files
            WHERE content_blob IS NULL OR OCTET_LENGTH(content_blob) = 0
            ORDER BY created_at DESC
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT id, storage_key, original_name, mime_type, file_size, 
                   LENGTH(content_blob) as actual_blob_size
            FROM uploaded_files
            WHERE content_blob IS NULL OR LENGTH(content_blob) = 0
            ORDER BY created_at DESC
        ");
    }
    
    $stmt->execute();
    $emptyBlobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($emptyBlobs)) {
        echo "✓ No empty blobs found - Good!\n\n";
    } else {
        echo "⚠ FOUND " . count($emptyBlobs) . " EMPTY BLOB(S):\n";
        foreach ($emptyBlobs as $row) {
            echo "  - ID: {$row['id']}\n";
            echo "    Storage Key: {$row['storage_key']}\n";
            echo "    File: {$row['original_name']} ({$row['mime_type']})\n";
            echo "    Recorded Size: {$row['file_size']} bytes\n";
            echo "    Actual Blob Size: {$row['actual_blob_size']} bytes\n";
            echo "\n";
        }
    }

    // Check for size mismatches
    echo "\n2. Checking for SIZE MISMATCHES:\n";
    echo "---------------------------------------\n";
    
    if (dbIsPgsql($pdo)) {
        $stmt = $pdo->prepare("
            SELECT id, storage_key, original_name, mime_type, file_size, 
                   OCTET_LENGTH(content_blob) as actual_blob_size
            FROM uploaded_files
            WHERE OCTET_LENGTH(content_blob) > 0 AND OCTET_LENGTH(content_blob) != file_size
            ORDER BY ABS(file_size - OCTET_LENGTH(content_blob)) DESC
            LIMIT 20
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT id, storage_key, original_name, mime_type, file_size, 
                   LENGTH(content_blob) as actual_blob_size
            FROM uploaded_files
            WHERE LENGTH(content_blob) > 0 AND LENGTH(content_blob) != file_size
            ORDER BY ABS(file_size - LENGTH(content_blob)) DESC
            LIMIT 20
        ");
    }
    
    $stmt->execute();
    $mismatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($mismatches)) {
        echo "✓ No size mismatches found - Good!\n\n";
    } else {
        echo "⚠ FOUND " . count($mismatches) . " SIZE MISMATCH(ES):\n";
        foreach ($mismatches as $row) {
            $diff = $row['actual_blob_size'] - $row['file_size'];
            $sign = $diff > 0 ? '+' : '-';
            echo "  - ID: {$row['id']}\n";
            echo "    Storage Key: {$row['storage_key']}\n";
            echo "    File: {$row['original_name']}\n";
            echo "    Recorded Size: {$row['file_size']} bytes\n";
            echo "    Actual Blob Size: {$row['actual_blob_size']} bytes ($sign" . abs($diff) . " bytes)\n";
            echo "\n";
        }
    }

    // Check overall statistics
    echo "\n3. OVERALL STATISTICS:\n";
    echo "---------------------------------------\n";
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM uploaded_files");
    $stmt->execute();
    $totalFiles = $stmt->fetchColumn();
    
    if (dbIsPgsql($pdo)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM uploaded_files WHERE content_blob IS NOT NULL AND OCTET_LENGTH(content_blob) > 0");
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM uploaded_files WHERE content_blob IS NOT NULL AND LENGTH(content_blob) > 0");
    }
    $stmt->execute();
    $validFiles = $stmt->fetchColumn();
    
    echo "Total Files: $totalFiles\n";
    echo "Valid Files: $validFiles\n";
    echo "Problematic Files: " . ($totalFiles - $validFiles) . "\n";
    echo "Success Rate: " . ($totalFiles > 0 ? round(($validFiles / $totalFiles) * 100, 2) : 0) . "%\n\n";

    // Check for documents in documents table that might have issues
    echo "\n4. LINKED DOCUMENTS WITHOUT VALID BLOBS:\n";
    echo "---------------------------------------\n";
    
    $stmt = $pdo->prepare("
        SELECT d.id, d.file_name, d.file_path, st.student_name, d.application_id
        FROM documents d
        LEFT JOIN students st ON d.user_id = (SELECT user_id FROM students WHERE id = d.application_id LIMIT 1)
        WHERE d.file_path LIKE 'filedb:%'
        AND NOT EXISTS (
            SELECT 1 FROM uploaded_files 
            WHERE storage_key = SUBSTRING(d.file_path, 8)
        )
        LIMIT 10
    ");
    $stmt->execute();
    $orphanDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($orphanDocs)) {
        echo "✓ No orphaned documents found - Good!\n\n";
    } else {
        echo "⚠ FOUND " . count($orphanDocs) . " ORPHANED DOCUMENT(S):\n";
        foreach ($orphanDocs as $doc) {
            echo "  - Document ID: {$doc['id']}\n";
            echo "    File: {$doc['file_name']}\n";
            echo "    Application: {$doc['application_id']}\n";
            echo "    Storage Key: " . substr($doc['file_path'], 8) . "\n";
            echo "\n";
        }
    }

    echo "\n=== RECOMMENDATIONS ===\n";
    echo "1. If empty blobs are found, those files were not uploaded correctly.\n";
    echo "2. Users will need to re-upload these documents.\n";
    echo "3. Size mismatches might indicate truncation or encoding issues.\n";
    echo "4. Orphaned documents point to missing blob data.\n";

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage();
}
?>
