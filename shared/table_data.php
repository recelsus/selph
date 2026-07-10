<?php
declare(strict_types=1);

function fetch_sqlite_tables(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    if ($stmt === false) {
        return [];
    }

    return array_map(static fn ($row) => $row['name'], $stmt->fetchAll());
}

function fetch_table_preview(PDO $pdo, string $table, int $limit = 50, array $metadata = []): array
{
    $schema = fetch_table_schema($pdo, $table, $metadata);
    $query = sprintf('SELECT rowid AS __selph_rowid, * FROM %s LIMIT %d', quote_sqlite_identifier($table), $limit);
    $stmt = $pdo->query($query);
    if ($stmt === false) {
        return ['columns' => [], 'rows' => [], 'schema' => $schema];
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $columns = array_keys($schema);

    return ['columns' => $columns, 'rows' => $rows, 'schema' => $schema];
}

function fetch_table_schema(PDO $pdo, string $table, array $metadata = []): array
{
    $stmt = $pdo->query(sprintf('PRAGMA table_info(%s)', quote_sqlite_identifier($table)));
    if ($stmt === false) {
        return [];
    }

    $schema = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $name = (string) ($column['name'] ?? '');
        if ($name === '') {
            continue;
        }

        $type = strtoupper((string) ($column['type'] ?? 'TEXT'));
        $primary_key = ((int) ($column['pk'] ?? 0)) > 0;
        $schema[$name] = [
            'type' => $type,
            'ui_type' => metadata_ui_column_type($metadata, $table, $name) ?? infer_ui_column_type($type, $primary_key),
            'editable' => true,
            'primary_key' => $primary_key,
        ];
    }

    return $schema;
}

function metadata_ui_column_type(array $metadata, string $table, string $column): ?string
{
    $definitions = $metadata['tables'][$table]['columns'] ?? null;
    if (!is_array($definitions)) {
        return null;
    }

    foreach ($definitions as $definition) {
        if (!is_array($definition) || ($definition['name'] ?? null) !== $column) {
            continue;
        }

        $ui_type = $definition['ui_type'] ?? null;
        if (is_string($ui_type) && in_array($ui_type, ['text', 'number', 'bool'], true)) {
            return $ui_type;
        }
    }

    return null;
}

function infer_ui_column_type(string $sqlite_type, bool $primary_key): string
{
    if ($primary_key) {
        return 'number';
    }

    if (str_contains($sqlite_type, 'REAL') || str_contains($sqlite_type, 'INT') || str_contains($sqlite_type, 'NUM')) {
        return 'number';
    }

    return 'text';
}

function update_table_cell(string $db_name, array $input): void
{
    $database = resolve_database($db_name);
    if ($database['exists'] !== true) {
        respond_json(404, [], 'Database file not found');
    }

    $table = $input['table'] ?? '';
    $column = $input['column'] ?? '';
    $rowid = $input['rowid'] ?? '';
    $value = $input['value'] ?? null;

    if (!is_string($table) || !is_string($column)) {
        respond_json(400, [], 'Invalid table or column');
    }

    validate_segment($table, 'table');
    validate_segment($column, 'column');

    if (!is_numeric($rowid)) {
        respond_json(400, [], 'Invalid row id');
    }

    $pdo = open_database_connection($database['file']);
    $metadata = load_database_metadata($database);
    $schema = fetch_table_schema($pdo, $table, $metadata);
    if (!isset($schema[$column])) {
        respond_json(400, [], 'Unknown column');
    }

    if ($schema[$column]['editable'] !== true) {
        respond_json(400, [], 'Column is not editable');
    }

    $normalized = normalize_cell_value($value, $schema[$column]['ui_type']);
    $sql = sprintf(
        'UPDATE %s SET %s = :value WHERE rowid = :rowid',
        quote_sqlite_identifier($table),
        quote_sqlite_identifier($column)
    );

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':value', $normalized['value'], $normalized['pdo_type']);
    $stmt->bindValue(':rowid', (int) $rowid, PDO::PARAM_INT);
    $stmt->execute();

    respond_json(200, [
        'table' => $table,
        'column' => $column,
        'rowid' => (int) $rowid,
        'value' => $normalized['value'],
    ], 'Cell updated');
}

function insert_table_row(string $db_name, array $input): void
{
    $database = resolve_database($db_name);
    if ($database['exists'] !== true) {
        respond_json(404, [], 'Database file not found');
    }

    $table = $input['table'] ?? '';
    $column = $input['column'] ?? '';
    $value = $input['value'] ?? null;

    if (!is_string($table) || !is_string($column)) {
        respond_json(400, [], 'Invalid table or column');
    }

    validate_segment($table, 'table');
    validate_segment($column, 'column');

    $pdo = open_database_connection($database['file']);
    $metadata = load_database_metadata($database);
    $schema = fetch_table_schema($pdo, $table, $metadata);
    if (!isset($schema[$column])) {
        respond_json(400, [], 'Unknown column');
    }

    if (($schema[$column]['primary_key'] ?? false) === true && $value === '') {
        $sql = sprintf('INSERT INTO %s DEFAULT VALUES', quote_sqlite_identifier($table));
        $stmt = $pdo->prepare($sql);
    } else {
        $normalized = normalize_cell_value($value, $schema[$column]['ui_type']);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (:value)',
            quote_sqlite_identifier($table),
            quote_sqlite_identifier($column)
        );

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':value', $normalized['value'], $normalized['pdo_type']);
    }
    $stmt->execute();

    $inserted_rowid = (int) $pdo->lastInsertId();
    $display_value = fetch_cell_value($pdo, $table, $column, $inserted_rowid);

    respond_json(201, [
        'table' => $table,
        'column' => $column,
        'rowid' => $inserted_rowid,
        'value' => $display_value,
    ], 'Row inserted');
}

function fetch_cell_value(PDO $pdo, string $table, string $column, int $rowid): mixed
{
    $sql = sprintf(
        'SELECT %s AS value FROM %s WHERE rowid = :rowid',
        quote_sqlite_identifier($column),
        quote_sqlite_identifier($table)
    );
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':rowid', $rowid, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? ($row['value'] ?? null) : null;
}

function normalize_cell_value(mixed $value, string $ui_type): array
{
    if ($value === '') {
        return ['value' => null, 'pdo_type' => PDO::PARAM_NULL];
    }

    if ($ui_type === 'number') {
        if (!is_numeric($value)) {
            respond_json(400, [], 'Value must be numeric');
        }

        return ['value' => (float) $value, 'pdo_type' => PDO::PARAM_STR];
    }

    if ($ui_type === 'bool') {
        if ($value === '1' || $value === 1 || $value === true || $value === 'true') {
            return ['value' => 1, 'pdo_type' => PDO::PARAM_INT];
        }
        if ($value === '0' || $value === 0 || $value === false || $value === 'false') {
            return ['value' => 0, 'pdo_type' => PDO::PARAM_INT];
        }

        respond_json(400, [], 'Value must be boolean');
    }

    return ['value' => (string) $value, 'pdo_type' => PDO::PARAM_STR];
}
