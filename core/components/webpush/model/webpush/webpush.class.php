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
        return $this->config['tablePrefix'] . 'webpush_' . $name;
    }

    public function publicKey()
    {
        return trim((string)$this->getOption('webpush_vapid_public_key'));
    }

    public function subscribe(array $data)
    {
        $endpoint = trim((string)($data['endpoint'] ?? ''));
        $keys = isset($data['keys']) && is_array($data['keys']) ? $data['keys'] : [];
        $p256dh = trim((string)($keys['p256dh'] ?? ''));
        $auth = trim((string)($keys['auth'] ?? ''));
        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            throw new InvalidArgumentException('Invalid PushSubscription payload.');
        }

        $contentEncoding = trim((string)($data['contentEncoding'] ?? 'aes128gcm')) ?: 'aes128gcm';
        $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        $userId = 0;
        if (isset($this->modx->user) && is_object($this->modx->user) && method_exists($this->modx->user, 'get')) {
            $userId = (int)$this->modx->user->get('id');
        }

        $sql = 'INSERT INTO `' . $this->table('subscriptions') . '` '
             . '(`endpoint`,`endpoint_hash`,`p256dh`,`auth`,`content_encoding`,`user_id`,`user_agent`,`active`,`fail_count`,`created_at`,`updated_at`) '
             . 'VALUES (:endpoint,:endpoint_hash,:p256dh,:auth,:encoding,:user_id,:user_agent,1,0,NOW(),NOW()) '
             . 'ON DUPLICATE KEY UPDATE `p256dh`=VALUES(`p256dh`),`auth`=VALUES(`auth`),'
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
        $stmt = $this->modx->prepare('UPDATE `' . $this->table('subscriptions') . '` SET `active`=0,`updated_at`=NOW() WHERE `endpoint`=:endpoint');
        return $stmt->execute([':endpoint' => (string)$endpoint]);
    }

    public function enqueueResource($resource, $kind = 'page', array $override = [])
    {
        $id = (int)$resource->get('id');
        if ($id <= 0) {
            return false;
        }
        $title = trim((string)($override['title'] ?? ''));
        if ($title === '') {
            $title = trim((string)$resource->get('pagetitle'));
        }
        $body = trim((string)($override['body'] ?? ''));
        if ($body === '') {
            $body = trim((string)$resource->get('description'));
        }
        if ($body === '') {
            $body = trim((string)$resource->get('introtext'));
        }
        if ($body === '') {
            $body = trim(strip_tags((string)$resource->get('content')));
        }
        $max = (int)$this->getOption('webpush_body_length', null, 180);
        if ($max > 0 && function_exists('mb_substr')) {
            $body = mb_substr($body, 0, $max);
        }
        $url = $this->modx->makeUrl($id, (string)$resource->get('context_key'), '', 'full');
        $image = trim((string)($override['image'] ?? $this->resolveResourceImage($resource)));
        $tag = 'resource-' . $id;

        $sql = 'INSERT INTO `' . $this->table('queue') . '` '
             . '(`resource_id`,`kind`,`title`,`body`,`url`,`image`,`tag`,`status`,`attempts`,`created_at`) '
             . 'VALUES (:resource_id,:kind,:title,:body,:url,:image,:tag,"pending",0,NOW()) '
             . 'ON DUPLICATE KEY UPDATE `title`=VALUES(`title`),`body`=VALUES(`body`),`url`=VALUES(`url`),`image`=VALUES(`image`)';
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
        if ($image !== '' && strpos($image, 'http://') !== 0 && strpos($image, 'https://') !== 0) {
            $image = rtrim((string)$this->getOption('site_url'), '/') . '/' . ltrim($image, '/');
        }
        return $image;
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
        $limit = max(1, (int)$limit);
        $stmt = $this->modx->prepare('SELECT * FROM `' . $this->table('queue') . '` WHERE `status`="pending" ORDER BY `id` ASC LIMIT ' . $limit);
        $stmt->execute();
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $summary = ['jobs' => 0, 'sent' => 0, 'failed' => 0, 'expired' => 0];
        foreach ($jobs as $job) {
            $summary['jobs']++;
            $this->markQueue($job['id'], 'processing');
            try {
                $result = $this->sendJob($job, $batchSize);
                foreach (['sent', 'failed', 'expired'] as $k) {
                    $summary[$k] += $result[$k];
                }
                $this->markQueue($job['id'], 'sent', null);
            } catch (Throwable $e) {
                $this->markQueue($job['id'], 'pending', $e->getMessage(), true);
                $this->log('Queue job #' . $job['id'] . ' failed: ' . $e->getMessage());
            }
        }
        return $summary;
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
            'icon' => (string)$this->getOption('webpush_icon', null, '/favicon.ico'),
            'badge' => (string)$this->getOption('webpush_badge', null, '/favicon.ico'),
            'tag' => (string)$job['tag'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stats = ['sent' => 0, 'failed' => 0, 'expired' => 0];
        $offset = 0;
        $batchSize = max(10, (int)$batchSize);
        do {
            $sql = 'SELECT * FROM `' . $this->table('subscriptions') . '` WHERE `active`=1 ORDER BY `id` ASC LIMIT ' . $batchSize . ' OFFSET ' . $offset;
            $stmt = $this->modx->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
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
            $offset += count($rows);
        } while (count($rows) === $batchSize);

        return $stats;
    }

    protected function markQueue($id, $status, $error = null, $incrementAttempts = false)
    {
        $sql = 'UPDATE `' . $this->table('queue') . '` SET `status`=:status,`last_error`=:error,'
             . ($incrementAttempts ? '`attempts`=`attempts`+1,' : '')
             . '`sent_at`=' . ($status === 'sent' ? 'NOW()' : '`sent_at`') . ' WHERE `id`=:id';
        $stmt = $this->modx->prepare($sql);
        return $stmt->execute([':status' => $status, ':error' => $error, ':id' => (int)$id]);
    }

    protected function markSubscriptionSuccess($endpoint)
    {
        $stmt = $this->modx->prepare('UPDATE `' . $this->table('subscriptions') . '` SET `last_success`=NOW(),`fail_count`=0,`updated_at`=NOW() WHERE `endpoint`=:endpoint');
        $stmt->execute([':endpoint' => $endpoint]);
    }

    protected function markSubscriptionFailure($endpoint)
    {
        $stmt = $this->modx->prepare('UPDATE `' . $this->table('subscriptions') . '` SET `fail_count`=`fail_count`+1,`updated_at`=NOW() WHERE `endpoint`=:endpoint');
        $stmt->execute([':endpoint' => $endpoint]);
    }

    protected function log($message)
    {
        if (method_exists($this->modx, 'log')) {
            $level = defined('modX::LOG_LEVEL_ERROR') ? constant('modX::LOG_LEVEL_ERROR') : 1;
            $this->modx->log($level, '[WebPush] ' . $message);
        }
    }
}
