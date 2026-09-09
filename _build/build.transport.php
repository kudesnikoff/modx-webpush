<?php
$tstart = microtime(true);
$packageRoot = dirname(__DIR__) . '/';
$modxRoot = getenv('MODX_ROOT');
$modxRoot = $modxRoot ? rtrim($modxRoot, '/\\') . '/' : $packageRoot;

$configCore = $modxRoot . 'config.core.php';
if (!is_file($configCore)) {
    fwrite(STDERR, "MODX config.core.php not found. Set MODX_ROOT to an installed MODX root.\n");
    exit(2);
}

require_once $configCore;
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';

if (class_exists('\\MODX\\Revolution\\modX')) {
    $modx = new \MODX\Revolution\modX();
} else {
    $modx = new modX();
}
$modx->initialize('mgr');
$modx->setLogLevel(modX::LOG_LEVEL_INFO);
$modx->setLogTarget('ECHO');

$signature = 'webpush-0.1.0-beta2';
$builder = new xPDOTransport($modx, $signature, $packageRoot . '_build/');
$category = $modx->newObject('modCategory');
$category->set('category', 'WebPush');

$plugin = $modx->newObject('modPlugin');
$plugin->set('name', 'WebPush');
$plugin->set('plugincode', file_get_contents($packageRoot . 'core/components/webpush/elements/plugins/plugin.webpush.php'));
$events = [];
foreach (['OnBeforeDocFormSave', 'OnDocFormSave'] as $event) {
    $e = $modx->newObject('modPluginEvent');
    $e->set('event', $event);
    $e->set('priority', 0);
    $e->set('propertyset', 0);
    $events[] = $e;
}
$plugin->addMany($events);

$snippet = $modx->newObject('modSnippet');
$snippet->set('name', 'WebPushSubscribe');
$snippet->set('snippet', file_get_contents($packageRoot . 'core/components/webpush/elements/snippets/snippet.webpushsubscribe.php'));
$category->addMany([$plugin, $snippet]);

$vehicle = $builder->createVehicle($category, [
    xPDOTransport::PRESERVE_KEYS => false,
    xPDOTransport::UPDATE_OBJECT => true,
    xPDOTransport::UNIQUE_KEY => 'category',
    xPDOTransport::RELATED_OBJECTS => true,
]);
$vehicle->resolve('file', ['source' => $packageRoot . 'core/components/webpush/', 'target' => "return MODX_CORE_PATH . 'components/';"]);
$vehicle->resolve('file', ['source' => $packageRoot . 'assets/components/webpush/', 'target' => "return MODX_ASSETS_PATH . 'components/';"]);
$vehicle->resolve('php', ['source' => $packageRoot . '_build/resolvers/resolve.tables.php']);
$vehicle->resolve('php', ['source' => $packageRoot . '_build/resolvers/resolve.serviceworker.php']);
$vehicle->resolve('php', ['source' => $packageRoot . '_build/resolvers/resolve.tvs.php']);
$builder->putVehicle($vehicle);

$settings = [
    'webpush_vapid_public_key' => '',
    'webpush_vapid_private_key' => '',
    'webpush_vapid_subject' => 'mailto:admin@example.com',
    'webpush_templates' => '0',
    'webpush_notify_pages' => '1',
    'webpush_notify_products' => '1',
    'webpush_image_tv' => 'image',
    'webpush_body_length' => '180',
    'webpush_icon' => '/favicon.ico',
    'webpush_badge' => '/favicon.ico',
    'webpush_jobs_per_run' => '5',
    'webpush_batch_size' => '500',
    'webpush_max_attempts' => '5',
    'webpush_lock_timeout_minutes' => '30',
];
foreach ($settings as $key => $value) {
    $setting = $modx->newObject('modSystemSetting');
    $setting->set('key', $key);
    $setting->set('value', $value);
    $setting->set('namespace', 'webpush');
    $setting->set('area', 'webpush_main');
    $v = $builder->createVehicle($setting, [
        xPDOTransport::PRESERVE_KEYS => true,
        xPDOTransport::UPDATE_OBJECT => false,
        xPDOTransport::UNIQUE_KEY => 'key'
    ]);
    $builder->putVehicle($v);
}

$namespace = $modx->newObject('modNamespace');
$namespace->set('name', 'webpush');
$namespace->set('path', '{core_path}components/webpush/');
$builder->putVehicle($builder->createVehicle($namespace, [
    xPDOTransport::PRESERVE_KEYS => true,
    xPDOTransport::UPDATE_OBJECT => true,
    xPDOTransport::UNIQUE_KEY => 'name'
]));

if (!$builder->pack()) {
    fwrite(STDERR, "Transport package build failed.\n");
    exit(1);
}

echo "Built {$signature} in " . round(microtime(true) - $tstart, 2) . " sec\n";
