<?php
declare(strict_types=1);

final class SecurityGate
{
    private PDO $pdo;
    private array $ip_rules = [];
    private bool $has_keys = false;

    public function __construct(string $db_path)
    {
        $directory = dirname($db_path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $this->pdo = new PDO('sqlite:' . $db_path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $this->bootstrap();
        $this->ip_rules = $this->loadIpRules();
        $this->has_keys = $this->detectApiKeys();
    }

    public function evaluateIp(string $ip): string
    {
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return 'neutral';
        }

        $match = null;
        $match_prefix = -1;

        foreach ($this->ip_rules as $rule) {
            [$prefix_len, $hit] = $this->ipMatchesCidr($ip, $rule['cidr']);
            if ($hit && $prefix_len > $match_prefix) {
                $match_prefix = $prefix_len;
                $match = $rule['action'];
            }
        }

        return $match ?? 'neutral';
    }

    public function requiresApiKey(string $ip_decision): bool
    {
        if ($ip_decision === 'allow') {
            return false;
        }

        return $this->has_keys;
    }

    public function isValidKey(?string $provided): bool
    {
        if ($provided === null || $provided === '') {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT 1 FROM api_keys WHERE api_key = :key LIMIT 1');
        $stmt->execute([':key' => $provided]);

        return (bool) $stmt->fetchColumn();
    }

    public function listApiKeys(): array
    {
        $stmt = $this->pdo->query('SELECT id, api_key, label FROM api_keys ORDER BY id DESC');

        return $stmt === false ? [] : $stmt->fetchAll();
    }

    public function createApiKey(?string $key, ?string $label): string
    {
        $api_key = $key !== null && trim($key) !== '' ? trim($key) : bin2hex(random_bytes(16));
        $stmt = $this->pdo->prepare('INSERT INTO api_keys (api_key, label) VALUES (:key, :label)');
        $stmt->execute([':key' => $api_key, ':label' => $label]);
        $this->has_keys = true;

        return $api_key;
    }

    public function deleteApiKey(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM api_keys WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $this->has_keys = $this->detectApiKeys();
    }

    public function listIpRules(): array
    {
        $stmt = $this->pdo->query('SELECT id, cidr, action FROM ip_rules ORDER BY id ASC');

        return $stmt === false ? [] : $stmt->fetchAll();
    }

    public function upsertIpRule(?int $id, string $cidr, string $action): void
    {
        $cidr = trim($cidr);
        $action = $action === 'allow' ? 'allow' : 'deny';

        if ($cidr === '') {
            throw new InvalidArgumentException('CIDR must not be empty');
        }

        if ($id === null) {
            $stmt = $this->pdo->prepare('INSERT INTO ip_rules (cidr, action) VALUES (:cidr, :action)');
            $stmt->execute([':cidr' => $cidr, ':action' => $action]);
        } else {
            $stmt = $this->pdo->prepare('UPDATE ip_rules SET cidr = :cidr, action = :action WHERE id = :id');
            $stmt->execute([':cidr' => $cidr, ':action' => $action, ':id' => $id]);
        }

        $this->ip_rules = $this->loadIpRules();
    }

    public function deleteIpRule(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ip_rules WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $this->ip_rules = $this->loadIpRules();
    }

    private function bootstrap(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS api_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                api_key TEXT NOT NULL UNIQUE,
                label TEXT
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS ip_rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                cidr TEXT NOT NULL,
                action TEXT NOT NULL CHECK(action IN ("allow", "deny"))
            )'
        );
    }

    private function loadIpRules(): array
    {
        $stmt = $this->pdo->query('SELECT cidr, action FROM ip_rules');

        return $stmt === false ? [] : $stmt->fetchAll();
    }

    private function detectApiKeys(): bool
    {
        $stmt = $this->pdo->query('SELECT 1 FROM api_keys LIMIT 1');

        return $stmt !== false && $stmt->fetchColumn() !== false;
    }

    private function ipMatchesCidr(string $ip, string $cidr): array
    {
        if ($cidr === '') {
            return [-1, false];
        }

        if (strpos($cidr, '/') === false) {
            return [$this->maxPrefixLength($ip), $ip === $cidr];
        }

        [$network, $prefix] = explode('/', $cidr, 2);
        $prefix = (int) $prefix;

        $packed_ip = @inet_pton($ip);
        $packed_network = @inet_pton($network);
        if ($packed_ip === false || $packed_network === false) {
            return [-1, false];
        }

        $bytes = strlen($packed_ip);
        $bits = $prefix;
        $mask = '';

        for ($i = 0; $i < $bytes; $i++) {
            if ($bits >= 8) {
                $mask .= chr(0xFF);
                $bits -= 8;
            } elseif ($bits > 0) {
                $mask .= chr((0xFF << (8 - $bits)) & 0xFF);
                $bits = 0;
            } else {
                $mask .= chr(0x00);
            }
        }

        $ip_masked = $packed_ip & $mask;
        $network_masked = $packed_network & $mask;

        return [$prefix, $ip_masked === $network_masked];
    }

    private function maxPrefixLength(string $ip): int
    {
        return strpos($ip, ':') !== false ? 128 : 32;
    }
}

function security_gate(): SecurityGate
{
    static $gate;
    if ($gate === null) {
        $config = load_app_config();
        $gate = new SecurityGate($config['security_database'] ?? __DIR__ . '/../database/security/security.sqlite');
    }

    return $gate;
}

function client_ip(): string
{
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
}

function is_loopback_ip(string $ip): bool
{
    if ($ip === '') {
        return false;
    }

    if ($ip === '127.0.0.1' || $ip === '::1') {
        return true;
    }

    return false;
}

function extract_api_key(): ?string
{
    $headers = [
        'HTTP_X_API_KEY',
        'HTTP_X_APIKEY',
        'HTTP_AUTHORIZATION',
    ];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $value = trim((string) $_SERVER[$header]);
            if ($header === 'HTTP_AUTHORIZATION' && stripos($value, 'ApiKey ') === 0) {
                return trim(substr($value, 7));
            }

            return $value;
        }
    }

    $query_key = $_GET['api_key'] ?? $_POST['api_key'] ?? null;

    return $query_key !== null ? trim((string) $query_key) : null;
}
