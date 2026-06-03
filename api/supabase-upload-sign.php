<?php
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isStudent()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'You must be logged in as a student to upload documents.']);
    exit;
}

if (!appUsesSupabaseUploads() || !supabaseStorageConfigured()) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Supabase Storage uploads are not configured.']);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody ?: '{}', true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid upload request.']);
    exit;
}

$files = $payload['files'] ?? [];
if (!is_array($files) || empty($files)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No files were provided for upload signing.']);
    exit;
}

if (count($files) > 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'You can upload a maximum of 6 PDFs for one application submission.']);
    exit;
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$dateFolder = date('Y/m/d');
$signedFiles = [];

foreach ($files as $index => $file) {
    if (!is_array($file)) {
        continue;
    }

    $originalName = trim((string) ($file['name'] ?? 'document.pdf'));
    $mimeType = trim((string) ($file['type'] ?? 'application/pdf'));
    $size = (int) ($file['size'] ?? 0);

    if ($originalName === '' || $size <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'One of the selected files is invalid.']);
        exit;
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension !== 'pdf' || !in_array($mimeType, ['application/pdf', ''], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Only PDF files can be uploaded."]);
        exit;
    }

    if ($size > appUploadMaxBytes()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "File '{$originalName}' exceeds the " . appUploadMaxLabel() . ' upload limit.']);
        exit;
    }

    $safeFilename = storageSanitizeFilename($originalName);
    $randomPart = bin2hex(random_bytes(12));
    $storagePath = supabaseStorageNormalizePath("applications/user_{$user_id}/{$dateFolder}/{$randomPart}_{$safeFilename}");
    $endpoint = 'object/upload/sign/' . rawurlencode((string) SUPABASE_STORAGE_BUCKET) . '/' . str_replace('%2F', '/', rawurlencode($storagePath));
    $result = supabaseStorageRequest(
        'POST',
        $endpoint,
        '{}',
        [
            'Content-Type: application/json',
        ]
    );

    if (!$result['success']) {
        error_log('Supabase signed upload URL error: ' . json_encode($result));
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Unable to prepare Supabase upload. Please try again.']);
        exit;
    }

    $data = json_decode($result['body'], true);
    $signedPath = is_array($data) ? ($data['signedURL'] ?? $data['signedUrl'] ?? $data['url'] ?? '') : '';
    $token = '';
    if (is_string($signedPath) && $signedPath !== '') {
        $parts = parse_url($signedPath);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            $token = (string) ($query['token'] ?? '');
        }
    }

    if ($token === '') {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Supabase did not return an upload token.']);
        exit;
    }

    $signedUrl = isAbsoluteUrl($signedPath)
        ? $signedPath
        : supabaseStorageBaseUrl() . '/' . ltrim((string) $signedPath, '/');

    $signedFiles[] = [
        'input_name' => (string) ($file['input_name'] ?? ''),
        'index' => (int) ($file['index'] ?? $index),
        'name' => $safeFilename,
        'original_name' => $originalName,
        'size' => $size,
        'type' => 'application/pdf',
        'storage_path' => $storagePath,
        'document_path' => 'supabase:' . $storagePath,
        'signed_url' => $signedUrl,
        'token' => $token,
    ];
}

echo json_encode([
    'success' => true,
    'bucket' => SUPABASE_STORAGE_BUCKET,
    'files' => $signedFiles,
]);
