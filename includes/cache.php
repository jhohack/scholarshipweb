<?php

if (!function_exists('portalCacheMissMarker')) {
    function portalCacheMissMarker()
    {
        static $marker = null;

        if ($marker === null) {
            $marker = new stdClass();
        }

        return $marker;
    }
}

if (!function_exists('portalCacheDir')) {
    function portalCacheDir(): string
    {
        static $dir = null;

        if ($dir !== null) {
            return $dir;
        }

        $candidates = [
            rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'scholarshipweb-cache',
            dirname(__DIR__) . DIRECTORY_SEPARATOR . '.cache',
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate) || @mkdir($candidate, 0777, true)) {
                $dir = rtrim($candidate, '/\\');
                return $dir;
            }
        }

        $dir = rtrim(sys_get_temp_dir(), '/\\');
        return $dir;
    }
}

if (!function_exists('portalCachePath')) {
    function portalCachePath(string $key): string
    {
        return portalCacheDir() . DIRECTORY_SEPARATOR . sha1($key) . '.cache';
    }
}

if (!function_exists('portalCacheGet')) {
    function portalCacheGet(string $key, int $ttlSeconds = 60)
    {
        $path = portalCachePath($key);

        if (!is_file($path)) {
            return portalCacheMissMarker();
        }

        $payload = @file_get_contents($path);
        if ($payload === false || $payload === '') {
            return portalCacheMissMarker();
        }

        $data = @unserialize($payload, ['allowed_classes' => false]);
        if (!is_array($data) || !array_key_exists('expires_at', $data) || !array_key_exists('value', $data)) {
            return portalCacheMissMarker();
        }

        if ((int) $data['expires_at'] < time()) {
            @unlink($path);
            return portalCacheMissMarker();
        }

        return $data['value'];
    }
}

if (!function_exists('portalCacheSet')) {
    function portalCacheSet(string $key, $value, int $ttlSeconds = 60): void
    {
        $dir = portalCacheDir();
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            return;
        }

        $path = portalCachePath($key);
        $payload = serialize([
            'expires_at' => time() + max(1, $ttlSeconds),
            'value' => $value,
        ]);

        $tmpPath = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmpPath, $payload, LOCK_EX) === false) {
            return;
        }

        @rename($tmpPath, $path);
    }
}

if (!function_exists('portalCacheRemember')) {
    function portalCacheRemember(string $key, int $ttlSeconds, callable $callback)
    {
        $cached = portalCacheGet($key, $ttlSeconds);
        if ($cached !== portalCacheMissMarker()) {
            return $cached;
        }

        $value = $callback();
        portalCacheSet($key, $value, $ttlSeconds);

        return $value;
    }
}
