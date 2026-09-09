<?php
define('MODX_API_MODE', true);
$dir = __DIR__;
$config = null;
for ($i = 0; $i < 8; $i++) {
    $candidate = $dir . '/config.core.php';
    if (is_file($candidate)) {
        $config = $candidate;
        break;
    }
    $dir = dirname($dir);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

if (!$config) {
    http_response_code(500);
    exit(json_encode(['success' => false, 'message' => 'Server configuration error']));
}

require_once $config;
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';
if (class_exists('\\MODX\\Revolution\\modX')) {
    $modx = new \MODX\Revolution\modX();
} elseif (class_exists('modX')) {
    $modx = new modX();
} else {
    http_response_code(500);
    exit(json_encode(['success' => false, 'message' => 'Server bootstrap error']));
}
$modx->initialize('web');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit(json_encode(['success' => false, 'message' => 'POST required']));
}

$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
if (strpos($contentType, 'application/json') !== 0) {
    http_response_code(415);
    exit(json_encode(['success' => false, 'message' => 'application/json required']));
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength < 0 || $contentLength > 32768) {
    http_response_code(413);
    exit(json_encode(['success' => false, 'message' => 'Payload too large']));
}

$fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'same-site'], true)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Forbidden request context']));
}

$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($origin !== '') {
    $siteUrl = rtrim((string)$modx->getOption('site_url'), '/');
    $originParts = parse_url($origin);
    $siteParts = parse_url($siteUrl);
    $originScheme = strtolower((string)($originParts['scheme'] ?? ''));
    $siteScheme = strtolower((string)($siteParts['scheme'] ?? ''));
    $sameOrigin = is_array($originParts)
        && is_array($siteParts)
        && $originScheme === $siteScheme
        && strtolower((string)($originParts['host'] ?? '')) === strtolower((string)($siteParts['host'] ?? ''))
        && (int)($originParts['port'] ?? ($originScheme === 'https' ? 443 : 80))
            === (int)($siteParts['port'] ?? ($siteScheme === 'https' ? 443 : 80));
    if (!$sameOrigin) {
        http_response_code(403);
        exit(json_encode(['success' => false, 'message' => 'Forbidden origin']));
    }
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || strlen($raw) > 32768) {
    http_response_code(413);
    exit(json_encode(['success' => false, 'message' => 'Payload too large']));
}
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Invalid JSON']));
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$providedToken = isset($input['csrfToken']) ? (string)$input['csrfToken'] : '';
$sessionToken = isset($_SESSION['webpush_csrf']) && is_string($_SESSION['webpush_csrf']) ? $_SESSION['webpush_csrf'] : '';
unset($input['csrfToken']);
if ($providedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $providedToken)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Invalid security token']));
}

$now = time();
$windowStart = $now - 600;
$history = isset($_SESSION['webpush_mutations']) && is_array($_SESSION['webpush_mutations'])
    ? $_SESSION['webpush_mutations']
    : [];
$history = array_values(array_filter($history, static function ($timestamp) use ($windowStart) {
    return is_int($timestamp) && $timestamp >= $windowStart;
}));
if (count($history) >= 30) {
    http_response_code(429);
    header('Retry-After: 600');
    exit(json_encode(['success' => false, 'message' => 'Too many requests']));
}
$history[] = $now;
$_SESSION['webpush_mutations'] = $history;

$corePath = $modx->getOption('webpush_core_path', null, $modx->getOption('core_path') . 'components/webpush/');
require_once $corePath . 'model/webpush/webpush.class.php';
$webPush = new WebPush($modx, ['corePath' => $corePath]);
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
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid subscription data']);
} catch (Throwable $e) {
    if (method_exists($modx, 'log')) {
        $level = defined('modX::LOG_LEVEL_ERROR') ? constant('modX::LOG_LEVEL_ERROR') : 1;
        $modx->log($level, '[WebPush] Connector error: ' . $e->getMessage());
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
