/**
 * sw.js — Service Worker для Sport CRM PWA
 *
 * Стратегія кешування:
 *   JS/CSS  — НЕ кешуємо у SW. Браузер керує через ?v=APP_VERSION у URL.
 *             При зміні APP_VERSION URL змінюється → браузер завантажує новий файл.
 *   API     — Network Only (завжди свіжі дані, ніколи не кешуємо)
 *   HTML    — Network First + офлайн fallback на кеш
 *   Іконки  — Cache First (змінюються рідко)
 *
 * Щоб скинути весь кеш: змінити APP_VERSION у config.php
 */

let CACHE_VERSION = 'sport-crm-v2';

async function loadVersion() {
  try {
    const res  = await fetch('/api/version', { cache: 'no-store' });
    const data = await res.json();
    CACHE_VERSION = 'sport-crm-v' + (data.version ?? '1');
  } catch (e) {}
}

// ── ВСТАНОВЛЕННЯ ─────────────────────────────────────────────
self.addEventListener('install', event => {
  event.waitUntil(
    loadVersion().then(() =>
      caches.open(CACHE_VERSION).then(cache =>
        cache.addAll(['/assets/icons/icon-192.png', '/manifest.json']).catch(() => {})
      )
    )
  );
  self.skipWaiting();
});

// ── АКТИВАЦІЯ — видаляємо всі старі кеші ─────────────────────
self.addEventListener('activate', event => {
  event.waitUntil(
    loadVersion().then(() =>
      caches.keys().then(keys =>
        Promise.all(keys.filter(k => k !== CACHE_VERSION).map(k => caches.delete(k)))
      )
    )
  );
  self.clients.claim();
});

// ── ПЕРЕХОПЛЕННЯ ЗАПИТІВ ──────────────────────────────────────
self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);

  // API — завжди мережа
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(
      fetch(request).catch(() => new Response(
        JSON.stringify({ success: false, error: 'Немає підключення до мережі' }),
        { headers: { 'Content-Type': 'application/json' } }
      ))
    );
    return;
  }

  // JS/CSS — завжди мережа (версіонуються через ?v= в URL)
  if (url.pathname.match(/\.(js|css)$/)) {
    event.respondWith(fetch(request));
    return;
  }

  // Іконки/шрифти — Cache First
  if (url.pathname.match(/\.(png|jpg|svg|ico|woff2?|ttf)$/)) {
    event.respondWith(
      caches.match(request).then(cached => cached || fetch(request))
    );
    return;
  }

  // HTML — Network First + офлайн fallback
  if (request.mode === 'navigate' || request.headers.get('Accept')?.includes('text/html')) {
    event.respondWith(
      fetch(request)
        .then(response => {
          if (response.ok) {
            const toCache = response.clone();
            caches.open(CACHE_VERSION).then(c => c.put(request, toCache));
          }
          return response;
        })
        .catch(() => caches.match(request).then(c => c || caches.match('/login')))
    );
    return;
  }
});

// ── PUSH ──────────────────────────────────────────────────────
self.addEventListener('push', event => {
  const data = event.data?.json() ?? {};
  event.waitUntil(
    self.registration.showNotification(data.title || 'Sport CRM', {
      body: data.body || '', icon: '/assets/icons/icon-192.png',
      badge: '/assets/icons/icon-192.png', data: { url: data.url || '/dashboard' },
    })
  );
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil(clients.openWindow(event.notification.data?.url || '/dashboard'));
});