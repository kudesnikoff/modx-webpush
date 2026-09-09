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

if (!isset($_SESSION['webpush'])) {
    $_SESSION['webpush'] = [];
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
    $_SESSION['webpush'][$key] = ['was_published' => $wasPublished];
    return;
}

$published = (bool)$resource->get('published');
$wasPublished = !empty($_SESSION['webpush'][$key]['was_published']);
unset($_SESSION['webpush'][$key]);

// Only first publication: new+published or unpublished -> published.
if (!$published || $wasPublished) {
    return;
}

$kind = $webPush->resourceKind($resource);
if (!$webPush->shouldNotifyKind($kind)) {
    return;
}

$readTv = static function ($resource, $name, $default = '') {
    if (!method_exists($resource, 'getTVValue')) {
        return $default;
    }
    try {
        $value = $resource->getTVValue($name);
        return $value === null ? $default : (string)$value;
    } catch (Throwable $e) {
        return $default;
    }
};

$enabled = trim($readTv($resource, 'webpush_enabled', '1'));
if ($enabled === '0' || strtolower($enabled) === 'no' || strtolower($enabled) === 'false') {
    return;
}

$override = [
    'title' => trim($readTv($resource, 'webpush_title', '')),
    'body' => trim($readTv($resource, 'webpush_body', '')),
    'image' => trim($readTv($resource, 'webpush_image', '')),
];

$webPush->enqueueResource($resource, $kind, $override);
