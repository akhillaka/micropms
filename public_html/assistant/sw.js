// Self-destructing Service Worker
// The offline process has been removed. This script ensures that any browsers
// that previously installed the service worker will unregister it and clear caches.

self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          return caches.delete(cacheName);
        })
      );
    }).then(() => {
      self.registration.unregister();
    })
  );
});

self.addEventListener('fetch', (event) => {
  // Do nothing, let the browser handle it natively
});
