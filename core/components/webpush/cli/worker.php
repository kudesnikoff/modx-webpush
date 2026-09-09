<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = isset($argv[1]) ? rtrim($argv[1], '/\\') : dirname(__DIR__, 5);
$config = $root . '/config.core.php';
if (!is_file($config)) {
    fwrite(STDERR, "Usage: php worker.php /path/to/modx-root\n");
    exit(2);
}

define('MODX_API_MODE', true);
require_once $config;
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';

if (class_exists('\\MODX\\Revolution\\modX')) {
    $modx = new \MODX\Revolution\modX();
} elseif (class_exists('modX')) {
    $modx = new modX();
} else {
    fwrite(STDERR, "Unable to bootstrap MODX\n");
    exit(3);
}

$modx->initialize('web');
$corePath = $modx->getOption('webpush_core_path', null, $modx->getOption('core_path') . 'components/webpush/');
require_once $corePath . 'model/webpush/webpush.class.php';
$webPush = new WebPush($modx, ['corePath' => $corePath]);
$result = $webPush->processQueue(
    (int)$modx->getOption('webpush_jobs_per_run', null, 5),
    (int)$modx->getOption('webpush_batch_size', null, 500)
);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
