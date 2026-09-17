<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/todo.php';

header('Content-Type: application/json');

$config = load_config();
require_login($config);

$category = $_POST['category'] ?? '';
$id = $_POST['id'] ?? '';

if ($category === '' || $id === '') {
    http_response_code(400);
    echo json_encode(['error' => 'category and id required']);
    exit;
}

$found = todo_toggle($category, $id);
echo json_encode(['ok' => $found]);
