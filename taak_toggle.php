<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/manual_taken.php';

header('Content-Type: application/json');

$config = load_config();
require_login($config);

$id = (string) ($_POST['id'] ?? '');
$period = (string) ($_POST['period'] ?? '');

if ($id === '' || !preg_match('/^\d{4}-\d{2}$/', $period)) {
    http_response_code(400);
    echo json_encode(['error' => 'id en geldige periode zijn verplicht.']);
    exit;
}

$found = manual_taak_toggle($id, $period);
echo json_encode(['ok' => $found]);
