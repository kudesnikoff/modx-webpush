<?php

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush as MinishlinkWebPush;

class WebPush
{
    /** @var object */
    public $modx;
    /** @var array */
    public $config = [];

    public function __construct(&$modx, array $config = [])
    {
        $this->modx =& $modx;
        $corePath = $this->getOption('webpush_core_path', $config, $this->getOption('core_path') . 'components/webpush/');
        $assetsUrl = $this->getOption('webpush_assets_url', $config, $this->getOption('assets_url') . 'components/webpush/');
        $this->config = array_merge([
            'corePath' => $corePath,
            'assetsUrl' => $assetsUrl,
            'connectorUrl' => $assetsUrl . 'connector.php',
            'tablePrefix' => $this->getOption('table_prefix', null, 'modx_'),
        ], $config);

        $autoloaders = [
            $corePath . 'vendor/autoload.php',
            dirname(dirname(dirname(dirname($corePath)))) . '/vendor/autoload.php',
        ];
        foreach ($autoloaders as $autoload) {
            if (is_file($autoload)) {
                require_once $autoload;
                break;
            }
        }
    }

    public function getOption($key, $options = null, $default = null)
    {
        return $this->modx->getOption($key, $options, $default);
    }

    public function isReady()
    {
        return class_exists(MinishlinkWebPush::class)
            && trim((string)$this->getOption('webpush_vapid_public_key')) !== ''
            && trim((string)$this->getOption('webpush_vapid_private_key')) !== '';
    }

    public function table($name)
    {
        if (!in_array($name, ['subscriptions', 'queue'], true)) {
            throw new InvalidArgumentException('Unknown WebPush table.');
        }
        return $this->config['tablePrefix'] . 'webpush_' . $name;
    }

    public function publicKey()
    {
        return trim((string)$this->getOption('webpush_vapid_public_key'));
    }

    public static function validateEndpoint($endpoint)
    {
        $endpoint = trim((string)$endpoint);
        if ($endpoint === '' || strlen($endpoint) > 2048 || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($endpoint);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            return false;
        }

        $host = strtolower(rtrim((string)$parts['host'], '.'));
        if ($host === 'localhost' || substr($host, -6) === '.local') {
            return false;
        }

        // IP literals are allowed only when globally routable. Hostnames are resolved
        // by the HTTP client later; the same-origin+CSRF connector prevents arbitrary
        // third-party endpoint injection while this rejects the obvious SSRF cases.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $publicIp = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if ($publicIp === false) {
                return false;
            }
        }

        return true;
    }

    public static function validateBase64Url($value, $minLength = 16, $maxLength = 255)
    {
        $value = (string)$value;
        $length = strlen($value);
        return $length >= $minLength
            && $length <= $maxLength
            && preg_match('/^[A-Za-z0-9_-]+$/D', $value) === 1;
    }

    public static function cleanText($value, $maxLength = 0)
    {
        $value = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        $value = preg_replace('/\s+/u', ' ', trim((string)$value));
        if ($maxLength > 0) {
            if (function_exists('mb_substr')) {
                $value = mb_substr($value, 0, $maxLength, 'UTF-8');
            } else {
                $value = substr($value, 0, $maxLength);
            }
        }
        return $value;
    }

    public function normalizeHttpUrl($value, $allowExternal = true)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        $siteUrl = rtrim((string)$this->getOption('site_url'), '/');
        if (strpos($value, '//') === 0) {
            $value = 'https:' . $value;
        } elseif (filter_var($value, FILTER_VALIDATE_URL) === false) {
            $value = $siteUrl . '/' . ltrim($value, '/');
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        $parts = parse_url($value);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        if (!$allowExternal) {
            $site = parse_url($siteUrl);
            if (!is_array($site) || strtolower((string)($site['host'] ?? '')) !== strtolower((string)$parts['host'])) {
                return '';
            }
        }
        return $value;
    }

    public function subscribe(array $data)
    {
        $endpoint = trim((string)($data['endpoint'] ?? ''));
        $keys = isset($data['keys']) && is_array($data['keys']) ? $data['keys'] : [];
        $p256dh = trim((string)($keys['p256dh'] ?? ''));
        $auth = trim((string)($keys['auth'] ?? ''));
        if (!self::validateEndpoint($endpoint)
            || !self::validateBase64Url($p256dh, 32, 255)
            || !self::validateBase64Url($auth, 16, 255)) {
            throw new InvalidArgumentException('Invalid PushSubscription payload.');
        }

        $contentEncoding = trim((string)($data['contentEncoding'] ?? 'aes128gcm')) ?: 'aes128gcm';
        if (!in_array($contentEncoding, ['aes128gcm', 'aesgcm'], true)) {
            throw new InvalidArgumentException('Unsupported content encoding.');
        }

        $userAgent = self::cleanText((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 500);
        $userId = 0;
        if (isset($this->modx->user) && is_object($this->modx->user) && method_exists($this->modx->user, 'get')) {
            $userId = (int)$this->modx->user->get('id');
        }

        $sql = 'INSERT INTO `' . $this->table('subscriptions') . '` '
             . '(`endpoint`,`endpoint_hash`,`p256dh`,`auth`,`content_encoding`,`user_id`,`user_agent`,`active`,`fail_count`,`created_at`,`updated_at`) '
             . 'VALUES (:endpoint,:endpoint_hash,:p256dh,:auth,:encoding,:user_id,:user_agent,1,0,NOW(),NOW()) '
             . 'ON DUPLICATE KEY UPDATE `endpoint`=VALUES(`endpoint`),`p256dh`=VALUES(`p256dh`),`auth`=VALUES(`auth`),'
             . '`content_encoding`=VALUES(`content_encoding`),`user_id`=VALUES(`user_id`),'
             . '`user_agent`=VALUES(`user_agent`),`active`=1,`fail_count`=0,`updated_at`=NOW()';
        $stmt = $this->modx->prepare($sql);
        return $stmt->execute([
            ':endpoint' => $endpoint,
            ':endpoint_hash' => hash('sha256', $endpoint),
            ':p256dh' => $p256dh,
            ':auth' => $auth,
            ':encoding' => $contentEncoding,
            ':user_id' => $userId,
            ':user_agent' => $userAgent,
        ]);
    }

    public function unsubscribe($endpoint)
    {
        $endpoint = trim((string)$endpoint);
        if (!self::validateEndpoint($endpoint)) {
            throw new InvalidArgumentException('Invalid endpoint.');
        }
        $stmt = $this->modx->prepare('UPDATE `' . $this->table('subscriptions') . '` SET `active`=0,`updated_at`=NOW() WHERE `endpoint_hash`=:hash');
        return $stmt->execute([':hash' => hash('sha256', $endpoint)]);
    }

    public function enqueueResource($resource, $kind = 'page', array $override = [])
    {
        $id = (int)$resource->get('id');
        if ($id <= 0) {
            return false;
        }

        $title = self::cleanText($override['title'] ?? '', 120);
        if ($title === '') {
            $title = self::cleanText($resource->get('pagetitle'), 120);
        }

        $body = self::cleanText($override['body'] ?? '');
        if ($body === '') {
            $body = self::cleanText($resource->get('description'));
        }
        if ($body === '') {
            $body = self::cleanText($resource->get('introtext'));
        }
        if ($body === '') {
            $body = self::cleanText($resource->get('content'));
        }
        $max = max(40, min(500, (int)$this->getOption('webpush_body_length', null, 180)));
        $body = self::cleanText($body, $max);

        $url = $this->normalizeHttpUrl(
            $this->modx->makeUrl($id, (string)$resource->get('context_key'), '', 'full'),
            false
        );
        if ($url === '') {
            return false;
        }

        $imageInput = trim((string)($override['image'] ?? ''));
        $image = $this->normalizeHttpUrl($imageInput !== '' ? $imageInput : $this->resolveResourceImage($resource), true);
        $tag = 'resource-' . $id;
        $kind = $kind === 'product' ? 'product' : 'page';

        $sql = 'INSERT INTO `' . $this->table('queue') . '` '
             . '(`resource_id`,`kind`,`title`,`body`,`url`,`image`,`tag`,`status`,`attempts`,`created_at`) '
             . 'VALUES (:resource_id,:kind,:title,:body,:url,:image,:tag,"pending",0,NOW()) '
             . 'ON DUPLICATE KEY UPDATE `resource_id`=`resource_id`';
        $stmt = $this->modx->prepare($sql);
        return $stmt->execute([
            ':resource_id' => $id,
            ':kind' => $kind,
            ':title' => $title,
            ':body' => $body,
            ':url' => $url,
            ':image' => $image,
            ':tag' => $tag,
        ]);
    }

    public function resolveResourceImage($resource)
    {
        $tv = trim((string)$this->getOption('webpush_image_tv', null, 'image'));
        $image = '';
        if ($tv !== '' && method_exists($resource, 'getTVValue')) {
            try {
                $image = trim((string)$resource->getTVValue($tv));
            } catch (Throwable $e) {
                $image = '';
            }
        }

        if ($image === '' && method_exists($resource, 'get')) {
            $image = trim((string)$resource->get('image'));
        }

        // miniShop2 normally keeps the primary image in the related product Data object.
        if ($image === '' && $this->resourceKind($resource) === 'product' && method_exists($resource, 'getOne')) {
            try {
                $data = $resource->getOne('Data');
                if ($data && method_exists($data, 'get')) {
                    $image = trim((string)$data->get('image'));
                    if ($image === '') {
                        $image = trim((string)$data->get('thumb'));
                    }
                }
            } catch (Throwable $e) {
                $image = '';
            }
        }

        return $this->normalizeHttpUrl($image, true);
    }

    public function isTemplateEnabled($template)
    {
        $raw = trim((string)$this->getOption('webpush_templates', null, '0'));
        if ($raw === '' || $raw === '0' || $raw === '*') {
            return true;
        }
        $ids = array_filter(array_map('intval', preg_split('/\s*,\s*/', $raw)));
        return in_array((int)$template, $ids, true);
    }

    public function resourceKind($resource)
    {
        $classKey = (string)$resource->get('class_key');
        $lower = strtolower($classKey);
        if ($lower === 'msproduct' || substr($lower, -10) === '\\msproduct') {
            return 'product';
        }
        return 'page';
    }

    public function shouldNotifyKind($kind)
    {
        if ($kind === 'product') {
            return (bool)$this->getOption('webpush_notify_products', null, true);
        }
        return (bool)$this->getOption('webpush_notify_pages', null, true);
    }

    public function processQueue($limit = 5, $batchSize = 500)
    {
        if (!$this->isReady()) {
            throw new RuntimeException('WebPush is not configured or Composer dependencies are missing.');
        }

        $limit = max(1, min(100, (int)$limit));
        $batchSize = max(10, min(2000, (int)$batchSize));
        $maxAttempts = max(1, min(20, (int)$this->getOption('webpush_max_attempts', null, 5)));
        $staleMinutes = max(5, min(1440, (int)$this->getOption('webpush_lock_timeout_minutes', null, 30)));

        // Recover jobs left in processing state after a killed worker.
        $this->modx->exec(
            'UPDATE `' . $this->table('queue') . '` SET `status`="pending",`locked_at`=NULL '
            . 'WHERE `status`="processing" AND `locked_at` IS NOT NULL '
            . 'AND `locked_at` < DATE_SUB(NOW(), INTERVAL ' . $staleMinutes . ' MINUTE)'
        );

        $stmt = $this->modx->prepare(
            'SELECT * FROM `' . $this->table('queue') . '` '
            . 'WHERE `status`="pending" AND `attempts` < :max_attempts ORDER BY `id` ASC LIMIT ' . $limit
        );
        $stmt->bindValue(':max_attempts', $maxAttempts, PDO::PARAM_INT);
        $stmt->execute();
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = ['jobs' => 0, 'sent' => 0, 'failed' => 0, 'expired' => 0];
        foreach ($jobs as $job) {
            if (!$this->claimQueue((int)$job['id'])) {
                continue; // Another cron worker claimed it first.
            }

            $summary['jobs']++;
            try {
                $result = $this->sendJob($job, $batchSize);
                foreach (['sent', 'failed', 'expired'] as $key) {
                    $summary[$key] += $result[$key];
                }
                $this->completeQueue((int)$job['id']);
            } catch (Throwable $e) {
                $this->failQueue((int)$job['id'], $e->getMessage(), $maxAttempts);
                $this->log('Queue job #' . (int)$job['id'] . ' failed: ' . $e->getMessage());
            }
        }
        return $summary;
    }

    protected function claimQueue($id)
    {
        $stmt = $this->modx->prepare(
            'UPDATE `' . $this->table('queue') . '` SET `status`="processing",`locked_at`=NOW() '
            . 'WHERE `id`=:id AND `status`="pending"'
        );
        $stmt->execute([':id' => (int)$id]);
        return $stmt->rowCount() === 1;
    }

    protected function completeQueue($id)
    {
        $stmt = $this->modx->prepare(
            'UPDATE `' . $this->table('queue') . '` SET `status`="sent",`locked_at`=NULL,`last_error`=NULL,`sent_at`=NOW() WHERE `id`=:id'
        );
        return $stmt->execute([':id' => (int)$id]);
    }

    protected function failQueue($id, $error, $maxAttempts)
    {
        $error = self::cleanText($error, 1000);
        $stmt = $this->modx->prepare(
            'UPDATE `' . $this->table('queue') . '` SET '
            . '`attempts`=`attempts`+1,'
            . '`status`=IF(`attempts`+1 >= :max_attempts,"failed","pending"),'
            . '`locked_at`=NULL,`last_error`=:error WHERE `id`=:id'
        );
        return $stmt->execute([
            ':max_attempts' => (int)$maxAttempts,
            ':error' => $error,
            ':id' => (int)$id,
        ]);
    }

    protected function sendJob(array $job, $batchSize)
    {
        $auth = [
            'VAPID' => [
                'subject' => trim((string)$this->getOption('webpush_vapid_subject', null, 'mailto:admin@example.com')),
                'publicKey' => $this->publicKey(),
                'privateKey' => trim((string)$this->getOption('webpush_vapid_private_key')),
            ],
        ];
        $webPush = new MinishlinkWebPush($auth);
        $payload = json_encode([
            'title' => (string)$job['title'],
            'body' => (string)$job['body'],
            'url' => (string)$job['url'],
            'image' => (string)$job['image'],
            'icon' => $this->normalizeHttpUrl((string)$this->getOption('webpush_icon', null, '/favicon.ico'), true),
            'badge' => $this->normalizeHttpUrl((string)$this->getOption('webpush_badge', null, '/favicon.ico'), true),
            'tag' => (string)$job['tag'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            throw new RuntimeException('Unable to encode push payload.');
        }

        $stats = ['sent' => 0, 'failed' => 0, 'expired' => 0];
        $lastId = 0;
        $batchSize = max(10, min(2000, (int)$batchSize));

        do {
            $sql = 'SELECT * FROM `' . $this->table('subscriptions') . '` '
                 . 'WHERE `active`=1 AND `id` > :last_id ORDER BY `id` ASC LIMIT ' . $batchSize;
            $stmt = $this->modx->prepare($sql);
            $stmt->bindValue(':last_id', $lastId, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $lastId = max($lastId, (int)$row['id']);
                $subscription = Subscription::create([
                    'endpoint' => $row['endpoint'],
                    'publicKey' => $row['p256dh'],
                    'authToken' => $row['auth'],
                    'contentEncoding' => $row['content_encoding'] ?: 'aes128gcm',
                ]);
                $webPush->queueNotification($subscription, $payload);
            }

            foreach ($webPush->flush() as $report) {
                $endpoint = (string)$report->getRequest()->getUri();
                if ($report->isSuccess()) {
                    $stats['sent']++;
                    $this->markSubscriptionSuccess($endpoint);
                } else {
                    $stats['failed']++;
                    $expired = method_exists($report, 'isSubscriptionExpired') && $report->isSubscriptionExpired();
                    if ($expired) {
                        $stats['expired']++;
                        $this->unsubscribe($endpoint);
                    } else {
                        $this->markSubscriptionFailure($endpoint);
                    }
                }
            }
        } while (count($rows) === $batchSize);

        return $stats;
    }

    protected function markSubscriptionSuccess($endpoint)
    {
        $stmt = $this->modx->prepare(
            'UPDATE `' . $this->table('subscriptions') . '` SET `last_success`=NOW(),`fail_count`=0,`updated_at`=NOW() WHERE `endpoint_hash`=:hash'
        );
        $stmt->execute([':hash' => hash('sha256', (string)$endpoint)]);
    }

    protected function markSubscriptionFailure($endpoint)
    {
        $stmt = $this->modx->prepare(
            'UPDATE `' . $this->table('subscriptions') . '` SET `fail_count`=`fail_count`+1,`updated_at`=NOW() WHERE `endpoint_hash`=:hash'
        );
        $stmt->execute([':hash' => hash('sha256', (string)$endpoint)]);
    }

    protected function log($message)
    {
        if (method_exists($this->modx, 'log')) {
            $level = defined('modX::LOG_LEVEL_ERROR') ? constant('modX::LOG_LEVEL_ERROR') : 1;
            $this->modx->log($level, '[WebPush] ' . self::cleanText($message, 1500));
        }
    }
}
