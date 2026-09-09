<?php
/**
 * WebPush plugin for MODX Revolution 2.x / 3.x.
 * Events: OnBeforeDocFormSave, OnDocFormSave
 */
$eventName = $modx->event->name;
if (!in_array($eventName, ['OnBeforeDocFormSave', 'OnDocFormSave'], true) || !isset($resource) || !is_object($resource)) {
    return;
}

$corePath = $modx->getOption('webpush_core_path', null, $modx->getOption('core_path') . 'components/webpush/');
if (!class_exists('WebPush')) {
    require_once $corePath . 'model/webpush/webpush.class.php';
}
$webPush = new WebPush($modx, ['corePath' => $corePath]);

if (!$webPush->isTemplateEnabled($resource->get('template'))) {
    return;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
if (!isset($_SESSION['webpush_publish_state']) || !is_array($_SESSION['webpush_publish_state'])) {
    $_SESSION['webpush_publish_state'] = [];
}

$id = (int)$resource->get('id');
$key = 'resource_' . $id;

if ($eventName === 'OnBeforeDocFormSave') {
    $wasPublished = false;
    if ($id > 0) {
        $old = $modx->getObject('modResource', $id);
        if ($old) {
            $wasPublished = (bool)$old->get('published');
        }
    }
    $_SESSION['webpush_publish_state'][$key] = [
        'was_published' => $wasPublished,
        'time' => time(),
    ];
    return;
}

$published = (bool)$resource->get('published');
$state = isset($_SESSION['webpush_publish_state'][$key]) && is_array($_SESSION['webpush_publish_state'][$key])
    ? $_SESSION['webpush_publish_state'][$key]
    : [];
unset($_SESSION['webpush_publish_state'][$key]);
$wasPublished = !empty($state['was_published']);

// Only first publication: new+published or unpublished -> published.
if (!$published || $wasPublished) {
    return;
}

$kind = $webPush->resourceKind($resource);
if (!$webPush->shouldNotifyKind($kind)) {
    return;
}

$readTv = static function ($resourceObject, $name, $default = '') {
    if (!method_exists($resourceObject, 'getTVValue')) {
        return $default;
    }
    try {
        $value = $resourceObject->getTVValue($name);
        return $value === null ? $default : (string)$value;
    } catch (Throwable $e) {
        return $default;
    }
};

$enabled = strtolower(trim($readTv($resource, 'webpush_enabled', '1')));
if (in_array($enabled, ['0', 'no', 'false', 'off'], true)) {
    return;
}

$override = [
    'title' => trim($readTv($resource, 'webpush_title', '')),
    'body' => trim($readTv($resource, 'webpush_body', '')),
    'image' => trim($readTv($resource, 'webpush_image', '')),
];

$webPush->enqueueResource($resource, $kind, $override);
