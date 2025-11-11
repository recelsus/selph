<?php
declare(strict_types=1);

function registry_allowed(SecurityGate $security, string $ip): bool
{
    $decision = $security->evaluateIp($ip);

    return $decision === 'allow' || is_loopback_ip($ip);
}

function registry_create_database(array $payload): array
{
    $db_name = $payload['name'] ?? '';
    $meta = $payload['meta'] ?? null;

    if (!is_string($db_name) || $db_name === '') {
        respond_json(400, [], 'Database name is required');
    }

    validate_segment($db_name, 'database');

    if (!is_array($meta)) {
        respond_json(400, [], 'meta must be an object');
    }

    $db_dir = DB_ROOT . '/' . $db_name;
    if (is_dir($db_dir)) {
        respond_json(409, [], 'Database directory already exists');
    }

    if (!mkdir($db_dir, 0775, true) && !is_dir($db_dir)) {
        respond_json(500, [], 'Failed to create database directory');
    }

    $schema_dir = $db_dir . '/schema';
    if (!mkdir($schema_dir, 0775, true) && !is_dir($schema_dir)) {
        respond_json(500, [], 'Failed to create schema directory');
    }

    $sqlite_path = $db_dir . '/' . $db_name . '.sqlite';
    $pdo = new PDO('sqlite:' . $sqlite_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo = null;

    $meta['database'] = $db_name;
    $meta_path = $db_dir . '/' . $db_name . '.meta.json';
    $meta_json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($meta_json === false || file_put_contents($meta_path, $meta_json) === false) {
        respond_json(500, [], 'Failed to write metadata file');
    }

    return [
        'directory' => $db_dir,
        'schema_dir' => $schema_dir,
        'sqlite' => $sqlite_path,
        'meta' => $meta_path,
    ];
}
