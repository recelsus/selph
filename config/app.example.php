<?php
declare(strict_types=1);

return [
    'api_prefix' => getenv('API_PREFIX') ?: 'api',
    'database_root' => getenv('DB_ROOT') ?: __DIR__ . '/../database',
    'json_flags' => JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    'security_database' => __DIR__ . '/security/security.sqlite',
];
