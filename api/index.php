<?php

$rootPath = dirname(__DIR__);
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?? '/';
$requestPath = rawurldecode($requestPath);
$requestPath = preg_replace('#/+#', '/', $requestPath) ?: '/';

if ($requestPath === '/api/index.php') {
    http_response_code(404);
    exit('Not found.');
}

if (strpos($requestPath, '..') !== false || strpos($requestPath, "\0") !== false) {
    http_response_code(400);
    exit('Invalid path.');
}

$targetPath = null;

if ($requestPath === '/' || $requestPath === '') {
    $targetPath = $rootPath . '/public/index.php';
} elseif (str_starts_with($requestPath, '/student/')) {
    $targetPath = $rootPath . $requestPath;
} elseif (str_starts_with($requestPath, '/admin/')) {
    $targetPath = $rootPath . $requestPath;
} elseif (str_starts_with($requestPath, '/public/')) {
    $targetPath = $rootPath . $requestPath;
} elseif (str_starts_with($requestPath, '/api/')) {
    $targetPath = $rootPath . $requestPath;
} elseif (preg_match('/\.php$/i', $requestPath)) {
    $targetPath = $rootPath . '/public' . $requestPath;
}

if ($targetPath === null || !is_file($targetPath)) {
    http_response_code(404);
    exit('Page not found.');
}

$realRoot = realpath($rootPath);
$realTarget = realpath($targetPath);

if ($realRoot === false || $realTarget === false || strpos($realTarget, $realRoot) !== 0) {
    http_response_code(403);
    exit('Forbidden.');
}

$targetExtension = strtolower(pathinfo($realTarget, PATHINFO_EXTENSION));
if ($targetExtension !== 'php') {
    $mimeTypes = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'txt' => 'text/plain; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'pdf' => 'application/pdf',
    ];

    header('Content-Type: ' . ($mimeTypes[$targetExtension] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=3600');
    readfile($realTarget);
    exit();
}

$originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
$originalScriptName = $_SERVER['SCRIPT_NAME'] ?? null;
$originalScriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
$originalCwd = getcwd();

$visiblePath = $requestPath === '/' ? '/index.php' : $requestPath;
$_SERVER['PHP_SELF'] = $visiblePath;
$_SERVER['SCRIPT_NAME'] = $visiblePath;
$_SERVER['SCRIPT_FILENAME'] = $realTarget;
$_SERVER['DOCUMENT_ROOT'] = $realRoot;

chdir(dirname($realTarget));
require basename($realTarget);

if ($originalCwd !== false) {
    chdir($originalCwd);
}

if ($originalPhpSelf !== null) {
    $_SERVER['PHP_SELF'] = $originalPhpSelf;
}
if ($originalScriptName !== null) {
    $_SERVER['SCRIPT_NAME'] = $originalScriptName;
}
if ($originalScriptFilename !== null) {
    $_SERVER['SCRIPT_FILENAME'] = $originalScriptFilename;
}
