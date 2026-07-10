<?php
declare(strict_types=1);

function registry_create_database(array $payload): array
{
    $db_name = $payload['name'] ?? '';
    $meta = $payload['meta'] ?? [
        'schema_version' => 1,
        'validation' => ['enabled' => true],
        'tables' => [],
    ];

    if (!is_string($db_name) || $db_name === '') {
        respond_json(400, [], 'Database name is required');
    }

    validate_segment($db_name, 'database');

    if (!is_array($meta)) {
        respond_json(400, [], 'meta must be an object');
    }

    $database = resolve_database($db_name);
    if (is_dir($database['dir'])) {
        respond_json(409, [], 'Database directory already exists');
    }

    registry_ensure_directory($database['dir'], 'database');
    registry_ensure_directory($database['schema_dir'], 'schema');

    $pdo = new PDO('sqlite:' . $database['file']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo = null;

    $meta['database'] = $db_name;
    $meta['schema_version'] = $meta['schema_version'] ?? 1;
    $meta['validation'] = is_array($meta['validation'] ?? null) ? $meta['validation'] : ['enabled' => true];
    $meta['tables'] = is_array($meta['tables'] ?? null) ? $meta['tables'] : [];
    registry_write_database_metadata($database, $meta);

    return [
        'database' => $db_name,
        'directory' => $database['dir'],
        'schema_dir' => $database['schema_dir'],
        'sqlite' => $database['file'],
        'meta' => registry_metadata_path($database),
    ];
}

function registry_create_table(array $payload): array
{
    $database = registry_database_from_payload($payload);

    if ($database['exists'] !== true) {
        respond_json(404, ['database' => $database['name']], 'Database file not found');
    }

    $table = $payload['table'] ?? $payload['name'] ?? '';
    if (!is_string($table)) {
        respond_json(400, [], 'Table name must be a string');
    }
    validate_segment($table, 'table');

    $columns = registry_normalize_columns($payload['columns'] ?? null);
    $if_not_exists = (bool) ($payload['if_not_exists'] ?? false);
    $sql = registry_build_create_table_sql($table, $columns, $if_not_exists);

    $pdo = open_database_connection($database['file']);
    try {
        $pdo->exec($sql);
    } catch (Throwable $exception) {
        respond_json(500, [], 'Failed to create table', ['detail' => $exception->getMessage()]);
    }

    $meta = registry_read_database_metadata($database);
    $meta['tables'][$table] = [
        'columns' => registry_columns_for_metadata($columns),
    ];
    registry_write_database_metadata($database, $meta);

    return [
        'database' => $database['name'],
        'table' => $table,
        'sql' => $sql,
        'meta' => registry_metadata_path($database),
    ];
}

function registry_delete_database(array $payload): array
{
    $database = registry_database_from_payload($payload);

    if (!is_dir($database['dir'])) {
        respond_json(404, ['database' => $database['name']], 'Database directory not found');
    }

    registry_delete_tree($database['dir']);

    return [
        'database' => $database['name'],
        'directory' => $database['dir'],
    ];
}

function registry_create_endpoint(array $payload): array
{
    $database = registry_database_from_payload($payload);

    if ($database['exists'] !== true) {
        respond_json(404, ['database' => $database['name']], 'Database file not found');
    }

    registry_ensure_directory($database['schema_dir'], 'schema');

    $endpoint = registry_build_endpoint_source($database, $payload);
    $endpoint_file = $database['schema_dir'] . '/' . $endpoint['endpoint'] . '.php';

    if (is_file($endpoint_file) && ($payload['overwrite'] ?? false) !== true) {
        respond_json(409, [], 'Endpoint already exists', ['endpoint' => $endpoint['endpoint']]);
    }

    if (file_put_contents($endpoint_file, $endpoint['source']) === false) {
        respond_json(500, [], 'Failed to write endpoint file');
    }

    return [
        'database' => $database['name'],
        'endpoint' => $endpoint['endpoint'],
        'file' => $endpoint_file,
    ];
}

function registry_drop_table(array $payload): array
{
    $database = registry_database_from_payload($payload);

    if ($database['exists'] !== true) {
        respond_json(404, ['database' => $database['name']], 'Database file not found');
    }

    $table = $payload['table'] ?? $payload['name'] ?? '';
    if (!is_string($table)) {
        respond_json(400, [], 'Table name must be a string');
    }
    validate_segment($table, 'table');

    $if_exists = (bool) ($payload['if_exists'] ?? false);
    $sql = sprintf(
        'DROP TABLE%s %s',
        $if_exists ? ' IF EXISTS' : '',
        quote_sqlite_identifier($table)
    );

    $pdo = open_database_connection($database['file']);
    try {
        $pdo->exec($sql);
    } catch (Throwable $exception) {
        respond_json(500, [], 'Failed to drop table', ['detail' => $exception->getMessage()]);
    }

    $meta = registry_read_database_metadata($database);
    unset($meta['tables'][$table]);
    registry_write_database_metadata($database, $meta);

    return [
        'database' => $database['name'],
        'table' => $table,
        'sql' => $sql,
        'meta' => registry_metadata_path($database),
    ];
}

function registry_database_from_payload(array $payload): array
{
    $db_name = $payload['database'] ?? $payload['db'] ?? '';
    if (!is_string($db_name) || $db_name === '') {
        respond_json(400, [], 'Database name is required');
    }

    validate_segment($db_name, 'database');

    return resolve_database($db_name);
}

function registry_read_database_metadata(array $database): array
{
    $metadata = load_database_metadata($database);

    if (!is_array($metadata['raw'] ?? null)) {
        return [
            'database' => $database['name'],
            'schema_version' => 1,
            'validation' => ['enabled' => true],
            'tables' => [],
        ];
    }

    $raw = $metadata['raw'];
    $raw['database'] = $database['name'];
    $raw['schema_version'] = $raw['schema_version'] ?? 1;
    $raw['validation'] = is_array($raw['validation'] ?? null) ? $raw['validation'] : ['enabled' => true];
    $raw['tables'] = is_array($raw['tables'] ?? null) ? $raw['tables'] : [];

    return $raw;
}

function registry_write_database_metadata(array $database, array $meta): void
{
    $meta_path = registry_metadata_path($database);
    $meta_json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($meta_json === false || file_put_contents($meta_path, $meta_json . "\n") === false) {
        respond_json(500, [], 'Failed to write metadata file');
    }
}

function registry_metadata_path(array $database): string
{
    return $database['dir'] . '/' . $database['name'] . '.meta.json';
}

function registry_ensure_directory(string $directory, string $label): void
{
    if (is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
        respond_json(500, [], sprintf('Failed to create %s directory', $label));
    }
}

function registry_delete_tree(string $path): void
{
    if (!is_dir($path)) {
        if (is_file($path) && !unlink($path)) {
            respond_json(500, [], 'Failed to delete file', ['path' => $path]);
        }
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        respond_json(500, [], 'Failed to read directory', ['path' => $path]);
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        registry_delete_tree($path . '/' . $item);
    }

    if (!rmdir($path)) {
        respond_json(500, [], 'Failed to delete directory', ['path' => $path]);
    }
}

function registry_columns_from_simple_schema(array $names, array $types): array
{
    $columns = [
        [
            'name' => 'id',
            'type' => 'INTEGER',
            'primary_key' => true,
            'auto_increment' => true,
        ],
    ];

    foreach ($names as $index => $name) {
        if (!is_string($name) || trim($name) === '') {
            continue;
        }

        $column_name = trim($name);
        validate_segment($column_name, 'column');
        if ($column_name === 'id') {
            continue;
        }

        $simple_type = is_string($types[$index] ?? null) ? $types[$index] : 'text';
        $columns[] = [
            'name' => $column_name,
            'type' => registry_simple_type_to_sqlite($simple_type),
            'not_null' => false,
            'ui_type' => registry_normalize_simple_type($simple_type),
        ];
    }

    if (count($columns) === 1) {
        respond_json(400, [], 'At least one column is required');
    }

    return $columns;
}

function registry_simple_type_to_sqlite(string $type): string
{
    return match (registry_normalize_simple_type($type)) {
        'number' => 'REAL',
        'bool' => 'INTEGER',
        default => 'TEXT',
    };
}

function registry_normalize_simple_type(string $type): string
{
    return match ($type) {
        'number', 'bool' => $type,
        default => 'text',
    };
}
