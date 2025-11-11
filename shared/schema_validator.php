<?php
declare(strict_types=1);

function load_database_metadata(array $database): array
{
    $meta_file = $database['dir'] . '/' . $database['name'] . '.meta.json';

    if (!is_file($meta_file)) {
        return disabled_metadata($meta_file);
    }

    $contents = @file_get_contents($meta_file);
    if ($contents === false) {
        return disabled_metadata($meta_file);
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return disabled_metadata($meta_file);
    }

    $tables = is_array($decoded['tables'] ?? null) ? $decoded['tables'] : [];
    $enabled = (bool) ($decoded['validation']['enabled'] ?? true);

    return [
        'enabled' => $enabled,
        'file' => $meta_file,
        'tables' => $tables,
        'raw' => $decoded,
    ];
}

function disabled_metadata(string $file): array
{
    return [
        'enabled' => false,
        'file' => $file,
        'tables' => [],
        'raw' => null,
    ];
}

function validate_tables(PDO $pdo, array $tables, array $metadata): void
{
    if ($tables === [] || ($metadata['enabled'] ?? false) !== true) {
        return;
    }

    $definitions = $metadata['tables'] ?? [];

    foreach ($tables as $table) {
        if (!isset($definitions[$table])) {
            continue;
        }

        validate_table_schema($pdo, $table, $definitions[$table]);
    }
}

function validate_table_schema(PDO $pdo, string $table, array $definition): void
{
    $expected_columns = $definition['columns'] ?? [];
    if ($expected_columns === []) {
        respond_json(500, [], 'Table definition missing columns', ['table' => $table]);
    }

    $query = sprintf('PRAGMA table_info(%s)', quote_sqlite_identifier($table));
    $statement = $pdo->query($query);
    if ($statement === false) {
        respond_json(500, [], 'Failed to inspect table schema', ['table' => $table]);
    }

    $actual_columns = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $actual_columns[$column['name']] = $column;
    }

    foreach ($expected_columns as $expected) {
        $name = $expected['name'] ?? null;
        $type = strtoupper((string) ($expected['type'] ?? ''));

        if ($name === null || $type === '') {
            respond_json(500, [], 'Invalid column definition', ['table' => $table]);
        }

        if (!isset($actual_columns[$name])) {
            respond_json(500, [], 'Missing column in table', ['table' => $table, 'column' => $name]);
        }

        $actual = $actual_columns[$name];
        $actual_type = strtoupper((string) ($actual['type'] ?? ''));
        if ($actual_type !== $type) {
            respond_json(500, [], 'Column type mismatch', [
                'table' => $table,
                'column' => $name,
                'expected' => $type,
                'actual' => $actual_type,
            ]);
        }

        $expected_not_null = (bool) ($expected['not_null'] ?? false);
        $actual_not_null = ((int) ($actual['notnull'] ?? 0)) === 1;
        if ($expected_not_null !== $actual_not_null) {
            respond_json(500, [], 'Column nullability mismatch', [
                'table' => $table,
                'column' => $name,
                'expected_not_null' => $expected_not_null,
                'actual_not_null' => $actual_not_null,
            ]);
        }
    }
}

function quote_sqlite_identifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}
