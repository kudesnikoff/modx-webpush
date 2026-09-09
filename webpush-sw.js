'use strict';

function webPushSafeUrl(value, fallback) {
  try {
    var url = new URL(typeof value === 'string' ? value : fallback, self.location.origin);
    if ((url.protocol !== 'https:' && url.protocol !== 'http:') || url.origin !== self.location.origin) {
      return new URL(fallback, self.location.origin).href;
    }
    return url.href;
  } catch (e) {
    return new URL(fallback, self.location.origin).href;
  }
}

self.addEventListener('push', function (event) {
  var data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = {};
  }

  var options = {
    body: typeof data.body === 'string' ? data.body : '',
    icon: typeof data.icon === 'string' ? data.icon : '/favicon.ico',
    badge: typeof data.badge === 'string' ? data.badge : '/favicon.ico',
    image: typeof data.image === 'string' && data.image ? data.image : undefined,
    tag: typeof data.tag === 'string' && data.tag ? data.tag : undefined,
    renotify: false,
    data: {url: webPushSafeUrl(data.url, '/')}
  };

  var title = typeof data.title === 'string' && data.title ? data.title : 'Новое на сайте';
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var requested = event.notification.data && event.notification.data.url;
  var url = webPushSafeUrl(requested, '/');

  event.waitUntil(clients.matchAll({type: 'window', includeUncontrolled: true}).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      if ('focus' in list[i] && list[i].url === url) {
        return list[i].focus();
      }
    }
    return clients.openWindow ? clients.openWindow(url) : undefined;
  }));
});
