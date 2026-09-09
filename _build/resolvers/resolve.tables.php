<?php
if ($object->xpdo) {
    $modx =& $object->xpdo;
    switch ($options[xPDOTransport::PACKAGE_ACTION]) {
        case xPDOTransport::ACTION_INSTALL:
        case xPDOTransport::ACTION_UPGRADE:
            $prefix = $modx->getOption('table_prefix', null, 'modx_');
            $subscriptions = $prefix . 'webpush_subscriptions';
            $queue = $prefix . 'webpush_queue';

            $modx->exec("CREATE TABLE IF NOT EXISTS `{$subscriptions}` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `endpoint` text NOT NULL,
              `endpoint_hash` char(64) NOT NULL,
              `p256dh` varchar(255) NOT NULL,
              `auth` varchar(255) NOT NULL,
              `content_encoding` varchar(32) NOT NULL DEFAULT 'aes128gcm',
              `user_id` int unsigned NOT NULL DEFAULT 0,
              `user_agent` varchar(500) NOT NULL DEFAULT '',
              `active` tinyint(1) NOT NULL DEFAULT 1,
              `fail_count` int unsigned NOT NULL DEFAULT 0,
              `last_success` datetime NULL,
              `created_at` datetime NOT NULL,
              `updated_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `endpoint_hash` (`endpoint_hash`),
              KEY `active_id` (`active`,`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $modx->exec("CREATE TABLE IF NOT EXISTS `{$queue}` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `resource_id` int unsigned NOT NULL,
              `kind` varchar(32) NOT NULL DEFAULT 'page',
              `title` varchar(255) NOT NULL,
              `body` text NOT NULL,
              `url` text NOT NULL,
              `image` text NOT NULL,
              `tag` varchar(255) NOT NULL DEFAULT '',
              `status` enum('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
              `attempts` int unsigned NOT NULL DEFAULT 0,
              `last_error` text NULL,
              `locked_at` datetime NULL,
              `created_at` datetime NOT NULL,
              `sent_at` datetime NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `resource_id` (`resource_id`),
              KEY `status_attempts` (`status`,`attempts`,`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Upgrade an early beta table in-place when it already exists.
            try {
                $columns = $modx->query("SHOW COLUMNS FROM `{$queue}` LIKE 'locked_at'");
                if ($columns && !$columns->fetch(PDO::FETCH_ASSOC)) {
                    $modx->exec("ALTER TABLE `{$queue}` ADD `locked_at` datetime NULL AFTER `last_error`");
                }
            } catch (Throwable $e) {
                // Fresh installs already contain the column.
            }
            try {
                $modx->exec("ALTER TABLE `{$queue}` MODIFY `status` enum('pending','processing','sent','failed') NOT NULL DEFAULT 'pending'");
            } catch (Throwable $e) {
                // Keep installation resilient across MySQL/MariaDB variants.
            }
            break;
    }
}
return true;
