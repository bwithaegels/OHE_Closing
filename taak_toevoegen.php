<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/manual_taken.php';

header('Content-Type: application/json');

$config = load_config();
require_login($config);

$title      = trim((string) ($_POST['title'] ?? ''));
$kind       = ($_POST['kind'] ?? '') === 'recurring' ? 'recurring' : 'once';
$owner      = (string) ($_POST['owner'] ?? '');
$due        = trim((string) ($_POST['due'] ?? ''));
$period     = (string) ($_POST['period'] ?? '');
$endPeriod  = (string) ($_POST['end_period'] ?? '');

if ($title === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Titel is verplicht.']);
    exit;
}
if (!in_array($owner, TAAK_EIGENAARS, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Kies een geldige eigenaar.']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ongeldige periode.']);
    exit;
}
if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ongeldige vervaldatum.']);
    exit;
}
if ($kind === 'recurring') {
    if (!preg_match('/^\d{4}-\d{2}$/', $endPeriod)) {
        http_response_code(400);
        echo json_encode(['error' => 'Kies een einddatum voor de herhaling.']);
        exit;
    }
    if ($endPeriod < $period) {
        http_response_code(400);
        echo json_encode(['error' => 'De einddatum ligt voor de startperiode.']);
        exit;
    }
}

$task = manual_taak_add([
    'title'        => $title,
    'owner'        => $owner,
    'due'          => $due,
    'kind'         => $kind,
    'start_period' => $period,
    'end_period'   => $kind === 'recurring' ? $endPeriod : $period,
]);

echo json_encode(['ok' => true, 'task' => $task]);
