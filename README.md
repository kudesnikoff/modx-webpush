# MODX WebPush

Self-hosted browser Web Push notifications for **MODX Revolution 2.x / 3.x**, with automatic notification when a MODX page or miniShop product is published for the first time.

No Sendsay, PushAll, OneSignal or similar marketing intermediary is required. Delivery uses the browser vendors' standard Web Push endpoints.

## Status

`0.1.0-beta1` — MVP intended for testing before production rollout.

## Requirements

- HTTPS site (required by Service Worker / Push API)
- MODX Revolution 2.x or 3.x
- PHP 8.1+
- Composer
- PHP extensions: curl, json, mbstring, openssl
- MySQL/MariaDB

Minishlink compatibility is selected by Composer:
- PHP 8.1 -> `minishlink/web-push` 9.x
- PHP 8.2+ -> current compatible 10/11.x

## What it does

- Stores browser PushSubscription data in your own MODX database.
- VAPID keys stay on your server.
- Adds a `WebPushSubscribe` frontend snippet.
- Detects first publication of a MODX resource using `OnBeforeDocFormSave` + `OnDocFormSave`.
- Recognises miniShop products by `class_key` (`msProduct` and namespaced `...\\msProduct`).
- Queues notifications instead of sending them during Manager save.
- Cron worker sends notifications in batches and disables expired subscriptions.
- Supports separate switches for pages and products and optional template filtering.

## Install from source

1. Copy `core/components/webpush` to your MODX core components directory.
2. Copy `assets/components/webpush` to your MODX assets components directory.
3. Copy `webpush-sw.js` to the site document root (`/webpush-sw.js`).
4. In `core/components/webpush` run:

```bash
composer install --no-dev --optimize-autoloader
```

5. Create the two tables using SQL from `_build/resolvers/resolve.tables.php` or build/install the MODX transport package.
6. Create a MODX plugin named `WebPush`, attach `OnBeforeDocFormSave` and `OnDocFormSave`, and use `elements/plugins/plugin.webpush.php` as its code.
7. Create snippet `WebPushSubscribe` using `elements/snippets/snippet.webpushsubscribe.php`.
8. Generate VAPID keys:

```bash
php core/components/webpush/cli/generate-vapid.php
```

9. Add the generated keys to MODX system settings:
   - `webpush_vapid_public_key`
   - `webpush_vapid_private_key`
   - `webpush_vapid_subject` (e.g. `mailto:admin@example.com`)
10. Put `[[WebPushSubscribe]]` on the desired page.
11. Add cron (example every minute):

```cron
* * * * * /usr/bin/php /path/to/modx/core/components/webpush/cli/worker.php /path/to/modx >/dev/null 2>&1
```

## Main MODX settings

| Setting | Default | Meaning |
|---|---:|---|
| `webpush_notify_products` | `1` | Queue new miniShop products |
| `webpush_notify_pages` | `1` | Queue ordinary MODX pages |
| `webpush_templates` | `0` | `0`/`*` = all templates; or comma-separated template IDs |
| `webpush_image_tv` | `image` | TV used as notification image |
| `webpush_body_length` | `180` | Max notification body length |
| `webpush_jobs_per_run` | `5` | Queue jobs processed per cron run |
| `webpush_batch_size` | `500` | Subscribers fetched per batch |

## miniShop2 / MiniShop3

miniShop2 products are MODX resources (`class_key=msProduct`), so publication is covered by the same resource events. The code also recognises namespaced class keys ending with `\\msProduct`, which is intended to ease MODX 3 / MiniShop3 compatibility.

The current beta does **not** yet send order/admin notifications; the uploaded legacy PushAll extra used `msOnCreateOrder`, but this project initially focuses on customer-facing notifications for new products and pages.

## Security / privacy

Push endpoints and subscription keys are stored in your own database. Treat them as application data, limit database access, use HTTPS, and provide users with a way to unsubscribe.

## License

MIT. `minishlink/web-push` is also MIT and remains a Composer dependency.
