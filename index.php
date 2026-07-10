<?php
declare(strict_types=1);

// ==== Shared bootstrap ====
require_once __DIR__ . '/shared/api.php';
require_once __DIR__ . '/shared/schema_validator.php';
require_once __DIR__ . '/shared/security.php';
require_once __DIR__ . '/shared/table_data.php';
require_once __DIR__ . '/shared/registry/sqlite_builder.php';
require_once __DIR__ . '/shared/registry/endpoint_templates.php';
require_once __DIR__ . '/shared/registry/service.php';
require_once __DIR__ . '/shared/registry/router.php';
require_once __DIR__ . '/ui/helpers.php';
require_once __DIR__ . '/ui/index.php';
require_once __DIR__ . '/ui/pages/database.php';
require_once __DIR__ . '/ui/router.php';

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

$first_segment = $segments[0] ?? '';

if ($first_segment === 'ui') {
    handle_ui_route($security, $client_ip, $method, $segments);
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
