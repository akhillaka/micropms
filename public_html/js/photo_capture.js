/**
 * Shared ID-card / guest-face camera overlay used by Hotel Assistant,
 * folio, guest profile, booking wizard, and guest portal.
 */
(function (global) {
  const TIPS = {
    id_front: 'Fill the card frame. Avoid glare. Hold still.',
    id_back: 'Fill the card frame. Avoid glare. Hold still.',
    guest_face: 'Center the face in the oval. Look at the camera.'
  };
  const TITLES = {
    id_front: 'Capture ID Front',
    id_back: 'Capture ID Back',
    guest_face: 'Capture Guest Photo'
  };

  let stream = null;
  let onCapture = null;
  let onCancel = null;
  let currentMode = 'id_front';

  function documentUrl(filename) {
    if (!filename) return '';
    return '/api/admin/view_document?file=' + encodeURIComponent(filename);
  }

  function ensureMarkup() {
    if (document.getElementById('pc-overlay')) return;
    const style = document.createElement('style');
    style.textContent = `
      #pc-overlay{position:fixed;inset:0;z-index:2400;background:rgba(8,12,24,.88);display:none;align-items:flex-end;justify-content:center;}
      #pc-overlay.pc-open{display:flex;}
      #pc-sheet{width:100%;max-width:420px;background:#111827;color:#fff;border-radius:24px 24px 0 0;padding:18px 18px calc(18px + env(safe-area-inset-bottom));}
      #pc-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;}
      #pc-title{font-weight:800;font-size:1.05rem;}
      #pc-close{background:none;border:none;color:#fff;font-size:1.4rem;cursor:pointer;line-height:1;}
      #pc-stage{position:relative;width:100%;aspect-ratio:4/3;background:#000;border-radius:16px;overflow:hidden;}
      #pc-video{width:100%;height:100%;object-fit:cover;}
      #pc-mask{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;}
      #pc-frame{position:relative;}
      #pc-frame.pc-id{width:86%;aspect-ratio:1.586/1;border:2px solid rgba(255,255,255,.92);border-radius:10px;box-shadow:0 0 0 9999px rgba(0,0,0,.42);}
      #pc-frame.pc-face{width:46%;aspect-ratio:3/4;border:2px dashed rgba(255,255,255,.9);border-radius:50%;box-shadow:0 0 0 9999px rgba(0,0,0,.42);}
      #pc-frame.pc-id::before,#pc-frame.pc-id::after{content:"";position:absolute;width:18px;height:18px;border-color:#fff;border-style:solid;}
      #pc-frame.pc-id::before{top:-2px;left:-2px;border-width:3px 0 0 3px;border-radius:4px 0 0 0;}
      #pc-frame.pc-id::after{top:-2px;right:-2px;border-width:3px 3px 0 0;border-radius:0 4px 0 0;}
      #pc-tips{margin:12px 0 14px;font-size:.8rem;font-weight:700;color:rgba(255,255,255,.78);text-align:center;}
      #pc-actions{display:flex;flex-direction:column;gap:8px;}
      #pc-actions button{min-height:48px;border-radius:12px;font-weight:800;cursor:pointer;border:none;}
      #pc-snap{background:#2563eb;color:#fff;}
      #pc-gallery{background:transparent;color:#fff;border:1px solid rgba(255,255,255,.25)!important;}
      #pc-file{display:none;}
    `;
    document.head.appendChild(style);

    const wrap = document.createElement('div');
    wrap.id = 'pc-overlay';
    wrap.innerHTML = `
      <div id="pc-sheet" onclick="event.stopPropagation()">
        <div id="pc-head">
          <strong id="pc-title">Capture ID Front</strong>
          <button type="button" id="pc-close" aria-label="Close">×</button>
        </div>
        <div id="pc-stage">
          <video id="pc-video" autoplay playsinline muted></video>
          <div id="pc-mask"><div id="pc-frame" class="pc-id"></div></div>
        </div>
        <p id="pc-tips">${TIPS.id_front}</p>
        <div id="pc-actions">
          <button type="button" id="pc-snap">📷 SNAP PHOTO</button>
          <button type="button" id="pc-gallery">🖼️ CHOOSE FROM GALLERY</button>
        </div>
        <input type="file" id="pc-file" accept="image/*">
      </div>
    `;
    wrap.addEventListener('click', (e) => {
      if (e.target === wrap) close();
    });
    document.body.appendChild(wrap);
    document.getElementById('pc-close').addEventListener('click', close);
    document.getElementById('pc-snap').addEventListener('click', snap);
    document.getElementById('pc-gallery').addEventListener('click', () => document.getElementById('pc-file').click());
    document.getElementById('pc-file').addEventListener('change', handleFile);
  }

  function applyMode(mode) {
    currentMode = TITLES[mode] ? mode : 'id_front';
    document.getElementById('pc-title').textContent = TITLES[currentMode];
    document.getElementById('pc-tips').textContent = TIPS[currentMode];
    const frame = document.getElementById('pc-frame');
    frame.className = currentMode === 'guest_face' ? 'pc-face' : 'pc-id';
  }

  async function startCamera() {
    stopCamera();
    const facingMode = currentMode === 'guest_face' ? 'user' : 'environment';
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode, width: { ideal: 1280 }, height: { ideal: 720 } }
      });
      const video = document.getElementById('pc-video');
      video.srcObject = stream;
      await video.play().catch(() => {});
    } catch (err) {
      console.warn('PhotoCapture camera failed', err);
      document.getElementById('pc-file').click();
    }
  }

  function stopCamera() {
    if (stream) {
      stream.getTracks().forEach((t) => t.stop());
      stream = null;
    }
    const video = document.getElementById('pc-video');
    if (video) video.srcObject = null;
  }

  function dataUrlToFile(dataUrl, name) {
    const parts = dataUrl.split(',');
    const mime = (parts[0].match(/:(.*?);/) || [])[1] || 'image/jpeg';
    const bin = atob(parts[1] || '');
    const bytes = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
    return new File([bytes], name, { type: mime });
  }

  function emit(dataUrl, file) {
    const cb = onCapture;
    onCapture = null;
    onCancel = null;
    close();
    if (typeof cb === 'function') cb(dataUrl, file);
  }

  function snap() {
    const video = document.getElementById('pc-video');
    if (!video || !video.videoWidth) return;
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    const dataUrl = canvas.toDataURL('image/jpeg', 0.9);
    emit(dataUrl, dataUrlToFile(dataUrl, currentMode + '.jpg'));
  }

  function handleFile(event) {
    const file = event.target.files && event.target.files[0];
    event.target.value = '';
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => emit(reader.result, file);
    reader.readAsDataURL(file);
  }

  function assignFile(input, file) {
    if (!input || !file) return;
    try {
      const dt = new DataTransfer();
      dt.items.add(file);
      input.files = dt.files;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    } catch (err) {
      console.warn('PhotoCapture could not assign file', err);
    }
  }

  function open(opts) {
    ensureMarkup();
    onCapture = opts && opts.onCapture;
    onCancel = opts && opts.onCancel;
    applyMode((opts && opts.mode) || 'id_front');
    document.getElementById('pc-overlay').classList.add('pc-open');
    startCamera();
  }

  function close() {
    const overlay = document.getElementById('pc-overlay');
    if (overlay) overlay.classList.remove('pc-open');
    stopCamera();
    const cancelled = onCancel;
    onCapture = null;
    onCancel = null;
    if (typeof cancelled === 'function') cancelled();
  }

  global.PhotoCapture = {
    TIPS,
    TITLES,
    documentUrl,
    open,
    close,
    assignFile,
    dataUrlToFile
  };
})(window);
