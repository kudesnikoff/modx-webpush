<?php
define('MODX_API_MODE', true);
$dir = __DIR__;
$config = null;
for ($i = 0; $i < 8; $i++) {
    $candidate = $dir . '/config.core.php';
    if (is_file($candidate)) { $config = $candidate; break; }
    $dir = dirname($dir);
}
if (!$config) {
    http_response_code(500);
    exit(json_encode(['success' => false, 'message' => 'MODX config.core.php not found']));
}
require_once $config;
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';
$modx = new modX();
$modx->initialize('web');

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'POST required']));
}

$corePath = $modx->getOption('webpush_core_path', null, $modx->getOption('core_path') . 'components/webpush/');
require_once $corePath . 'model/webpush/webpush.class.php';
$webPush = new WebPush($modx, ['corePath' => $corePath]);
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];
$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

try {
    if ($action === 'subscribe') {
        $webPush->subscribe($input);
        echo json_encode(['success' => true]);
    } elseif ($action === 'unsubscribe') {
        $webPush->unsubscribe($input['endpoint'] ?? '');
        echo json_encode(['success' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
