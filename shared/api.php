<?php
declare(strict_types=1);

$app_config = load_app_config();

defined('API_PREFIX') || define('API_PREFIX', $app_config['api_prefix']);
defined('DB_ROOT') || define('DB_ROOT', $app_config['database_root']);
defined('JSON_FLAGS') || define('JSON_FLAGS', $app_config['json_flags']);

function respond_json(int $status, array $data = [], ?string $message = null, ?array $error = null): void
{
    $payload = format_api_payload($status, $data, $message, $error);

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
    }

    echo json_encode($payload, JSON_FLAGS) ?: '{}';
    exit;
}

function format_api_payload(int $status, array $data = [], ?string $message = null, ?array $error = null): array
{
    return [
        'status' => $status,
        'message' => $message ?? default_status_message($status),
        'data' => $data,
        'error' => $error,
    ];
}

function default_status_message(int $status): string
{
    if ($status >= 200 && $status < 300) {
        return 'OK';
    }

    if ($status >= 400 && $status < 500) {
        return 'Bad Request';
    }

    if ($status >= 500) {
        return 'Server Error';
    }

    return 'Unknown';
}

function api_success(array $data = [], string $message = 'OK', int $status = 200): array
{
    return [
        'status' => $status,
        'body' => format_api_payload($status, $data, $message, null),
    ];
}

function api_error(string $message, int $status = 400, ?array $error = null, array $data = []): array
{
    return [
        'status' => $status,
        'body' => format_api_payload($status, $data, $message, $error ?? ['message' => $message]),
    ];
}

function validate_segment(string $value, string $type): void
{
    if ($value === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $value)) {
        respond_json(400, [], sprintf('Invalid %s name', $type), ['field' => $type]);
    }
}

function request_segments(string $path): array
{
    $trimmed = trim($path, '/');
    if ($trimmed === '') {
        return [];
    }

    return array_values(array_filter(explode('/', $trimmed), static fn (string $segment) => $segment !== ''));
}

function collect_request_input(string $method): array
{
    $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
    $raw_body = file_get_contents('php://input');
    if ($raw_body === false) {
        $raw_body = '';
    }

    $payload = [];
    $json = null;

    if (stripos($content_type, 'application/json') === 0 && $raw_body !== '') {
        $decoded = json_decode($raw_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            respond_json(400, [], 'Invalid JSON payload', ['reason' => json_last_error_msg()]);
        }

        if (!is_array($decoded)) {
            respond_json(400, [], 'Invalid JSON payload', ['reason' => 'JSON body must be an object']);
        }

        $json = $decoded;
        $payload = $decoded;
    } elseif ($method === 'GET') {
        $payload = $_GET;
    } else {
        $payload = $_POST;
    }

    if (!is_array($payload)) {
        $payload = [];
    }

    return [
        'content_type' => $content_type,
        'raw' => $raw_body,
        'json' => $json,
        'data' => $payload,
    ];
}

function resolve_database(string $db_name): array
{
    $db_dir = DB_ROOT . '/' . $db_name;
    $db_file = $db_dir . '/' . $db_name . '.sqlite';
    $schema_dir = $db_dir . '/schema';

    return [
        'name' => $db_name,
        'dir' => $db_dir,
        'file' => $db_file,
        'schema_dir' => $schema_dir,
        'exists' => is_dir($db_dir) && is_file($db_file),
    ];
}

function input_data(array $context): array
{
    return $context['input']['data'] ?? [];
}

function input_value(array $context, string $key, mixed $default = null): mixed
{
    $data = input_data($context);

    if (array_key_exists($key, $data)) {
        return $data[$key];
    }

    return $default;
}

function open_database_connection(string $db_file): PDO
{
    try {
        return new PDO('sqlite:' . $db_file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $exception) {
        respond_json(500, [], 'Failed to open database', ['detail' => $exception->getMessage()]);
    }
}

function ensure_request_method(string $method, array $allowed): void
{
    if (!in_array($method, $allowed, true)) {
        if (!headers_sent()) {
            header('Allow: ' . implode(', ', $allowed));
        }

        respond_json(405, [], 'Method not allowed', ['allowed' => $allowed]);
    }
}

function load_app_config(): array
{
    static $config;

    if ($config !== null) {
        return $config;
    }

    $defaults = [
        'api_prefix' => 'api',
        'database_root' => __DIR__ . '/../database',
        'json_flags' => JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        'security_database' => __DIR__ . '/../config/security/security.sqlite',
    ];

    $config_path = __DIR__ . '/../config/app.php';
    if (!is_file($config_path)) {
        return $config = $defaults;
    }

    $loaded = require $config_path;
    if (!is_array($loaded)) {
        return $config = $defaults;
    }

    return $config = array_merge($defaults, $loaded);
}
