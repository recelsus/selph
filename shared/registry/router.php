<?php
declare(strict_types=1);

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

    $action = $segments[1] ?? '';
    if ($action === '') {
        respond_json(404, [], 'Unknown registry endpoint');
    }

    $payload = $input['json'] ?? $input['data'] ?? [];
    if (!is_array($payload)) {
        respond_json(400, [], 'Payload must be an object');
    }

    switch ($action) {
        case 'create_db':
            respond_json(201, registry_create_database($payload), 'Database created');
            break;
        case 'create_table':
            respond_json(201, registry_create_table($payload), 'Table created');
            break;
        case 'delete_db':
            respond_json(200, registry_delete_database($payload), 'Database deleted');
            break;
        case 'create_endpoint':
            respond_json(201, registry_create_endpoint($payload), 'Endpoint created');
            break;
        case 'drop_table':
            respond_json(200, registry_drop_table($payload), 'Table dropped');
            break;
        default:
            respond_json(404, [], 'Unknown registry endpoint');
    }
}
