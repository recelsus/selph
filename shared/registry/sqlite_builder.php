<?php
declare(strict_types=1);

const REGISTRY_SQLITE_TYPES = ['INTEGER', 'TEXT', 'REAL', 'BLOB', 'NUMERIC'];

function registry_normalize_columns(mixed $columns): array
{
    if (!is_array($columns) || $columns === []) {
        respond_json(400, [], 'columns must be a non-empty array');
    }

    $normalized = [];
    $seen = [];

    foreach ($columns as $column) {
        if (!is_array($column)) {
            respond_json(400, [], 'Each column must be an object');
        }

        $name = $column['name'] ?? '';
        if (!is_string($name)) {
            respond_json(400, [], 'Column name must be a string');
        }
        validate_segment($name, 'column');

        if (isset($seen[$name])) {
            respond_json(400, [], 'Duplicate column name', ['column' => $name]);
        }
        $seen[$name] = true;

        $type = strtoupper((string) ($column['type'] ?? 'TEXT'));
        if (!in_array($type, REGISTRY_SQLITE_TYPES, true)) {
            respond_json(400, [], 'Unsupported SQLite column type', [
                'column' => $name,
                'type' => $type,
                'allowed' => REGISTRY_SQLITE_TYPES,
            ]);
        }

        $normalized[] = [
            'name' => $name,
            'type' => $type,
            'not_null' => (bool) ($column['not_null'] ?? false),
            'primary_key' => (bool) ($column['primary_key'] ?? false),
            'auto_increment' => (bool) ($column['auto_increment'] ?? false),
            'unique' => (bool) ($column['unique'] ?? false),
            'default' => array_key_exists('default', $column) ? $column['default'] : null,
            'has_default' => array_key_exists('default', $column),
            'default_expression' => isset($column['default_expression']) && is_string($column['default_expression'])
                ? strtoupper($column['default_expression'])
                : null,
            'ui_type' => isset($column['ui_type']) && is_string($column['ui_type']) ? $column['ui_type'] : null,
        ];
    }

    return $normalized;
}

function registry_build_create_table_sql(string $table, array $columns, bool $if_not_exists): string
{
    validate_segment($table, 'table');

    $definitions = [];
    $primary_key_count = 0;

    foreach ($columns as $column) {
        if ($column['primary_key']) {
            $primary_key_count++;
        }
    }

    if ($primary_key_count > 1) {
        respond_json(400, [], 'Composite primary keys are not supported by registry table builder');
    }

    foreach ($columns as $column) {
        $definition = quote_sqlite_identifier($column['name']) . ' ' . $column['type'];

        if ($column['primary_key']) {
            $definition .= ' PRIMARY KEY';
            if ($column['auto_increment']) {
                if ($column['type'] !== 'INTEGER' || $primary_key_count !== 1) {
                    respond_json(400, [], 'auto_increment requires a single INTEGER primary key', [
                        'column' => $column['name'],
                    ]);
                }
                $definition .= ' AUTOINCREMENT';
            }
        }

        if ($column['not_null'] && !$column['primary_key']) {
            $definition .= ' NOT NULL';
        }

        if ($column['unique'] && !$column['primary_key']) {
            $definition .= ' UNIQUE';
        }

        if ($column['default_expression'] !== null) {
            $definition .= ' DEFAULT ' . registry_sqlite_default_expression($column['default_expression']);
        } elseif ($column['has_default']) {
            $definition .= ' DEFAULT ' . registry_sqlite_literal($column['default']);
        }

        $definitions[] = $definition;
    }

    $clause = $if_not_exists ? ' IF NOT EXISTS' : '';

    return sprintf(
        'CREATE TABLE%s %s (%s)',
        $clause,
        quote_sqlite_identifier($table),
        implode(', ', $definitions)
    );
}

function registry_sqlite_literal(mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    return "'" . str_replace("'", "''", (string) $value) . "'";
}

function registry_sqlite_default_expression(string $expression): string
{
    $allowed = ['CURRENT_TIME', 'CURRENT_DATE', 'CURRENT_TIMESTAMP'];
    if (!in_array($expression, $allowed, true)) {
        respond_json(400, [], 'Unsupported SQLite default expression', [
            'expression' => $expression,
            'allowed' => $allowed,
        ]);
    }

    return $expression;
}

function registry_columns_for_metadata(array $columns): array
{
    return array_map(static function (array $column): array {
        $entry = [
            'name' => $column['name'],
            'type' => $column['type'],
            'not_null' => $column['not_null'],
        ];

        if ($column['primary_key']) {
            $entry['primary_key'] = true;
        }

        if ($column['has_default']) {
            $entry['default'] = $column['default'];
        }

        if ($column['default_expression'] !== null) {
            $entry['default_expression'] = $column['default_expression'];
        }

        if ($column['ui_type'] !== null) {
            $entry['ui_type'] = $column['ui_type'];
        }

        return $entry;
    }, $columns);
}
