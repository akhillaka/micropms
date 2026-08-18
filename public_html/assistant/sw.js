// Service Worker for Hotel Booking Assistant
const CACHE_NAME = 'assistant-cache-v2';
const ASSETS = [
  '/assistant/index.html',
  '/assistant/manifest.json',
  '/assistant/css/guest_theme.css',
  '/assistant/js/app.js',
  '/assistant/js/voice.js',
  '/assistant/js/ocr.js',
  '/assistant/js/voice_commands.js',
  '/assistant/assets/icon-192.png',
  '/assistant/assets/icon-512.png'
];

// Install Event
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      // Pre-cache core assets if desired, but don't fail install if they aren't all present
      return cache.addAll(ASSETS.map(url => new Request(url, { mode: 'no-cors' }))).catch(() => {});
    })
  );
  self.skipWaiting();
});

// Activate Event
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (key !== CACHE_NAME) {
            return caches.delete(key);
          }
        })
      );
    })
  );
  return self.clients.claim();
});

// Fetch Event (Network-first fallback to cache for offline capabilities)
self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;

  const url = new URL(event.request.url);
  if (url.pathname.includes('/api/') || url.pathname.includes('/assistant/api/')) {
    return;
  }

  event.respondWith(
    fetch(event.request)
      .then((response) => {
        // Clone and save response to cache if successful
        if (response && response.status === 200) {
          const cacheCopy = response.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(event.request, cacheCopy);
          });
        }
        return response;
      })
      .catch(() => {
        return caches.match(event.request);
      })
  );
});

// Listen for native OS Push Notifications
self.addEventListener('push', (event) => {
  let payload = { title: 'Hotel Assistant Update', message: 'You have a new update.' };
  if (event.data) {
    try {
      payload = event.data.json();
    } catch (e) {
      payload = { title: 'Hotel Assistant Update', message: event.data.text() };
    }
  }

  const options = {
    body: payload.message || payload.body || 'New alert in Hotel Assistant',
    icon: '/assistant/assets/icon-192.png',
    badge: '/assistant/assets/icon-192.png',
    vibrate: [100, 50, 100],
    data: {
      url: payload.url || '/assistant/index.html'
    }
  };

  event.waitUntil(
    self.registration.showNotification(payload.title, options)
  );
});

// Notification Click Event (opens app window)
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const urlToOpen = new URL(event.notification.data.url, self.location.origin).href;

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
      // Check if there is already a window open with this url/path
      for (let i = 0; i < windowClients.length; i++) {
        const client = windowClients[i];
        if (client.url === urlToOpen && 'focus' in client) {
          return client.focus();
        }
      }
      // If not, open a new window
      if (clients.openWindow) {
        return clients.openWindow(urlToOpen);
      }
    })
  );
});
