<?php
$corePath = $modx->getOption('webpush_core_path', null, $modx->getOption('core_path') . 'components/webpush/');
if (!class_exists('WebPush')) {
    require_once $corePath . 'model/webpush/webpush.class.php';
}
$webPush = new WebPush($modx, ['corePath' => $corePath]);
$buttonText = isset($buttonText) ? $buttonText : 'Получать уведомления';
$unsubscribeText = isset($unsubscribeText) ? $unsubscribeText : 'Отключить уведомления';
$id = isset($id) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $id) : 'webpush-toggle';

$modx->regClientStartupScript($webPush->config['assetsUrl'] . 'js/webpush.js');
$config = [
    'publicKey' => $webPush->publicKey(),
    'connectorUrl' => $webPush->config['connectorUrl'],
    'serviceWorkerUrl' => '/webpush-sw.js',
    'buttonId' => $id,
    'subscribeText' => $buttonText,
    'unsubscribeText' => $unsubscribeText,
];
$modx->regClientStartupHTMLBlock('<script>window.MODXWebPushConfig=' . json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>');
return '<button type="button" id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" class="webpush-toggle">' . htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8') . '</button>';
