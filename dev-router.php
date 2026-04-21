<?php

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$uriPath = rawurldecode(parse_url($requestUri, PHP_URL_PATH) ?: '/');
$uriPath = '/' . ltrim($uriPath, '/');

$rootDir = __DIR__;
$publicDir = $rootDir . DIRECTORY_SEPARATOR . 'public';
$rootTarget = $rootDir . str_replace('/', DIRECTORY_SEPARATOR, $uriPath);
$publicTarget = $publicDir . str_replace('/', DIRECTORY_SEPARATOR, $uriPath);

$mimeTypes = [
    'css' => 'text/css; charset=UTF-8',
    'js' => 'application/javascript; charset=UTF-8',
    'json' => 'application/json; charset=UTF-8',
    'svg' => 'image/svg+xml',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'ico' => 'image/x-icon',
    'mp3' => 'audio/mpeg',
    'wav' => 'audio/wav',
    'mp4' => 'video/mp4',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf' => 'font/ttf',
    'map' => 'application/json; charset=UTF-8',
];

$servePhp = static function (string $filePath, string $scriptName): void {
    $_SERVER['SCRIPT_FILENAME'] = $filePath;
    $_SERVER['SCRIPT_NAME'] = $scriptName;
    $_SERVER['PHP_SELF'] = $scriptName;
    require $filePath;
};

$serveStatic = static function (string $filePath) use ($mimeTypes): void {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mime = $mimeTypes[$ext] ?? (function_exists('mime_content_type') ? (mime_content_type($filePath) ?: 'application/octet-stream') : 'application/octet-stream');

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($filePath));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    readfile($filePath);
};

if ($uriPath === '/' || $uriPath === '') {
    $servePhp($publicDir . DIRECTORY_SEPARATOR . 'index.php', '/index.php');
    return true;
}

if (is_file($rootTarget)) {
    $ext = strtolower(pathinfo($rootTarget, PATHINFO_EXTENSION));
    if ($ext === 'php') {
        return false;
    }

    $serveStatic($rootTarget);
    return true;
}

if (is_file($publicTarget)) {
    $ext = strtolower(pathinfo($publicTarget, PATHINFO_EXTENSION));
    if ($ext === 'php') {
        $servePhp($publicTarget, $uriPath);
    } else {
        $serveStatic($publicTarget);
    }
    return true;
}

if (is_dir($rootTarget) && is_file($rootTarget . DIRECTORY_SEPARATOR . 'index.php')) {
    return false;
}

if (is_dir($publicTarget) && is_file($publicTarget . DIRECTORY_SEPARATOR . 'index.php')) {
    $servePhp($publicTarget . DIRECTORY_SEPARATOR . 'index.php', $uriPath . '/index.php');
    return true;
}

return false;
