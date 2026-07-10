<?php
declare(strict_types=1);

function registry_build_endpoint_source(array $database, array $payload): array
{
    $endpoint = $payload['endpoint'] ?? $payload['name'] ?? '';
    if (!is_string($endpoint)) {
        respond_json(400, [], 'Endpoint name must be a string');
    }
    validate_segment($endpoint, 'endpoint');

    $kind = strtolower((string) ($payload['kind'] ?? 'select'));
    $table = $payload['table'] ?? '';
    if (!is_string($table)) {
        respond_json(400, [], 'Table name must be a string');
    }
    validate_segment($table, 'table');

    $metadata = load_database_metadata($database);
    $table_definition = $metadata['tables'][$table] ?? null;
    if (!is_array($table_definition)) {
        respond_json(400, [], 'Table is not registered in metadata', ['table' => $table]);
    }

    $columns = registry_metadata_column_names($table_definition);
    if ($columns === []) {
        respond_json(400, [], 'Table metadata has no columns', ['table' => $table]);
    }

    if ($kind === 'select') {
        return [
            'endpoint' => $endpoint,
            'source' => registry_select_endpoint_source($table, $columns),
        ];
    }

    if ($kind === 'insert') {
        $writable = registry_writable_columns($table_definition);
        if ($writable === []) {
            respond_json(400, [], 'No writable columns available for insert endpoint', ['table' => $table]);
        }

        return [
            'endpoint' => $endpoint,
            'source' => registry_insert_endpoint_source($table, $writable, $columns),
        ];
    }

    if ($kind === 'update') {
        $writable = registry_writable_columns($table_definition);
        if ($writable === []) {
            respond_json(400, [], 'No writable columns available for update endpoint', ['table' => $table]);
        }

        $key_column = registry_row_key_column($table_definition);

        return [
            'endpoint' => $endpoint,
            'source' => registry_update_endpoint_source($table, $writable, $columns, $key_column),
        ];
    }

    if ($kind === 'delete') {
        $key_column = registry_row_key_column($table_definition);

        return [
            'endpoint' => $endpoint,
            'source' => registry_delete_endpoint_source($table, $key_column),
        ];
    }

    respond_json(400, [], 'Unsupported endpoint kind', [
        'kind' => $kind,
        'allowed' => ['select', 'insert', 'update', 'delete'],
    ]);
}

function registry_metadata_column_names(array $table_definition): array
{
    $columns = $table_definition['columns'] ?? [];
    if (!is_array($columns)) {
        return [];
    }

    $names = [];
    foreach ($columns as $column) {
        if (is_array($column) && isset($column['name']) && is_string($column['name'])) {
            validate_segment($column['name'], 'column');
            $names[] = $column['name'];
        }
    }

    return $names;
}

function registry_writable_columns(array $table_definition): array
{
    $columns = $table_definition['columns'] ?? [];
    if (!is_array($columns)) {
        return [];
    }

    $writable = [];
    foreach ($columns as $column) {
        if (!is_array($column) || !isset($column['name']) || !is_string($column['name'])) {
            continue;
        }

        if (($column['primary_key'] ?? false) === true) {
            continue;
        }

        if (array_key_exists('default', $column) || array_key_exists('default_expression', $column)) {
            continue;
        }

        $writable[] = $column['name'];
    }

    return $writable;
}

function registry_row_key_column(array $table_definition): ?string
{
    $columns = $table_definition['columns'] ?? [];
    if (!is_array($columns)) {
        return null;
    }

    foreach ($columns as $column) {
        if (!is_array($column) || !isset($column['name']) || !is_string($column['name'])) {
            continue;
        }

        if (($column['primary_key'] ?? false) === true) {
            return $column['name'];
        }
    }

    return null;
}

function registry_select_endpoint_source(string $table, array $columns): string
{
    $select_list = implode(', ', array_map('quote_sqlite_identifier', $columns));
    $table_sql = quote_sqlite_identifier($table);

    return <<<PHP
<?php
declare(strict_types=1);

ensure_request_method(\$context['request']['method'], ['GET']);

validate_tables(\$context['pdo'], ['$table'], \$context['metadata']);

\$limit = max(1, min(500, (int) input_value(\$context, 'limit', 50)));
\$offset = max(0, (int) input_value(\$context, 'offset', 0));
\$pdo = \$context['pdo'];

try {
    \$stmt = \$pdo->prepare('SELECT $select_list FROM $table_sql LIMIT :limit OFFSET :offset');
    \$stmt->bindValue(':limit', \$limit, PDO::PARAM_INT);
    \$stmt->bindValue(':offset', \$offset, PDO::PARAM_INT);
    \$stmt->execute();

    return api_success([
        'rows' => \$stmt->fetchAll(),
        'limit' => \$limit,
        'offset' => \$offset,
    ], 'Rows retrieved');
} catch (Throwable \$exception) {
    return api_error('Failed to select rows', 500, ['detail' => \$exception->getMessage()]);
}

PHP;
}

function registry_insert_endpoint_source(string $table, array $writable_columns, array $return_columns): string
{
    $table_sql = quote_sqlite_identifier($table);
    $column_list = implode(', ', array_map('quote_sqlite_identifier', $writable_columns));
    $placeholder_list = implode(', ', array_map(static fn (string $column): string => ':' . $column, $writable_columns));
    $return_list = implode(', ', array_map('quote_sqlite_identifier', $return_columns));
    $assignments = registry_insert_assignment_source($writable_columns);

    return <<<PHP
<?php
declare(strict_types=1);

ensure_request_method(\$context['request']['method'], ['POST']);

validate_tables(\$context['pdo'], ['$table'], \$context['metadata']);

\$input = input_data(\$context);
\$pdo = \$context['pdo'];

try {
    \$stmt = \$pdo->prepare('INSERT INTO $table_sql ($column_list) VALUES ($placeholder_list)');
$assignments
    \$stmt->execute();

    \$id = (int) \$pdo->lastInsertId();
    \$query = \$pdo->prepare('SELECT $return_list FROM $table_sql WHERE rowid = :id');
    \$query->execute([':id' => \$id]);

    return api_success(['row' => \$query->fetch()], 'Row created', 201);
} catch (Throwable \$exception) {
    return api_error('Failed to insert row', 500, ['detail' => \$exception->getMessage()]);
}

PHP;
}

function registry_update_endpoint_source(string $table, array $writable_columns, array $return_columns, ?string $key_column): string
{
    $table_sql = quote_sqlite_identifier($table);
    $return_list = implode(', ', array_map('quote_sqlite_identifier', $return_columns));
    $set_list = implode(', ', array_map(
        static fn (string $column): string => quote_sqlite_identifier($column) . ' = :' . $column,
        $writable_columns
    ));
    $assignments = registry_insert_assignment_source($writable_columns);

    if ($key_column !== null) {
        $where_sql = quote_sqlite_identifier($key_column) . ' = :__row_key';
        $key_source = "\$row_key = input_value(\$context, '$key_column');";
    } else {
        $where_sql = 'rowid = :__row_key';
        $key_source = "\$row_key = input_value(\$context, 'rowid', input_value(\$context, 'id'));";
    }

    return <<<PHP
<?php
declare(strict_types=1);

ensure_request_method(\$context['request']['method'], ['POST', 'PATCH']);

validate_tables(\$context['pdo'], ['$table'], \$context['metadata']);

\$input = input_data(\$context);
$key_source
if (\$row_key === null || \$row_key === '') {
    return api_error('Row key is required', 400);
}
\$pdo = \$context['pdo'];

try {
    \$stmt = \$pdo->prepare('UPDATE $table_sql SET $set_list WHERE $where_sql');
$assignments
    \$stmt->bindValue(':__row_key', \$row_key);
    \$stmt->execute();

    \$query = \$pdo->prepare('SELECT $return_list FROM $table_sql WHERE $where_sql');
    \$query->bindValue(':__row_key', \$row_key);
    \$query->execute();

    return api_success([
        'row' => \$query->fetch() ?: null,
        'updated' => \$stmt->rowCount(),
    ], 'Row updated');
} catch (Throwable \$exception) {
    return api_error('Failed to update row', 500, ['detail' => \$exception->getMessage()]);
}

PHP;
}

function registry_delete_endpoint_source(string $table, ?string $key_column): string
{
    $table_sql = quote_sqlite_identifier($table);

    if ($key_column !== null) {
        $where_sql = quote_sqlite_identifier($key_column) . ' = :__row_key';
        $key_source = "\$row_key = input_value(\$context, '$key_column');";
    } else {
        $where_sql = 'rowid = :__row_key';
        $key_source = "\$row_key = input_value(\$context, 'rowid', input_value(\$context, 'id'));";
    }

    return <<<PHP
<?php
declare(strict_types=1);

ensure_request_method(\$context['request']['method'], ['POST', 'DELETE']);

validate_tables(\$context['pdo'], ['$table'], \$context['metadata']);

$key_source
if (\$row_key === null || \$row_key === '') {
    return api_error('Row key is required', 400);
}
\$pdo = \$context['pdo'];

try {
    \$stmt = \$pdo->prepare('DELETE FROM $table_sql WHERE $where_sql');
    \$stmt->bindValue(':__row_key', \$row_key);
    \$stmt->execute();

    return api_success(['deleted' => \$stmt->rowCount()], 'Row deleted');
} catch (Throwable \$exception) {
    return api_error('Failed to delete row', 500, ['detail' => \$exception->getMessage()]);
}

PHP;
}

function registry_insert_assignment_source(array $columns): string
{
    $lines = [];
    foreach ($columns as $column) {
        $exported = var_export($column, true);
        $placeholder = var_export(':' . $column, true);
        $lines[] = "    \$stmt->bindValue($placeholder, \$input[$exported] ?? null);";
    }

    return implode("\n", $lines);
}
