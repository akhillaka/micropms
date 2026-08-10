<?php
require_once __DIR__ . '/../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

AuthHelper::requireLoginOrRedirect();

// For now, restricting this to manage_settings permission (Admin/Owner)
if (!AuthHelper::can('manage_settings')) {
    header('Location: index.php');
    exit;
}

CsrfToken::checkTimeout();

$db = Database::getInstance()->getConnection();
$propertyId = AuthHelper::getPropertyId();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CsrfToken::verify($_POST['csrf_token'] ?? '')) {
        $error = "Security token expired. Please try again.";
    } else {
        $titles = $_POST['banner_title'] ?? [];
        $subtitles = $_POST['banner_subtitle'] ?? [];
        $actions = $_POST['banner_action'] ?? [];
        $descriptions = $_POST['banner_description'] ?? [];
        $images = $_POST['banner_image'] ?? [];
        
        $newBanners = [];
        
        for ($i = 0; $i < count($titles); $i++) {
            $t = trim($titles[$i]);
            if (!empty($t)) {
                $newBanners[] = [
                    'title' => $t,
                    'subtitle' => trim($subtitles[$i]),
                    'action' => trim($actions[$i]),
                    'description' => trim($descriptions[$i] ?? ''),
                    'image' => trim($images[$i] ?? '')
                ];
            }
        }
        
        $jsonStr = json_encode($newBanners);
        
        $stmt = $db->prepare("INSERT INTO system_settings (property_id, key_name, key_value) VALUES (?, 'GUEST_PORTAL_BANNERS', ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
        
        if ($stmt->execute([$propertyId, $jsonStr])) {
            AuditLogger::log($_SESSION['user_id'] ?? 0, 'GUEST_PORTAL_BANNERS_UPDATED', 'PROPERTY', $propertyId, ['count' => count($newBanners)], $propertyId);
            $message = "Guest portal banners updated successfully!";
        } else {
            $error = "Failed to update banners.";
        }
    }
}

// Fetch current banners
$stmt = $db->prepare("SELECT key_value FROM system_settings WHERE property_id = ? AND key_name = 'GUEST_PORTAL_BANNERS'");
$stmt->execute([$propertyId]);
$bannersJson = $stmt->fetchColumn();

if (!$bannersJson) {
    // Try to get default
    $stmtDef = $db->prepare("SELECT key_value FROM system_settings WHERE property_id = 0 AND key_name = 'GUEST_PORTAL_BANNERS'");
    $stmtDef->execute();
    $bannersJson = $stmtDef->fetchColumn();
}

$banners = $bannersJson ? json_decode($bannersJson, true) : [];
if (!is_array($banners)) $banners = [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Portal Settings | StayFlexi</title>
    <?php include __DIR__ . '/components/ui_head.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 flex flex-col min-h-screen font-sans">
    
    <!-- Top Nav (Mobile) -->
    <?php include __DIR__ . '/components/mobile_nav.php'; ?>
    
    <!-- App Bar -->
    <header class="bg-white px-5 py-4 flex items-center justify-between z-10 border-b border-slate-100 sticky top-0">
        <div class="flex items-center gap-3">
            <a href="settings.php" class="p-2 -ml-2 rounded-full hover:bg-slate-100 active:bg-slate-200 transition-colors">
                <i class="ph ph-caret-left text-2xl text-slate-800"></i>
            </a>
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Guest Portal Banners</h1>
        </div>
        <?php include __DIR__ . '/components/desktop_nav.php'; ?>
    </header>

    <div class="flex flex-1 overflow-hidden">
        <main class="flex-1 overflow-y-auto p-4 lg:p-8">
            <div class="max-w-4xl mx-auto space-y-6">
                
                <div>
                    <p class="text-sm text-gray-500">Configure the scrolling highlight cards shown on the guest portal dashboard.</p>
                </div>

                <?php if ($message): ?>
                    <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg flex items-center gap-3">
                        <i class="ph-fill ph-check-circle text-xl"></i>
                        <span class="text-sm font-semibold"><?= htmlspecialchars($message) ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg flex items-center gap-3">
                        <i class="ph-fill ph-warning-circle text-xl"></i>
                        <span class="text-sm font-semibold"><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <div class="bg-white border border-gray-200 rounded-2xl shadow-sm p-6">
                    <form method="POST" id="bannersForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CsrfToken::generate()) ?>">
                        
                        <div id="bannersList" class="space-y-4 mb-6">
                            <?php foreach ($banners as $idx => $b): ?>
                            <div class="banner-row flex items-start gap-3 p-4 bg-gray-50 rounded-xl border border-gray-100 relative group">
                                <div class="flex-1 space-y-3">
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">Banner Title</label>
                                            <input type="text" name="banner_title[]" value="<?= htmlspecialchars($b['title'] ?? '') ?>" class="w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-brand-500 focus:ring-brand-500" required placeholder="e.g. Weekend Brunch">
                                        </div>
                                        <div>
                                            <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">Subtitle</label>
                                            <input type="text" name="banner_subtitle[]" value="<?= htmlspecialchars($b['subtitle'] ?? '') ?>" class="w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-brand-500 focus:ring-brand-500" placeholder="e.g. 10 AM - 2 PM">
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">Description</label>
                                        <textarea name="banner_description[]" class="w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-brand-500 focus:ring-brand-500" rows="2" placeholder="e.g. Enjoy our special weekend menu..."><?= htmlspecialchars($b['description'] ?? '') ?></textarea>
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">Action URL (Optional)</label>
                                            <input type="text" name="banner_action[]" value="<?= htmlspecialchars($b['action'] ?? '') ?>" class="w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-brand-500 focus:ring-brand-500" placeholder="https://...">
                                        </div>
                                        <div>
                                            <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">Image URL (Optional)</label>
                                            <input type="text" name="banner_image[]" value="<?= htmlspecialchars($b['image'] ?? '') ?>" class="w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-brand-500 focus:ring-brand-500" placeholder="https://.../image.jpg">
                                        </div>
                                    </div>
                                </div>
                                <button type="button" onclick="this.closest('.banner-row').remove()" class="mt-6 p-2 text-red-500 hover:bg-red-50 rounded-lg transition" title="Remove Banner">
                                    <i class="ph ph-trash text-lg"></i>
                                </button>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                            <button type="button" onclick="addBannerRow()" class="flex items-center gap-2 text-sm font-bold text-brand-600 hover:text-brand-800 transition">
                                <i class="ph ph-plus-circle text-lg"></i> Add New Banner
                            </button>
                            
                            <button type="submit" class="px-6 py-2.5 bg-brand-600 hover:bg-brand-700 text-white font-bold text-sm rounded-xl shadow-sm transition">
                                Save Settings
                            </button>
                        </div>
                    </form>
                </div>

            </div>
        </main>
    </div>

    <template id="bannerTemplate">
        <div class="banner-row flex items-start gap-3 p-4 bg-gray-50 rounded-xl border border-gray-100 relative group animate-fade-in">
            <div class="flex-1 space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">Banner Title</label>
                        <input type="text" name="banner_title[]" class="w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-brand-500 focus:ring-brand-500" required placeholder="e.g. Weekend Brunch">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">Subtitle</label>
                        <input type="text" name="banner_subtitle[]" class="w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-brand-500 focus:ring-brand-500" placeholder="e.g. 10 AM - 2 PM">
                    </div>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">Description</label>
                    <textarea name="banner_description[]" class="w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-brand-500 focus:ring-brand-500" rows="2" placeholder="e.g. Enjoy our special weekend menu..."></textarea>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">Action URL (Optional)</label>
                        <input type="text" name="banner_action[]" class="w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-brand-500 focus:ring-brand-500" placeholder="https://...">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">Image URL (Optional)</label>
                        <input type="text" name="banner_image[]" class="w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-brand-500 focus:ring-brand-500" placeholder="https://.../image.jpg">
                    </div>
                </div>
            </div>
            <button type="button" onclick="this.closest('.banner-row').remove()" class="mt-6 p-2 text-red-500 hover:bg-red-50 rounded-lg transition" title="Remove Banner">
                <i class="ph ph-trash text-lg"></i>
            </button>
        </div>
    </template>

    <script>
        function addBannerRow() {
            const template = document.getElementById('bannerTemplate');
            const clone = template.content.cloneNode(true);
            document.getElementById('bannersList').appendChild(clone);
        }
        
        <?php if(empty($banners)): ?>
        // Add one empty row if none exist
        addBannerRow();
        <?php endif; ?>
    </script>
</body>
</html>
