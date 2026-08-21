/**
 * Pull-to-refresh for MicroPMS / Hotel Assistant PWAs on touch devices.
 * Swipe down from the top of a scroll container to refresh.
 */
(function (global) {
  const THRESHOLD = 72;
  const MAX_PULL = 110;

  function ensureStyles() {
    if (document.getElementById('ptr-styles')) return;
    const style = document.createElement('style');
    style.id = 'ptr-styles';
    style.textContent = `
      html, body { overscroll-behavior-y: contain; }
      #ptr-indicator{
        position:fixed; left:50%; top:calc(8px + env(safe-area-inset-top, 0px));
        transform:translate(-50%, -120%) scale(.85); opacity:0;
        z-index:2300; pointer-events:none;
        min-width:44px; height:44px; border-radius:999px;
        background:rgba(15,23,42,.92); color:#fff;
        display:flex; align-items:center; justify-content:center;
        box-shadow:0 8px 24px rgba(15,23,42,.28);
        transition:opacity .15s ease, transform .15s ease;
        font-size:1.25rem;
      }
      #ptr-indicator.ptr-visible{ opacity:1; transform:translate(-50%, 0) scale(1); }
      #ptr-indicator.ptr-busy i, #ptr-indicator.ptr-busy .ptr-spin{
        animation: ptr-spin .7s linear infinite;
      }
      @keyframes ptr-spin{ to{ transform:rotate(360deg); } }
    `;
    document.head.appendChild(style);
  }

  function ensureIndicator() {
    let el = document.getElementById('ptr-indicator');
    if (el) return el;
    el = document.createElement('div');
    el.id = 'ptr-indicator';
    el.innerHTML = '<span class="ptr-spin" aria-hidden="true">↓</span>';
    document.body.appendChild(el);
    return el;
  }

  function isInteractiveTarget(target) {
    if (!target || !target.closest) return false;
    return !!target.closest('input, textarea, select, button, a, [contenteditable="true"], .camera-overlay, #pc-overlay, #pc-sheet');
  }

  /**
   * @param {object} opts
   * @param {() => Element|null} [opts.getScrollEl]
   * @param {() => (void|Promise<void>)} opts.onRefresh
   * @param {() => boolean} [opts.isBlocked]
   * @param {() => boolean} [opts.enabled]
   */
  function attach(opts) {
    if (!opts || typeof opts.onRefresh !== 'function') return { destroy() {} };
    ensureStyles();
    const indicator = ensureIndicator();

    let startY = 0;
    let pulling = false;
    let armed = false;
    let busy = false;
    let pullPx = 0;

    function enabled() {
      if (typeof opts.enabled === 'function' && !opts.enabled()) return false;
      // Prefer touch / coarse pointers (phones, installed PWAs)
      return window.matchMedia('(pointer: coarse)').matches
        || window.matchMedia('(max-width: 900px)').matches
        || (window.navigator.standalone === true)
        || window.matchMedia('(display-mode: standalone)').matches;
    }

    function blocked() {
      if (busy) return true;
      if (typeof opts.isBlocked === 'function' && opts.isBlocked()) return true;
      return false;
    }

    function scrollEl() {
      if (typeof opts.getScrollEl === 'function') {
        return opts.getScrollEl() || document.scrollingElement || document.documentElement;
      }
      return document.scrollingElement || document.documentElement;
    }

    function setPull(px) {
      pullPx = Math.max(0, Math.min(MAX_PULL, px));
      const ready = pullPx >= THRESHOLD;
      indicator.classList.toggle('ptr-visible', pullPx > 8);
      indicator.classList.toggle('ptr-busy', busy);
      const spin = indicator.querySelector('.ptr-spin');
      if (spin && !busy) {
        spin.textContent = ready ? '↑' : '↓';
        spin.style.transform = `rotate(${Math.min(180, (pullPx / THRESHOLD) * 180)}deg)`;
      }
    }

    function resetPull() {
      pulling = false;
      armed = false;
      pullPx = 0;
      if (!busy) {
        indicator.classList.remove('ptr-visible');
        const spin = indicator.querySelector('.ptr-spin');
        if (spin) {
          spin.textContent = '↓';
          spin.style.transform = '';
        }
      }
    }

    async function finishRefresh() {
      busy = true;
      indicator.classList.add('ptr-visible', 'ptr-busy');
      const spin = indicator.querySelector('.ptr-spin');
      if (spin) {
        spin.textContent = '↻';
        spin.style.transform = '';
      }
      try {
        await opts.onRefresh();
      } catch (e) {
        console.warn('Pull-to-refresh failed', e);
      } finally {
        busy = false;
        indicator.classList.remove('ptr-busy', 'ptr-visible');
        resetPull();
      }
    }

    function onTouchStart(e) {
      if (!enabled() || blocked() || e.touches.length !== 1) return;
      if (isInteractiveTarget(e.target)) return;
      const el = scrollEl();
      if (!el) return;
      if ((el.scrollTop || 0) > 2) return;
      startY = e.touches[0].clientY;
      armed = true;
      pulling = false;
    }

    function onTouchMove(e) {
      if (!armed || busy || e.touches.length !== 1) return;
      const el = scrollEl();
      if (!el || (el.scrollTop || 0) > 2) {
        resetPull();
        return;
      }
      const dy = e.touches[0].clientY - startY;
      if (dy <= 0) {
        resetPull();
        armed = true; // still at top; allow re-arm without lift
        startY = e.touches[0].clientY;
        return;
      }
      pulling = true;
      // Resist after threshold so the gesture feels natural
      const resisted = dy < THRESHOLD ? dy : THRESHOLD + (dy - THRESHOLD) * 0.35;
      setPull(resisted);
      if (pullPx > 12 && e.cancelable) {
        e.preventDefault();
      }
    }

    function onTouchEnd() {
      if (!armed) return;
      const shouldRefresh = pulling && pullPx >= THRESHOLD && !blocked();
      armed = false;
      pulling = false;
      if (shouldRefresh) {
        finishRefresh();
      } else {
        resetPull();
      }
    }

    document.addEventListener('touchstart', onTouchStart, { passive: true, capture: true });
    document.addEventListener('touchmove', onTouchMove, { passive: false, capture: true });
    document.addEventListener('touchend', onTouchEnd, { passive: true, capture: true });
    document.addEventListener('touchcancel', onTouchEnd, { passive: true, capture: true });

    return {
      destroy() {
        document.removeEventListener('touchstart', onTouchStart, true);
        document.removeEventListener('touchmove', onTouchMove, true);
        document.removeEventListener('touchend', onTouchEnd, true);
        document.removeEventListener('touchcancel', onTouchEnd, true);
      }
    };
  }

  global.PullToRefresh = { attach };
})(window);
