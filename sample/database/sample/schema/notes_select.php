<?php
declare(strict_types=1);

ensure_request_method($context['request']['method'], ['GET']);

$verified_tables = [
    'notes',
];

validate_tables($context['pdo'], $verified_tables, $context['metadata']);

$limit = (int) input_value($context, 'limit', 20);
$offset = (int) input_value($context, 'offset', 0);

$pdo = $context['pdo'];

try {
    $stmt = $pdo->prepare(
        'SELECT id, title, content, created_at FROM notes ORDER BY id DESC LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $notes = $stmt->fetchAll();

    return api_success(
        [
            'notes' => $notes,
            'limit' => $limit,
            'offset' => $offset,
        ],
        'Notes retrieved'
    );
} catch (Throwable $exception) {
    return api_error('Failed to load notes', 500, ['detail' => $exception->getMessage()]);
}
