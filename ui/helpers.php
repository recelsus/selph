<?php
declare(strict_types=1);

function respond_ui_forbidden(string $ip): void
{
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(403);
    }

    echo 'UI access denied for IP: ' . $ip;
    exit;
}

function respond_ui_error(string $message): void
{
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(400);
    }

    echo 'UI action failed: ' . $message;
    exit;
}

function list_database_endpoints(): array
{
    $root = DB_ROOT;
    if (!is_dir($root)) {
        return [];
    }

    $entries = [];
    $items = scandir($root) ?: [];

    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === 'security') {
            continue;
        }

        $dir = $root . '/' . $item;
        if (!is_dir($dir)) {
            continue;
        }

        $schema_dir = $dir . '/schema';
        $endpoints = [];
        if (is_dir($schema_dir)) {
            $files = glob($schema_dir . '/*.php') ?: [];
            foreach ($files as $file) {
                $endpoints[] = basename($file, '.php');
            }
            sort($endpoints, SORT_NATURAL);
        }

        $entries[] = [
            'name' => $item,
            'exists' => is_file($dir . '/' . $item . '.sqlite'),
            'endpoints' => $endpoints,
        ];
    }

    usort($entries, static fn ($a, $b) => strcmp($a['name'], $b['name']));

    return $entries;
}

function ui_base_url(): ?string
{
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? null;
    if ($host === null) {
        return null;
    }

    $is_https = false;
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        $is_https = true;
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $is_https = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
    }

    $scheme = $is_https ? 'https' : 'http';
    $script_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $base_path = $script_dir === '' ? '' : $script_dir;

    return rtrim($scheme . '://' . $host . ($base_path !== '' ? $base_path : ''), '/');
}

function ui_asset_path(string $relative): string
{
    $relative = ltrim($relative, '/');
    $script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/') ?: '/';
    $base = rtrim($script_dir, '/');

    return ($base === '' ? '' : $base) . '/' . $relative;
}
