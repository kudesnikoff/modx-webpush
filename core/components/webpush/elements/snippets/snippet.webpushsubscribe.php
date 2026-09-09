<?php
$corePath = $modx->getOption('webpush_core_path', null, $modx->getOption('core_path') . 'components/webpush/');
if (!class_exists('WebPush')) {
    require_once $corePath . 'model/webpush/webpush.class.php';
}
$webPush = new WebPush($modx, ['corePath' => $corePath]);
$buttonText = isset($buttonText) ? (string)$buttonText : 'Получать уведомления';
$unsubscribeText = isset($unsubscribeText) ? (string)$unsubscribeText : 'Отключить уведомления';
$id = isset($id) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$id) : 'webpush-toggle';
if ($id === '') {
    $id = 'webpush-toggle';
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
if (empty($_SESSION['webpush_csrf']) || !is_string($_SESSION['webpush_csrf'])) {
    $_SESSION['webpush_csrf'] = bin2hex(random_bytes(32));
}

$modx->regClientStartupScript($webPush->config['assetsUrl'] . 'js/webpush.js');
$config = [
    'publicKey' => $webPush->publicKey(),
    'connectorUrl' => $webPush->config['connectorUrl'],
    'serviceWorkerUrl' => '/webpush-sw.js',
    'buttonId' => $id,
    'subscribeText' => $buttonText,
    'unsubscribeText' => $unsubscribeText,
    'csrfToken' => $_SESSION['webpush_csrf'],
];

$jsonFlags = JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT;
$json = json_encode($config, $jsonFlags);
if ($json === false) {
    $json = '{}';
}

$modx->regClientStartupHTMLBlock('<script>window.MODXWebPushConfig=' . $json . ';</script>');

return '<button type="button" id="'
    . htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    . '" class="webpush-toggle">'
    . htmlspecialchars($buttonText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    . '</button>';
