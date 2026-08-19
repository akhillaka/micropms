(function () {
  if (!('serviceWorker' in navigator)) return;

  navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});

  async function subscribePush() {
    if (!window.PMS_STAFF_PUSH) return;
    if (!('PushManager' in window) || !('Notification' in window)) return;
    try {
      const vapidRes = await fetch('/api/admin/push_subscribe', { credentials: 'same-origin' });
      const vapid = await vapidRes.json();
      const publicKey = vapid.publicKey || (vapid.data && vapid.data.publicKey) || '';
      if (!publicKey) return;

      if (Notification.permission === 'default') {
        await Notification.requestPermission();
      }
      if (Notification.permission !== 'granted') return;

      const reg = await navigator.serviceWorker.ready;
      let sub = await reg.pushManager.getSubscription();
      if (!sub) {
        sub = await reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlBase64ToUint8Array(publicKey),
        });
      }
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      await fetch('/api/admin/push_subscribe', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify(Object.assign({ action: 'subscribe' }, sub.toJSON())),
      });
    } catch (e) {
      console.warn('Push subscribe skipped', e);
    }
  }

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const output = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) output[i] = raw.charCodeAt(i);
    return output;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', subscribePush);
  } else {
    subscribePush();
  }
})();
