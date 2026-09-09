self.addEventListener('push', function (event) {
  var data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) {}
  var options = {
    body: data.body || '',
    icon: data.icon || '/favicon.ico',
    badge: data.badge || '/favicon.ico',
    image: data.image || undefined,
    tag: data.tag || undefined,
    renotify: false,
    data: {url: data.url || '/'}
  };
  event.waitUntil(self.registration.showNotification(data.title || 'Новое на сайте', options));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var url = (event.notification.data && event.notification.data.url) || '/';
  event.waitUntil(clients.matchAll({type: 'window', includeUncontrolled: true}).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      if ('focus' in list[i] && list[i].url === url) return list[i].focus();
    }
    return clients.openWindow ? clients.openWindow(url) : undefined;
  }));
});
