<?php
declare(strict_types=1);

// ==== Shared bootstrap ====
require_once __DIR__ . '/shared/api.php';
require_once __DIR__ . '/shared/schema_validator.php';
require_once __DIR__ . '/shared/security.php';
require_once __DIR__ . '/ui/registry.php';
require_once __DIR__ . '/ui/index.php';
require_once __DIR__ . '/ui/pages/database.php';

if (PHP_SAPI === 'cli-server') {
    $asset_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $asset_file = realpath(__DIR__ . $asset_path);
    if ($asset_file !== false && is_file($asset_file) && strpos($asset_file, __DIR__) === 0) {
        return false;
    }
}

// ==== Request metadata ====
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/') ?: '/';
$script_dir = $script_dir === '/' ? '' : trim(str_replace('\\', '/', $script_dir), '/');
if ($script_dir !== '') {
    $prefix = '/' . $script_dir;
    $prefix_len = strlen($prefix);
    if (strncmp($path, $prefix, $prefix_len) === 0) {
        $path = substr($path, $prefix_len) ?: '/';
    }
}
$segments = request_segments($path);
$security = security_gate();
$client_ip = client_ip();
$ip_decision = $security->evaluateIp($client_ip);

if ($ip_decision === 'deny') {
    respond_json(403, ['ip' => $client_ip], 'Access denied by IP policy');
}

if ($segments === []) {
    if ($ip_decision !== 'allow' && !is_loopback_ip($client_ip)) {
        respond_ui_forbidden($client_ip);
    }

    if ($method === 'POST') {
        handle_ui_post($security);
        header('Location: /', true, 303);
        exit;
    }

    render_ui_page([
        'databases' => list_database_endpoints(),
        'api_keys' => $security->listApiKeys(),
        'ip_rules' => $security->listIpRules(),
        'client_ip' => $client_ip,
        'base_url' => ui_base_url(),
    ]);
    exit;
}

// ==== Path sanity ====
if ($segments === []) {
    if ($ip_decision !== 'allow' && !is_loopback_ip($client_ip)) {
        respond_ui_forbidden($client_ip);
    }

    if ($method === 'POST') {
        handle_ui_post($security);
        header('Location: /', true, 303);
        exit;
    }

    render_ui_page([
        'databases' => list_database_endpoints(),
        'api_keys' => $security->listApiKeys(),
        'ip_rules' => $security->listIpRules(),
        'client_ip' => $client_ip,
        'base_url' => ui_base_url(),
    ]);
    exit;
}

$first_segment = $segments[0] ?? '';

if ($first_segment === 'ui') {
    handle_ui_route($security, $client_ip, $segments);
    exit;
}

if ($first_segment !== API_PREFIX && $first_segment !== 'registry') {
    respond_json(404, [], 'Endpoint not found'); // deny non api path
}

if ($first_segment === 'registry') {
    handle_registry_request($security, $client_ip, $method, $segments, $input ?? collect_request_input($method));
    exit;
}

if (!isset($segments[1])) {
    respond_json(400, [], 'Database name is required'); // need db slug
}

// ==== Database resolution ====
$db_name = $segments[1];
validate_segment($db_name, 'database');
$database = resolve_database($db_name);

if (count($segments) === 2) {
    respond_json(200, ['database' => $db_name, 'exists' => $database['exists']], 'Database lookup'); // allow quick ping
}

if (count($segments) !== 3) {
    respond_json(404, [], 'Unsupported endpoint depth'); // only /api/db/endpoint
}

// ==== Endpoint location ====
$endpoint_name = $segments[2];
validate_segment($endpoint_name, 'endpoint');

$api_key_required = $security->requiresApiKey($ip_decision);
if ($api_key_required) {
    $provided_key = extract_api_key();
    if (!$security->isValidKey($provided_key)) {
        respond_json(401, [], 'API key required');
    }
}

if ($database['exists'] !== true) {
    respond_json(404, ['database' => $db_name], 'Database file not found');
}

if (!is_dir($database['schema_dir'])) {
    respond_json(404, [], 'Schema directory missing');
}

$endpoint_file = $database['schema_dir'] . '/' . $endpoint_name . '.php';
if (!is_file($endpoint_file)) {
    respond_json(404, [], 'Endpoint script not found');
}

// ==== Request payload + metadata ====
$input = collect_request_input($method);
$metadata = load_database_metadata($database); // meta may disable validation

// ==== Context passed to schema endpoint ====
$context = [
    'database' => $database,
    'endpoint' => ['name' => $endpoint_name, 'file' => $endpoint_file],
    'request' => [
        'method' => $method,
        'path' => $path,
        'segments' => $segments,
        'query' => $_GET,
    ],
    'input' => $input,
    'metadata' => $metadata,
];

$context['pdo'] = open_database_connection($database['file']); // sqlite handle shared by endpoint

// ==== Schema endpoint execution ====
try {
    $result = require $endpoint_file;
} catch (Throwable $exception) {
    respond_json(500, [], 'Endpoint execution failed', ['detail' => $exception->getMessage()]);
}

if (!is_array($result) || !array_key_exists('body', $result)) {
    respond_json(500, [], 'Endpoint did not return a valid response');
}

$status = isset($result['status']) && is_int($result['status']) ? $result['status'] : 200;
$body = $result['body'];

if (!is_array($body)) {
    respond_json(500, [], 'Endpoint body must be an array');
}

// ==== Normalise endpoint payload ====
$response_status = isset($body['status']) ? (int) $body['status'] : $status;
$response_message = $body['message'] ?? null;
$response_data = $body['data'] ?? [];
$response_error = $body['error'] ?? null;

respond_json($response_status, $response_data, $response_message, $response_error);

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
            default:
                respond_ui_error('Unknown action');
        }
    } catch (Throwable $exception) {
        respond_ui_error($exception->getMessage());
    }
}

function handle_registry_request(SecurityGate $security, string $client_ip, string $method, array $segments, array $input): void
{
    $ip_decision = $security->evaluateIp($client_ip);
    if ($ip_decision === 'deny') {
        respond_json(403, ['ip' => $client_ip], 'Registry access denied');
    }

    $allowed_without_key = $ip_decision === 'allow' || is_loopback_ip($client_ip);

    if (!$allowed_without_key && $security->requiresApiKey($ip_decision)) {
        $provided_key = extract_api_key();
        if (!$security->isValidKey($provided_key)) {
            respond_json(401, [], 'API key required for registry');
        }
    }

    if ($method !== 'POST') {
        respond_json(405, [], 'Method not allowed');
    }

    if (!isset($segments[1]) || $segments[1] !== 'create_db') {
        respond_json(404, [], 'Unknown registry endpoint');
    }

    $payload = $input['json'] ?? $input['data'] ?? [];
    if (!is_array($payload)) {
        respond_json(400, [], 'Payload must be an object');
    }

    $result = registry_create_database($payload);
    respond_json(201, $result, 'Database created');
}

function ui_asset_path(string $relative): string
{
    $relative = ltrim($relative, '/');
    $script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/') ?: '/';
    $base = rtrim($script_dir, '/');

    return ($base === '' ? '' : $base) . '/' . $relative;
}

function fetch_sqlite_tables(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    if ($stmt === false) {
        return [];
    }

    return array_map(static fn ($row) => $row['name'], $stmt->fetchAll());
}

function fetch_table_preview(PDO $pdo, string $table, int $limit = 50): array
{
    $query = sprintf('SELECT * FROM %s LIMIT %d', quote_sqlite_identifier($table), $limit);
    $stmt = $pdo->query($query);
    if ($stmt === false) {
        return ['columns' => [], 'rows' => []];
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $columns = $rows === [] ? [] : array_keys($rows[0]);

    return ['columns' => $columns, 'rows' => $rows];
}

function handle_ui_route(SecurityGate $security, string $client_ip, array $segments): void
{
    if ($security->evaluateIp($client_ip) !== 'allow' && !is_loopback_ip($client_ip)) {
        respond_ui_forbidden($client_ip);
    }

    if (!isset($segments[1]) || $segments[1] !== 'db' || !isset($segments[2])) {
        respond_json(404, [], 'UI endpoint not found');
    }

    $db_name = $segments[2];
    validate_segment($db_name, 'database');
    $db_dir = DB_ROOT . '/' . $db_name;
    $db_file = $db_dir . '/' . $db_name . '.sqlite';

    if (!is_file($db_file)) {
        respond_json(404, [], 'Database file not found');
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

    $preview = fetch_table_preview($pdo, $table, 50);
    render_table_rows_page($db_name, $table, $preview, $client_ip);
}
