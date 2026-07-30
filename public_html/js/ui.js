// CSRF Interceptor: auto-attach token to all fetch requests
(function() {
    const originalFetch = window.fetch;
    window.fetch = function(url, options = {}) {
        if (typeof url === 'string' && url.includes('/api/')) {
            const meta = document.querySelector('meta[name="csrf-token"]');
            if (meta) {
                options.headers = options.headers || {};
                if (options.headers instanceof Headers) {
                    options.headers.set('X-CSRF-Token', meta.content);
                } else {
                    options.headers['X-CSRF-Token'] = meta.content;
                }
            }
            options.credentials = 'same-origin';
        }
        return originalFetch.call(this, url, options);
    };
})();

const UI = {
    showToast: (message, type = 'success') => {
        // Find or create global container
        let container = document.getElementById('global-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'global-toast-container';
            container.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:12px;pointer-events:none;';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = `web-toast toast-${type}`;
        toast.style.cssText = 'min-width:320px;max-width:420px;background:rgba(255,255,255,0.95);backdrop-filter:blur(12px);border:1px solid rgba(226,232,240,0.8);border-radius:1rem;padding:14px 16px;display:flex;align-items:center;gap:12px;box-shadow:0 10px 15px -3px rgba(0,0,0,0.05),0 4px 6px -2px rgba(0,0,0,0.02);transition:all 0.3s cubic-bezier(0.16,1,0.3,1);transform:translateY(1rem);opacity:0;pointer-events:auto;';
        
        let iconHtml = '';
        let iconColor = '#3B82F6';
        if (type === 'success') {
            iconHtml = '<i class="ph-fill ph-check-circle" style="font-size:1.25rem;"></i>';
            iconColor = '#10B981';
        } else if (type === 'error') {
            iconHtml = '<i class="ph-fill ph-warning-circle" style="font-size:1.25rem;"></i>';
            iconColor = '#EF4444';
        } else {
            iconHtml = '<i class="ph-fill ph-info" style="font-size:1.25rem;"></i>';
        }

        toast.innerHTML = `
            <span style="color:${iconColor};display:inline-flex;align-items:center;justify-content:center;">${iconHtml}</span>
            <div style="flex:1;min-width:0;">
                <p style="margin:0;font-size:0.875rem;font-weight:600;color:#1E293B;font-family:sans-serif;line-height:1.25rem;text-overflow:ellipsis;overflow:hidden;white-space:nowrap;">${message}</p>
            </div>
            <button class="web-toast-close" style="cursor:pointer;color:#94A3B8;border:none;background:transparent;padding:4px;border-radius:50%;display:flex;align-items:center;justify-content:center;transition:all 0.2s;" onclick="this.parentElement.style.opacity=\'0\';this.parentElement.style.transform=\'translateY(1rem)\';setTimeout(()=>this.parentElement.remove(),300);">
                <i class="ph ph-x"></i>
            </button>
        `;

        container.appendChild(toast);
        
        // Trigger reflow and show transition
        void toast.offsetHeight;
        toast.style.transform = 'translateY(0)';
        toast.style.opacity = '1';

        // Auto remove after 4 seconds
        setTimeout(() => {
            if (toast.parentElement) {
                toast.style.transform = 'translateY(1rem)';
                toast.style.opacity = '0';
                setTimeout(() => {
                    if (toast.parentElement) toast.remove();
                }, 300);
            }
        }, 4000);
    },
    
    showModal: (id) => {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.remove('hidden');
        modal.style.transition = 'opacity 0.25s cubic-bezier(0.16, 1, 0.3, 1)';
        modal.style.opacity = '0';
        void modal.offsetHeight;
        modal.style.opacity = '1';
        
        const panel = modal.querySelector('.transform');
        if (panel) {
            panel.classList.remove('translate-y-full');
            panel.style.transition = 'transform 0.3s cubic-bezier(0.16, 1, 0.3, 1)';
            panel.style.transform = 'translateY(1rem) scale(0.98)';
            void panel.offsetHeight;
            panel.style.transform = 'translateY(0) scale(1)';
        }
    },
    
    hideModal: (id) => {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.style.opacity = '0';
        
        const panel = modal.querySelector('.transform');
        if (panel) {
            panel.style.transform = 'translateY(1rem) scale(0.98)';
        }
        setTimeout(() => {
            modal.classList.add('hidden');
        }, 300);
    },
    
    setLoading: (btnElement, isLoading, originalText = '') => {
        if (!btnElement) return;
        if (isLoading) {
            btnElement.disabled = true;
            btnElement.dataset.originalText = btnElement.innerText;
            btnElement.innerHTML = `<svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-current inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Processing...`;
            btnElement.classList.add('opacity-75', 'cursor-not-allowed');
        } else {
            btnElement.disabled = false;
            btnElement.innerText = btnElement.dataset.originalText || originalText;
            btnElement.classList.remove('opacity-75', 'cursor-not-allowed');
        }
    },

    compressImage: (file, maxDim = 1000, quality = 0.7, targetSize = 500 * 1024) => {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = (e) => {
                const img = new Image();
                img.onload = () => {
                    let { width, height } = img;
                    if (width > maxDim || height > maxDim) {
                        const ratio = Math.min(maxDim / width, maxDim / height);
                        width = Math.round(width * ratio);
                        height = Math.round(height * ratio);
                    }
                    const canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0, width, height);
                    let currentQuality = quality;
                    let attempts = 0;
                    const tryCompress = () => {
                        canvas.toBlob((blob) => {
                            if (!blob) { reject(new Error('Compression failed')); return; }
                            if (blob.size <= targetSize || attempts >= 5 || currentQuality <= 0.3) {
                                resolve({ blob });
                            } else {
                                currentQuality -= 0.1;
                                attempts++;
                                tryCompress();
                            }
                        }, 'image/jpeg', currentQuality);
                    };
                    tryCompress();
                };
                img.onerror = () => reject(new Error('Image load failed'));
                img.src = e.target.result;
            };
            reader.onerror = () => reject(new Error('File read failed'));
            reader.readAsDataURL(file);
        });
    },

    viewImage: (src) => {
        const modal = document.getElementById('image-viewer-modal');
        if (modal) {
            document.getElementById('viewer-image').src = src;
            modal.classList.remove('hidden');
        }
    },

    closeImageViewer: () => {
        const modal = document.getElementById('image-viewer-modal');
        if (modal) {
            modal.classList.add('hidden');
            document.getElementById('viewer-image').src = '';
        }
    }
};
