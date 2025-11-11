<?php
declare(strict_types=1);

ensure_request_method($context['request']['method'], ['POST']);

$verified_tables = [
    'notes',
];

validate_tables($context['pdo'], $verified_tables, $context['metadata']);

$title = input_value($context, 'title');
$content = input_value($context, 'content');

$pdo = $context['pdo'];

try {
    $stmt = $pdo->prepare('INSERT INTO notes (title, content) VALUES (:title, :content)');
    $stmt->execute([
        ':title' => $title,
        ':content' => $content,
    ]);

    $id = (int) $pdo->lastInsertId();
    $query = $pdo->prepare('SELECT id, title, content, created_at FROM notes WHERE id = :id');
    $query->execute([':id' => $id]);

    return api_success(['note' => $query->fetch()], 'Note created', 201);
} catch (Throwable $exception) {
    return api_error('Failed to create note', 500, ['detail' => $exception->getMessage()]);
}
