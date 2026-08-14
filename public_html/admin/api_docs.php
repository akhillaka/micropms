<?php
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
AuthHelper::requireLoginOrRedirect();
$pageTitle = "API Documentation | MicroPMS";

$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http');
$host = $_SERVER['HTTP_HOST'] ?? 'yourdomain.com';
$baseUrl = $scheme . '://' . $host;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, ">
    <title><?= htmlspecialchars((string)($pageTitle), ENT_QUOTES, 'UTF-8') ?></title>
    <?php include __DIR__ . '/components/ui_head.php'; ?>
</head>
<body class="bg-brand-50 text-brand-900 font-sans antialiased flex flex-col min-h-screen">
    
    <!-- Header -->
    <header class="bg-white border-b-4 border-brand-900 p-4 sticky top-0 z-40 shrink-0">
        <div class="max-w-7xl mx-auto flex justify-between items-center">
            <div class="flex items-center gap-4">
                <a href="index.php" class="w-10 h-10 bg-brand-50 rounded-xl border border-brand-200 flex items-center justify-center text-brand-900 hover:bg-brand-100 transition-colors">
                    <i class="ph ph-arrow-left text-xl font-bold"></i>
                </a>
                <div>
                    <h1 class="text-2xl  font-semibold tracking-tight text-brand-900 leading-none">Developer API Docs</h1>
                    <p class="text-sm font-medium text-brand-900/60 mt-1">Webhooks & External Integrations</p>
                </div>
            </div>
            <a href="modules/whatsapp/whatsapp_automations.php" class="bg-brutal-green text-brand-900 font-semibold px-4 py-2 rounded-xl border border-brand-200 shadow-minimal hover:translate-y-1 hover: transition-all flex items-center gap-2">
                <i class="ph ph-whatsapp-logo text-xl"></i> Back to CRM
            </a>
        </div>
    </header>

    <div class="flex-1 max-w-4xl mx-auto w-full p-4 md:p-8 space-y-8">
        
        <!-- WhatsApp Webhook -->
        <div class="card-minimal p-6 md:p-8">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-12 h-12 rounded-xl bg-success-50 text-success-600 flex items-center justify-center border border-brand-200">
                    <i class="ph ph-whatsapp-logo text-2xl"></i>
                </div>
                <div>
                    <h2 class="text-xl font-semibold">Meta WhatsApp Webhook</h2>
                    <p class="text-sm text-brand-500 font-medium">For receiving inbound guest replies</p>
                </div>
            </div>
            <div class="bg-brand-50 border border-brand-200 rounded-xl p-4 space-y-4">
                <div>
                    <label class="block text-xs font-bold text-brand-500 uppercase tracking-wider mb-1">Callback URL</label>
                    <div class="flex gap-2">
                        <input type="text" readonly id="wa-url" value="<?= htmlspecialchars((string)($baseUrl), ENT_QUOTES, 'UTF-8') ?>/api/whatsapp/webhook" class="flex-1 bg-white border-2 border-brand-200 p-2.5 rounded-lg text-sm font-mono outline-none">
                        <button onclick="copyToClipboard('wa-url')" class="bg-brand-900 text-white px-4 py-2 rounded-lg font-bold text-sm hover:bg-brand-700 transition-colors">Copy</button>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-brand-500 uppercase tracking-wider mb-1">Verify Token</label>
                    <div class="flex gap-2">
                        <input type="text" readonly id="wa-token" value="micropms_wa_secure_token_123" class="flex-1 bg-white border-2 border-brand-200 p-2.5 rounded-lg text-sm font-mono outline-none">
                        <button onclick="copyToClipboard('wa-token')" class="bg-brand-900 text-white px-4 py-2 rounded-lg font-bold text-sm hover:bg-brand-700 transition-colors">Copy</button>
                    </div>
                </div>
                <div class="bg-warning-50 text-warning-800 p-3 rounded border border-warning-200 text-sm font-medium">
                    <strong>Instructions:</strong> Paste these values into the Meta Developer Dashboard under <strong>WhatsApp &rarr; Configuration</strong>. Make sure you also subscribe to the <code>messages</code> field!
                </div>
            </div>
        </div>

        <!-- Razorpay Webhook -->
        <div class="card-minimal p-6 md:p-8">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-12 h-12 rounded-xl bg-brand-accentLight text-brand-accent flex items-center justify-center border border-brand-200">
                    <i class="ph ph-currency-inr text-2xl"></i>
                </div>
                <div>
                    <h2 class="text-xl font-semibold">Razorpay Webhook</h2>
                    <p class="text-sm text-brand-500 font-medium">For capturing successful payments automatically</p>
                </div>
            </div>
            <div class="bg-brand-50 border border-brand-200 rounded-xl p-4 space-y-4">
                <div>
                    <label class="block text-xs font-bold text-brand-500 uppercase tracking-wider mb-1">Webhook URL</label>
                    <div class="flex gap-2">
                        <input type="text" readonly id="rzp-url" value="<?= htmlspecialchars((string)($baseUrl), ENT_QUOTES, 'UTF-8') ?>/api/admin_record_payment.php" class="flex-1 bg-white border-2 border-brand-200 p-2.5 rounded-lg text-sm font-mono outline-none">
                        <button onclick="copyToClipboard('rzp-url')" class="bg-brand-900 text-white px-4 py-2 rounded-lg font-bold text-sm hover:bg-brand-700 transition-colors">Copy</button>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-brand-500 uppercase tracking-wider mb-1">Secret (Configured in Settings)</label>
                    <div class="flex gap-2">
                        <input type="text" readonly id="rzp-secret" value="Configured in Settings (not shown)" class="flex-1 bg-white border-2 border-brand-200 p-2.5 rounded-lg text-sm font-mono outline-none">
                        <button onclick="copyToClipboard('rzp-secret')" class="bg-brand-900 text-white px-4 py-2 rounded-lg font-bold text-sm hover:bg-brand-700 transition-colors">Copy</button>
                    </div>
                </div>
                <div class="bg-brand-accentLight text-blue-800 p-3 rounded border border-brand-accent text-sm font-medium">
                    <strong>Events to subscribe:</strong> <code>payment.captured</code> and <code>order.paid</code>
                </div>
        </div>

        <!-- PhonePe Webhook -->
        <div class="card-minimal p-6 md:p-8">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-12 h-12 rounded-xl bg-violet-50 text-violet-600 flex items-center justify-center border border-brand-200">
                    <i class="ph ph-lightning text-2xl"></i>
                </div>
                <div>
                    <h2 class="text-xl font-semibold">PhonePe Webhook</h2>
                    <p class="text-sm text-brand-500 font-medium">For capturing successful payments automatically via PhonePe</p>
                </div>
            </div>
            <div class="bg-brand-50 border border-brand-200 rounded-xl p-4 space-y-4">
                <div>
                    <label class="block text-xs font-bold text-brand-500 uppercase tracking-wider mb-1">Webhook URL</label>
                    <div class="flex gap-2">
                        <input type="text" readonly id="phonepe-url" value="<?= htmlspecialchars((string)($baseUrl), ENT_QUOTES, 'UTF-8') ?>/webhook_phonepe" class="flex-1 bg-white border-2 border-brand-200 p-2.5 rounded-lg text-sm font-mono outline-none">
                        <button onclick="copyToClipboard('phonepe-url')" class="bg-brand-900 text-white px-4 py-2 rounded-lg font-bold text-sm hover:bg-brand-700 transition-colors">Copy</button>
                    </div>
                </div>
                <div class="bg-indigo-50 text-indigo-800 p-3 rounded border border-indigo-200 text-sm font-medium">
                    <strong>Instructions:</strong> Configure this callback URL in your PhonePe dashboard to receive real-time webhook callback notifications.
                </div>
            </div>
        </div>
        
    </div>

    <script>
        function copyToClipboard(elementId) {
            var copyText = document.getElementById(elementId);
            copyText.select();
            copyText.setSelectionRange(0, 99999);
            navigator.clipboard.writeText(copyText.value);
            
            // Visual feedback
            const btn = event.currentTarget;
            const originalText = btn.innerText;
            btn.innerText = "Copied!";
            btn.classList.replace('bg-brand-900', 'bg-brutal-green');
            btn.classList.replace('text-white', 'text-brand-900');
            setTimeout(() => {
                btn.innerText = originalText;
                btn.classList.replace('bg-brutal-green', 'bg-brand-900');
                btn.classList.replace('text-brand-900', 'text-white');
            }, 2000);
        }
    </script>
</body>
</html>
