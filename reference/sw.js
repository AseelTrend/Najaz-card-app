/**
 * Service Worker — شحن برو
 * يدعم: وضع Offline + Web Push Notifications
 * ملاحظة: لا نخزّن صفحات PHP لأنها ديناميكية
 */

const CACHE_VERSION = 'shahenpro-v4';
const STATIC_CACHE  = `${CACHE_VERSION}-static`;

// نخزّن فقط الأصول الثابتة (CSS, Fonts, Icons)
const STATIC_ASSETS = [
  '/offline.html',
  '/icons/icon-512.png',
  '/icons/icon-192.png',
  '/icons/icon-144.png',
  '/icons/icon-96.png',
  '/icons/icon-72.png',
];

// ══════════════ Install ══════════════
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then(cache => Promise.allSettled(
        STATIC_ASSETS.map(url => cache.add(url).catch(() => {}))
      ))
      .then(() => self.skipWaiting())
  );
});

// ══════════════ Activate ══════════════
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(
        keys.filter(k => k !== STATIC_CACHE).map(k => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

// ══════════════ Fetch ══════════════
self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') return;

  const url = new URL(event.request.url);

  // صفحات PHP وملفات ديناميكية — دائماً من الشبكة
  if (url.pathname.endsWith('.php') ||
      url.pathname.startsWith('/api/') ||
      url.pathname.startsWith('/admin/') ||
      url.pathname === '/') {
    event.respondWith(
      fetch(event.request).catch(() => caches.match('/offline.html'))
    );
    return;
  }

  // الأصول الثابتة فقط — من الـ cache أولاً
  event.respondWith(
    caches.match(event.request).then(cached => {
      if (cached) return cached;
      return fetch(event.request).then(response => {
        // خزّن فقط الأيقونات والصور الثابتة
        if (response && response.status === 200 &&
            (url.pathname.startsWith('/icons/') || url.pathname.startsWith('/assets/'))) {
          caches.open(STATIC_CACHE).then(c => c.put(event.request, response.clone()));
        }
        return response;
      }).catch(() => caches.match('/offline.html'));
    })
  );
});

// ══════════════ Push ══════════════
self.addEventListener('push', event => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch(e) {}

  const origin = self.location.origin;
  const title   = data.title || 'شحن برو';
  const options = {
    body:     data.body  || 'لديك إشعار جديد',
    icon:     data.icon  || (origin + '/icons/icon-192.png'),
    badge:    data.badge || (origin + '/icons/icon-96.png'),
    dir:      'rtl',
    lang:     'ar',
    vibrate:  [200, 100, 200],
    tag:      data.tag || 'shahenpro-' + Date.now(),
    renotify: true,
    data:     { url: data.url || '/mobile.php' },
    actions:  [
      { action: 'open',    title: 'فتح التطبيق' },
      { action: 'dismiss', title: 'تجاهل' },
    ]
  };

  event.waitUntil(
    caches.open(STATIC_CACHE).then(cache =>
      cache.match(options.icon).then(cached => {
        if (!cached) return fetch(options.icon).then(r => { if(r.ok) cache.put(options.icon, r.clone()); }).catch(()=>{});
      })
    ).then(() => self.registration.showNotification(title, options))
  );
});

// ══════════════ Notification Click ══════════════
self.addEventListener('notificationclick', event => {
  event.notification.close();
  if (event.action === 'dismiss') return;

  const targetUrl = event.notification.data?.url || '/mobile.php';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientList => {
      for (const client of clientList) {
        if ('focus' in client) {
          client.navigate(targetUrl);
          return client.focus();
        }
      }
      return clients.openWindow(targetUrl);
    })
  );
});

// ══════════════ Push Subscription Change ══════════════
self.addEventListener('pushsubscriptionchange', event => {
  event.waitUntil(
    event.newSubscription
      ? fetch('/api/push_subscribe.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(event.newSubscription.toJSON()),
          credentials: 'include'
        })
      : Promise.resolve()
  );
});
