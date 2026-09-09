<?php
require_once dirname(__DIR__) . '/core/components/webpush/model/webpush/webpush.class.php';

$failures = [];
$check = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$validEndpoint = 'https://fcm.googleapis.com/fcm/send/example-token';
$check(WebPush::validateEndpoint($validEndpoint) === true, 'Expected public HTTPS push endpoint to be accepted');

$invalidEndpoints = [
    'http://fcm.googleapis.com/fcm/send/token',
    'https://localhost/push',
    'https://internal.local/push',
    'https://127.0.0.1/push',
    'https://10.0.0.1/push',
    'https://169.254.169.254/latest/meta-data/',
    'https://[::1]/push',
    'file:///etc/passwd',
    'javascript:alert(1)',
    'https://user:pass@example.com/push',
    'https://example.com/push#fragment',
];
foreach ($invalidEndpoints as $endpoint) {
    $check(WebPush::validateEndpoint($endpoint) === false, 'Unsafe endpoint accepted: ' . $endpoint);
}

$check(WebPush::validateBase64Url(str_repeat('A', 87), 32, 255) === true, 'Valid base64url key rejected');
$check(WebPush::validateBase64Url('<script>alert(1)</script>', 1, 255) === false, 'Invalid key characters accepted');

$dirty = " <b>Hello</b>\n<script>alert(1)</script> World\x00 ";
$clean = WebPush::cleanText($dirty, 100);
$check(strpos($clean, '<') === false, 'HTML survived cleanText');
$check(strpos($clean, "\x00") === false, 'Control byte survived cleanText');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Security smoke tests passed\n";
