<?php
if (PHP_SAPI !== 'cli') { exit(1); }
$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Run composer install in core/components/webpush first.\n");
    exit(2);
}
require_once $autoload;
use Minishlink\WebPush\VAPID;
$keys = VAPID::createVapidKeys();
echo "webpush_vapid_public_key=" . $keys['publicKey'] . PHP_EOL;
echo "webpush_vapid_private_key=" . $keys['privateKey'] . PHP_EOL;
