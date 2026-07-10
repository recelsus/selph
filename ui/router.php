<?php
declare(strict_types=1);

function handle_ui_post(SecurityGate $security): void
{
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'add_api_key':
                $security->createApiKey($_POST['api_key'] ?? null, $_POST['label'] ?? null);
                break;
            case 'delete_api_key':
                $security->deleteApiKey((int) ($_POST['id'] ?? 0));
                break;
            case 'add_ip_rule':
                $security->upsertIpRule(null, $_POST['cidr'] ?? '', $_POST['rule_action'] ?? 'deny');
                break;
            case 'update_ip_rule':
                $security->upsertIpRule((int) ($_POST['id'] ?? 0), $_POST['cidr'] ?? '', $_POST['rule_action'] ?? 'deny');
                break;
            case 'delete_ip_rule':
                $security->deleteIpRule((int) ($_POST['id'] ?? 0));
                break;
            case 'create_database':
                registry_create_database(['name' => $_POST['database'] ?? '']);
                break;
            case 'delete_database':
                registry_delete_database(['database' => $_POST['database'] ?? '']);
                break;
            default:
                respond_ui_error('Unknown action');
        }
    } catch (Throwable $exception) {
        respond_ui_error($exception->getMessage());
    }
}

function handle_ui_route(SecurityGate $security, string $client_ip, string $method, array $segments): void
{
    if ($security->evaluateIp($client_ip) !== 'allow' && !is_loopback_ip($client_ip)) {
        respond_ui_forbidden($client_ip);
    }

    if (!isset($segments[1]) || $segments[1] !== 'db' || !isset($segments[2])) {
        respond_json(404, [], 'UI endpoint not found');
    }

    $db_name = $segments[2];
    validate_segment($db_name, 'database');
    $database = resolve_database($db_name);
    $db_file = $database['file'];

    if (!is_file($db_file)) {
        respond_json(404, [], 'Database file not found');
    }

    if ($method === 'POST') {
        handle_database_ui_post($db_name);
        if (!headers_sent()) {
            header('Location: ' . ui_asset_path('ui/db/' . rawurlencode($db_name)), true, 303);
        }
        exit;
    }

    $pdo = open_database_connection($db_file);

    if (!isset($segments[3])) {
        $tables = fetch_sqlite_tables($pdo);
        render_db_tables_page($db_name, $tables, $client_ip);
        return;
    }

    $table = $segments[3];
    validate_segment($table, 'table');
    $tables = fetch_sqlite_tables($pdo);
    if (!in_array($table, $tables, true)) {
        respond_json(404, [], 'Table not found');
    }

    $metadata = load_database_metadata($database);
    $preview = fetch_table_preview($pdo, $table, 50, $metadata);
    render_db_tables_page($db_name, $tables, $client_ip, $table, $preview);
}

function handle_database_ui_post(string $db_name): void
{
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'create_table':
                registry_create_table([
                    'database' => $db_name,
                    'table' => $_POST['table'] ?? '',
                    'columns' => registry_columns_from_simple_schema(
                        is_array($_POST['column_name'] ?? null) ? $_POST['column_name'] : [],
                        is_array($_POST['column_type'] ?? null) ? $_POST['column_type'] : []
                    ),
                ]);
                break;
            case 'drop_table':
                registry_drop_table([
                    'database' => $db_name,
                    'table' => $_POST['table'] ?? '',
                ]);
                break;
            case 'update_cell':
                update_table_cell($db_name, $_POST);
                break;
            case 'insert_row':
                insert_table_row($db_name, $_POST);
                break;
            default:
                respond_ui_error('Unknown database action');
        }
    } catch (Throwable $exception) {
        respond_ui_error($exception->getMessage());
    }
}
