<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/yuki.php';

date_default_timezone_set('Europe/Brussels');
header('Content-Type: application/json');

$config = load_config();
require_login($config);

$taskId = $_GET['id'] ?? '';
$tasks = load_tasks();
$task = null;
foreach ($tasks as $t) {
    if ($t['id'] === $taskId) {
        $task = $t;
        break;
    }
}
if (!$task || $task['type'] !== 'check') {
    http_response_code(404);
    echo json_encode(['error' => 'unknown check task']);
    exit;
}

$period = selected_period();
$debug = isset($_GET['debug']);

try {
    $sessionId = yuki_session($config);

    switch ($task['check_id']) {
        case 'po_604003_selfmatch':
            require_once __DIR__ . '/checks/po_604003_selfmatch.php';
            $result = $debug
                ? debug_dump_po_604003($sessionId, $config, $period)
                : run_check_po_604003($sessionId, $config, $period);
            break;
        case 'assets':
            require_once __DIR__ . '/checks/assets.php';
            $result = run_check_assets($sessionId, $config, $period);
            break;
        case 'depreciation':
            require_once __DIR__ . '/checks/depreciation.php';
            $result = run_check_depreciation($sessionId, $config, $period);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'no implementation for ' . $task['check_id']]);
            exit;
    }

    echo json_encode(['ok' => true, 'period' => $period, 'debug' => $debug] + $result, $debug ? JSON_PRETTY_PRINT : 0);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
