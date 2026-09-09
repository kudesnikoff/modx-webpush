(function () {
  'use strict';
  var cfg = window.MODXWebPushConfig || {};
  var button;

  function b64ToUint8(base64String) {
    var padding = '='.repeat((4 - base64String.length % 4) % 4);
    var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var raw = window.atob(base64);
    return Uint8Array.from(Array.prototype.map.call(raw, function (c) { return c.charCodeAt(0); }));
  }

  function request(action, payload) {
    var body = payload || {};
    body.csrfToken = cfg.csrfToken || '';

    return fetch(cfg.connectorUrl + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      redirect: 'error',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify(body)
    }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  }

  function updateButton(subscription) {
    if (!button) return;
    button.dataset.subscribed = subscription ? '1' : '0';
    button.textContent = subscription ? cfg.unsubscribeText : cfg.subscribeText;
  }

  function init() {
    button = document.getElementById(cfg.buttonId || 'webpush-toggle');
    if (!button || !('serviceWorker' in navigator) || !('PushManager' in window) || !cfg.publicKey || !cfg.csrfToken) {
      if (button) button.hidden = true;
      return;
    }

    navigator.serviceWorker.register(cfg.serviceWorkerUrl || '/webpush-sw.js').then(function (registration) {
      return registration.pushManager.getSubscription().then(function (subscription) {
        updateButton(subscription);
        button.addEventListener('click', function () {
          button.disabled = true;
          registration.pushManager.getSubscription().then(function (current) {
            if (current) {
              var endpoint = current.endpoint;
              return current.unsubscribe().then(function () {
                return request('unsubscribe', {endpoint: endpoint});
              }).then(function () { updateButton(null); });
            }

            return Notification.requestPermission().then(function (permission) {
              if (permission !== 'granted') throw new Error('Permission not granted');
              return registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: b64ToUint8(cfg.publicKey)
              });
            }).then(function (subscription) {
              var data = subscription.toJSON();
              if (PushManager.supportedContentEncodings && PushManager.supportedContentEncodings.length) {
                data.contentEncoding = PushManager.supportedContentEncodings[0];
              }
              return request('subscribe', data).then(function () { updateButton(subscription); });
            });
          }).catch(function (err) {
            console.error('[MODX WebPush]', err);
          }).finally(function () {
            button.disabled = false;
          });
        });
      });
    }).catch(function (err) { console.error('[MODX WebPush]', err); });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
