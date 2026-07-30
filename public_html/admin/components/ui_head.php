<?php
/**
 * ui_head.php — Global head component
 * Injects CDN scripts, Tailwind config, design system CSS, global JS helpers,
 * toast system, confirm modal, and loading overlay.
 * Pulls hotel name/logo from PROPERTY_NAME / PROPERTY_LOGO_BASE64 constants.
 */

// Resolve hotel branding (set by config.php load_db_settings)
$_pms_hotel_name = defined('PROPERTY_NAME') ? PROPERTY_NAME : 'MicroPMS';
$_pms_hotel_logo = defined('PROPERTY_LOGO_BASE64') ? PROPERTY_LOGO_BASE64 : '';
?>
<!-- Phosphor Icons -->
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<!-- Tailwind CSS -->
<script src="https://cdn.tailwindcss.com"></script>
<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<!-- Tailwind Config -->
<script>
    tailwind.config = {
        theme: {
            extend: {
                fontFamily: {
                    sans: ['"Inter"', 'sans-serif'],
                    display: ['"Plus Jakarta Sans"', 'sans-serif'],
                },
                colors: {
                    brand: {
                        50: '#F0F4FC',
                        100: '#DBE2F4',
                        200: '#BFCEEC',
                        300: '#99B2DF',
                        400: '#6F91CF',
                        500: '#1E3A8A',
                        600: '#1A3279',
                        700: '#162963',
                        800: '#101E49',
                        900: '#080F25',
                        accent: '#CA8A04',
                        accentHover: '#A16E03',
                        accentLight: '#FEF9EC'
                    },
                    success: { 50: '#F0FDF4', 100: '#DCFCE7', 500: '#22C55E', 600: '#16A34A', 700: '#15803D' },
                    error:   { 50: '#FEF2F2', 100: '#FEE2E2', 500: '#EF4444', 600: '#DC2626', 700: '#B91C1C' },
                    warning: { 50: '#FFFBEB', 100: '#FEF3C7', 500: '#F59E0B', 600: '#D97706', 700: '#B45309' }
                },
                boxShadow: {
                    'minimal': '0 4px 20px -2px rgba(0, 0, 0, 0.05), 0 2px 6px -1px rgba(0, 0, 0, 0.03)',
                    'minimal-hover': '0 10px 25px -3px rgba(0, 0, 0, 0.08), 0 4px 12px -2px rgba(0, 0, 0, 0.04)',
                }
            }
        }
    }
</script>
<!-- Global CSS -->
<style>
    body {
        font-family: 'Inter', sans-serif;
        -webkit-tap-highlight-color: transparent;
        background-color: #F8FAFC;
        color: #1E40AF;
        font-weight: 500;
        letter-spacing: -0.01em;
    }
    h1, h2, h3, h4, h5, h6, .font-display {
        font-family: 'Plus Jakarta Sans', sans-serif;
        font-weight: 700;
        letter-spacing: -0.02em;
    }

    /* Modern minimalist scrollbar */
    ::-webkit-scrollbar { width: 5px; height: 5px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 10px; }
    ::-webkit-scrollbar-thumb:hover { background: #94A3B8; }
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

    * { border-color: #E2E8F0; }

    /* Ensure hidden class works */
    .hidden { display: none !important; }

    /* ─── Cards ─── */
    .card-minimal {
        background-color: #ffffff;
        border: 1px solid #E2E8F0 !important;
        border-radius: 1rem;
        box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 6px -1px rgba(0,0,0,0.03);
        transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .card-minimal:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px -3px rgba(0,0,0,0.08), 0 4px 12px -2px rgba(0,0,0,0.04);
    }

    /* ─── Buttons ─── */
    .btn-minimal {
        background-color: #CA8A04;
        color: #FFFFFF;
        font-weight: 600;
        border-radius: 0.75rem;
        border: 1px solid transparent !important;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        cursor: pointer;
        padding: 0.625rem 1.25rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.375rem;
        font-family: 'Plus Jakarta Sans', sans-serif;
    }
    .btn-minimal:hover { background-color: #A16E03; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
    .btn-minimal:active { transform: translateY(1px); box-shadow: 0 1px 2px rgba(0,0,0,0.05); }

    .btn-secondary {
        background-color: #ffffff;
        color: #334155;
        font-weight: 600;
        border: 1px solid #E2E8F0 !important;
        border-radius: 0.75rem;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        cursor: pointer;
        padding: 0.625rem 1.25rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.375rem;
        font-family: 'Plus Jakarta Sans', sans-serif;
    }
    .btn-secondary:hover { background-color: #F8FAFC; border-color: #CBD5E1 !important; transform: translateY(-1px); }
    .btn-secondary:active { transform: translateY(1px); }

    .btn-success-min {
        background-color: #10B981;
        color: #fff;
        font-weight: 600;
        border-radius: 0.75rem;
        border: 1px solid transparent !important;
        transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        cursor: pointer;
        padding: 0.625rem 1.25rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.375rem;
        font-family: 'Plus Jakarta Sans', sans-serif;
    }
    .btn-success-min:hover { background-color: #059669; transform: translateY(-1px); }

    .btn-danger-min {
        background-color: #EF4444;
        color: #fff;
        font-weight: 600;
        border-radius: 0.75rem;
        border: 1px solid transparent !important;
        transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        cursor: pointer;
        padding: 0.625rem 1.25rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.375rem;
        font-family: 'Plus Jakarta Sans', sans-serif;
    }
    .btn-danger-min:hover { background-color: #DC2626; transform: translateY(-1px); }

    /* Focus-visible styles for keyboard navigation */
    .btn-minimal:focus-visible,
    .btn-secondary:focus-visible,
    .btn-success-min:focus-visible,
    .btn-danger-min:focus-visible,
    button:focus-visible,
    a:focus-visible {
        outline: 2px solid #1E3A8A;
        outline-offset: 2px;
        border-radius: 0.5rem;
    }

    /* ─── Inputs ─── */
    input[type="text"], input[type="date"], input[type="number"],
    input[type="password"], input[type="email"], input[type="tel"],
    select, textarea {
        transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        border: 1px solid #E2E8F0 !important;
        border-radius: 0.75rem;
        background-color: #ffffff;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05) !important;
        font-weight: 500;
        padding: 0.625rem 0.875rem !important;
        font-family: 'Inter', sans-serif;
    }
    input[type="text"]:focus, input[type="date"]:focus, input[type="number"]:focus,
    input[type="password"]:focus, input[type="email"]:focus, input[type="tel"]:focus,
    select:focus, textarea:focus {
        outline: none;
        border-color: #1E3A8A !important;
        box-shadow: 0 0 0 4px rgba(30,58,138,0.15) !important;
        background-color: #ffffff;
    }

    /* ─── Tables ─── */
    .table-brutal {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        background: #ffffff;
        border: 1px solid #E2E8F0 !important;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        font-size: 0.875rem;
        border-radius: 0.75rem;
        overflow: hidden;
    }
    .table-brutal th {
        background-color: #F8FAFC;
        color: #475569;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.05em;
        padding: 0.875rem 1rem;
        border-bottom: 1px solid #E2E8F0;
        border-right: 1px solid #F1F5F9;
        text-align: left;
    }
    .table-brutal th:last-child { border-right: none; }
    .table-brutal td {
        padding: 0.875rem 1rem;
        border-bottom: 1px solid #F1F5F9;
        border-right: 1px solid #F1F5F9;
        color: #334155;
        font-weight: 500;
    }
    .table-brutal td:last-child { border-right: none; }
    .table-brutal tr:last-child td { border-bottom: none; }
    .table-brutal tbody tr:nth-child(even) { background-color: #FCFDFE; }
    .table-brutal tbody tr:hover { background-color: #F8FAFC; transition: background 0.1s; }

    /* ─── Modal ─── */
    .modal-brutal {
        background: rgba(255,255,255,0.97);
        backdrop-filter: blur(16px);
        border: 1px solid rgba(226,232,240,0.8) !important;
        box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
        border-radius: 1.25rem;
        padding: 1.75rem;
    }

    /* ─── Toast System ─── */
    #global-toast-container {
        position: fixed;
        bottom: 24px;
        right: 24px;
        z-index: 9999;
        display: flex;
        flex-direction: column;
        gap: 10px;
        pointer-events: none;
    }
    .web-toast {
        min-width: 300px;
        max-width: 420px;
        background: rgba(255,255,255,0.96);
        backdrop-filter: blur(12px);
        border: 1px solid rgba(226,232,240,0.9) !important;
        border-radius: 1rem;
        padding: 14px 16px;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        box-shadow: 0 10px 15px -3px rgba(0,0,0,0.06), 0 4px 6px -2px rgba(0,0,0,0.03);
        transform: translateX(120%);
        opacity: 0;
        animation: toast-slide-in 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        pointer-events: auto;
        position: relative;
        overflow: hidden;
    }
    .toast-progress {
        position: absolute;
        bottom: 0;
        left: 0;
        height: 3px;
        border-radius: 0 0 1rem 1rem;
        animation: toast-progress-shrink linear forwards;
    }
    .web-toast.toast-success .toast-icon { color: #10B981; }
    .web-toast.toast-success .toast-progress { background: #10B981; }
    .web-toast.toast-error   .toast-icon { color: #EF4444; }
    .web-toast.toast-error   .toast-progress { background: #EF4444; }
    .web-toast.toast-info    .toast-icon { color: #3B82F6; }
    .web-toast.toast-info    .toast-progress { background: #3B82F6; }
    .web-toast.toast-warning .toast-icon { color: #F59E0B; }
    .web-toast.toast-warning .toast-progress { background: #F59E0B; }
    .toast-icon { font-size: 1.2rem; flex-shrink: 0; margin-top: 1px; }
    .web-toast-close {
        cursor: pointer; color: #94A3B8; border: none; background: transparent;
        padding: 2px; border-radius: 50%; display: flex; align-items: center;
        justify-content: center; transition: all 0.15s; flex-shrink: 0; margin-top: 1px;
    }
    .web-toast-close:hover { background: #F1F5F9; color: #475569; }
    @keyframes toast-slide-in { to { transform: translateX(0); opacity: 1; } }
    @keyframes toast-slide-out { to { transform: translateX(120%); opacity: 0; } }
    @keyframes toast-progress-shrink { from { width: 100%; } to { width: 0%; } }

    /* ─── Confirm Modal ─── */
    #pms-confirm-overlay {
        position: fixed; inset: 0;
        background: rgba(15,23,42,0.5);
        backdrop-filter: blur(6px);
        z-index: 10000;
        display: flex; align-items: center; justify-content: center;
        opacity: 0; transition: opacity 0.2s ease; pointer-events: none;
    }
    #pms-confirm-overlay.show { opacity: 1; pointer-events: auto; }
    #pms-confirm-box {
        background: #fff;
        border: 1px solid #E2E8F0 !important;
        border-radius: 1.25rem;
        padding: 1.75rem;
        max-width: 380px; width: 90%;
        box-shadow: 0 25px 50px -12px rgba(0,0,0,0.2);
        transform: scale(0.94) translateY(10px);
        transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }
    #pms-confirm-overlay.show #pms-confirm-box { transform: scale(1) translateY(0); }

    /* ─── Loading Overlay ─── */
    #pms-loading-overlay {
        position: fixed; inset: 0;
        background: rgba(255,255,255,0.75);
        backdrop-filter: blur(4px);
        z-index: 9998;
        display: flex; align-items: center; justify-content: center;
        opacity: 0; transition: opacity 0.2s ease; pointer-events: none;
        flex-direction: column; gap: 12px;
    }
    #pms-loading-overlay.show { opacity: 1; pointer-events: auto; }
    @keyframes pms-spin { to { transform: rotate(360deg); } }
    .pms-spinner {
        width: 36px; height: 36px;
        border: 3px solid #E2E8F0;
        border-top-color: #000;
        border-radius: 50%;
        animation: pms-spin 0.65s linear infinite;
    }

    /* ─── Tab Styles ─── */
    .tab-active {
        font-size: 0.813rem;
        font-weight: 700;
        color: #0F172A;
        border-bottom: 2px solid #0F172A;
    }
    .tab-inactive {
        font-size: 0.813rem;
        font-weight: 600;
        color: #64748B;
        border-bottom: 2px solid transparent;
    }
    .tab-inactive:hover { color: #334155; }

    /* ─── Animations ─── */
    @keyframes pms-fade-up {
        from { opacity: 0; transform: translateY(8px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .animate-fade-up { animation: pms-fade-up 0.3s cubic-bezier(0.16, 1, 0.3, 1) both; }
    
    @keyframes pms-fade-in {
        from { opacity: 0; }
        to   { opacity: 1; }
    }
    .animate-fade-in { animation: pms-fade-in 0.2s ease both; }
</style>

<!-- Global DOM Elements -->
<div id="global-toast-container"></div>

<!-- Confirm Modal -->
<div id="pms-confirm-overlay" role="dialog" aria-modal="true">
    <div id="pms-confirm-box">
        <div class="flex items-start gap-3 mb-5">
            <div id="pms-confirm-icon" class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 bg-amber-50">
                <i class="ph-fill ph-warning text-amber-500 text-xl"></i>
            </div>
            <div>
                <h3 id="pms-confirm-title" class="font-bold text-slate-900 text-base font-display">Are you sure?</h3>
                <p id="pms-confirm-message" class="text-sm text-slate-500 mt-1 leading-relaxed">This action cannot be undone.</p>
            </div>
        </div>
        <div class="flex gap-2 justify-end">
            <button id="pms-confirm-cancel" onclick="pmsConfirmResolve(false)"
                class="btn-secondary text-sm px-5 py-2.5">Cancel</button>
            <button id="pms-confirm-ok" onclick="pmsConfirmResolve(true)"
                class="btn-minimal text-sm px-5 py-2.5">Confirm</button>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div id="pms-loading-overlay">
    <div class="pms-spinner"></div>
    <p class="text-xs font-semibold text-slate-500">Processing...</p>
</div>

<script>
    // ─── Global PMS Helpers ───────────────────────────────────────────────

    /**
     * Hotel branding (from PHP)
     */
    window.PMS_HOTEL_NAME = <?= json_encode($_pms_hotel_name) ?>;
    window.PMS_HOTEL_LOGO = <?= json_encode($_pms_hotel_logo) ?>;

    /**
     * Format a number as Indian Rupee currency.
     * @param {number|string} amount
     * @param {boolean} showSymbol
     */
    function formatCurrency(amount, showSymbol = true) {
        const num = parseFloat(amount) || 0;
        const formatted = num.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return showSymbol ? '₹' + formatted : formatted;
    }

    /**
     * Format a datetime string into a human-readable date.
     * @param {string} dateStr
     * @param {object} opts  Intl.DateTimeFormat options
     */
    function formatDate(dateStr, opts = { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) {
        if (!dateStr) return '—';
        const d = new Date(dateStr.replace(' ', 'T'));
        return d.toLocaleString('en-IN', opts);
    }

    /**
     * Format just the date portion.
     */
    function formatDateOnly(dateStr) {
        return formatDate(dateStr, { day: 'numeric', month: 'short', year: 'numeric' });
    }

    /**
     * Displays a styled toast notification.
     * @param {string} message
     * @param {'success'|'error'|'info'|'warning'} type
     * @param {number} duration  ms before auto-dismiss
     */
    function showToast(message, type = 'info', duration = 4000) {
        const container = document.getElementById('global-toast-container');
        if (!container) return;

        const icons = { success: 'ph-fill ph-check-circle', error: 'ph-fill ph-warning-circle', info: 'ph-fill ph-info', warning: 'ph-fill ph-warning' };
        const toast = document.createElement('div');
        toast.className = `web-toast toast-${type}`;
        toast.innerHTML = `
            <i class="${icons[type] || icons.info} toast-icon"></i>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-slate-800 leading-snug">${message}</p>
            </div>
            <button class="web-toast-close" onclick="dismissToast(this.parentElement)">
                <i class="ph ph-x text-sm"></i>
            </button>
            <div class="toast-progress" style="animation-duration: ${duration}ms"></div>
        `;
        container.appendChild(toast);
        setTimeout(() => dismissToast(toast), duration);
    }

    function dismissToast(toast) {
        if (!toast || !toast.parentElement) return;
        toast.style.animation = 'toast-slide-out 0.25s cubic-bezier(0.4,0,1,1) forwards';
        setTimeout(() => toast.remove(), 250);
    }

    // ─── Beautiful Confirm Dialog (replaces window.confirm) ───

    let _pmsConfirmResolve = null;

    function pmsConfirmResolve(result) {
        const overlay = document.getElementById('pms-confirm-overlay');
        overlay.classList.remove('show');
        if (_pmsConfirmResolve) { _pmsConfirmResolve(result); _pmsConfirmResolve = null; }
    }

    /**
     * Show a beautiful confirm dialog. Returns a Promise<boolean>.
     * Usage: if (await pmsConfirm('Delete this booking?')) { ... }
     */
    function pmsConfirm(message, title = 'Confirm Action', type = 'warning') {
        return new Promise(resolve => {
            _pmsConfirmResolve = resolve;
            document.getElementById('pms-confirm-title').textContent = title;
            document.getElementById('pms-confirm-message').textContent = message;

            const iconMap = {
                danger:  { icon: 'ph-fill ph-trash', bg: 'bg-red-50', color: 'text-red-500', btnClass: 'btn-danger-min', btnText: 'Delete' },
                warning: { icon: 'ph-fill ph-warning', bg: 'bg-amber-50', color: 'text-amber-500', btnClass: 'btn-minimal', btnText: 'Confirm' },
                info:    { icon: 'ph-fill ph-info', bg: 'bg-blue-50', color: 'text-blue-500', btnClass: 'btn-minimal', btnText: 'Proceed' },
            };
            const cfg = iconMap[type] || iconMap.warning;
            const iconBox = document.getElementById('pms-confirm-icon');
            iconBox.className = `w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 ${cfg.bg}`;
            iconBox.innerHTML = `<i class="${cfg.icon} ${cfg.color} text-xl"></i>`;
            const okBtn = document.getElementById('pms-confirm-ok');
            okBtn.className = `${cfg.btnClass} text-sm px-5 py-2.5`;
            okBtn.textContent = cfg.btnText;

            document.getElementById('pms-confirm-overlay').classList.add('show');
        });
    }

    // ─── Loading Overlay ───

    function showLoading(msg = 'Processing...') {
        const el = document.getElementById('pms-loading-overlay');
        el.querySelector('p').textContent = msg;
        el.classList.add('show');
    }
    function hideLoading() {
        document.getElementById('pms-loading-overlay').classList.remove('show');
    }

    // ─── Global Keyboard Shortcuts ───

    document.addEventListener('keydown', (e) => {
        // Esc closes overlays / drawers
        if (e.key === 'Escape') {
            // Close confirm modal if open
            const confirmOverlay = document.getElementById('pms-confirm-overlay');
            if (confirmOverlay && confirmOverlay.classList.contains('show')) {
                pmsConfirmResolve(false);
                return;
            }
            // Close drawers
            if (typeof closeDrawer === 'function') closeDrawer();
            if (typeof closeBottomSheet === 'function') closeBottomSheet();
        }
    });

    // ─── Dismiss confirm on overlay click ───
    document.getElementById('pms-confirm-overlay')?.addEventListener('click', function(e) {
        if (e.target === this) pmsConfirmResolve(false);
    });
</script>
<script src="/js/ui.js"></script>
