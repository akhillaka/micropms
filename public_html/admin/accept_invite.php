<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/services/SaaSEntitlementsService.php';

$db = Database::getInstance()->getConnection();

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$invitation = null;
$error = null;
$success = false;

if (empty($token)) {
    die("Error: Invalid or missing invitation token.");
}

// Fetch invitation
try {
    $stmt = $db->prepare("SELECT ti.*, p.name as property_name FROM team_invitations ti JOIN properties p ON ti.property_id = p.id WHERE ti.token = ? AND ti.expires_at > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $invitation = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "System Database error: " . $e->getMessage();
}

if (!$invitation) {
    $error = "This invitation token is invalid, expired, or has already been used.";
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invitation) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $pin = trim($_POST['pin'] ?? '');
    
    if (empty($username) || empty($password) || empty($pin)) {
        $error = "All fields are required.";
    } elseif (!preg_match('/^\d{4}$/', $pin)) {
        $error = "PWA PIN must be exactly 4 numeric digits.";
    } else {
        try {
            // Verify if workspace limit has been reached
            SaaSEntitlementsService::checkStaffLimit($db, (int)$invitation['property_id']);
            
            $db->beginTransaction();
            
            // Insert new staff user
            $passHash = password_hash($password, PASSWORD_BCRYPT);
            $pinHash = password_hash($pin, PASSWORD_BCRYPT);
            $accessLevel = $invitation['role'] === 'owner' ? 'owner' : 'manager';
            
            $userStmt = $db->prepare("
                INSERT INTO staff_users (username, password_hash, pin_hash, access_level, role, property_id, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            $userStmt->execute([$username, $passHash, $pinHash, $accessLevel, ucfirst($invitation['role']), $invitation['property_id']]);
            
            // Delete token so it cannot be reused
            $delStmt = $db->prepare("DELETE FROM team_invitations WHERE id = ?");
            $delStmt->execute([$invitation['id']]);
            
            $db->commit();
            $success = true;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accept Workspace Invitation | MicroPMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-[#050811] text-slate-100 flex items-center justify-center min-h-screen px-4">
    <div class="bg-slate-900 border border-slate-800 p-8 rounded-2xl w-full max-w-md shadow-2xl relative overflow-hidden">
        
        <div class="text-center mb-6">
            <span class="inline-block p-3 bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 rounded-2xl mb-4">
                <i class="ph ph-envelope-open text-3xl"></i>
            </span>
            <h2 class="text-2xl font-black text-white tracking-tight">Join Workspace</h2>
            <?php if ($invitation): ?>
                <p class="text-slate-400 text-xs mt-1">You have been invited to join <strong><?= htmlspecialchars((string)($invitation['property_name'])) ?></strong> as a <strong><?= htmlspecialchars((string)(ucfirst($invitation['role']))) ?></strong></p>
            <?php endif; ?>
        </div>

        <?php if ($success): ?>
            <div class="p-4 bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 rounded-xl text-sm font-bold text-center space-y-3">
                <p>🎉 Welcome aboard! Your account has been registered successfully.</p>
                <a href="login.php" class="block w-full py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg text-xs font-black transition">
                    Go to Login Portal
                </a>
            </div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="p-3 bg-rose-500/10 border border-rose-500/30 text-rose-400 rounded-xl text-xs font-bold text-center mb-4">
                    ⚠️ <?= htmlspecialchars((string)($error)) ?>
                </div>
            <?php endif; ?>

            <?php if ($invitation): ?>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="token" value="<?= htmlspecialchars((string)($token)) ?>">
                    
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">Username / Login Name</label>
                        <input type="text" name="username" required placeholder="e.g. alex_pms" class="w-full bg-slate-950/80 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-xs focus:border-indigo-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">Password</label>
                        <input type="password" name="password" required placeholder="••••••••" class="w-full bg-slate-950/80 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-xs focus:border-indigo-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">PWA PIN (4 Digits)</label>
                        <input type="password" name="pin" maxlength="4" required placeholder="1234" class="w-full bg-slate-950/80 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-xs focus:border-indigo-500 focus:outline-none text-center tracking-widest font-bold">
                    </div>
                    
                    <button type="submit" class="w-full py-3 bg-indigo-600 hover:bg-indigo-500 text-white font-extrabold rounded-xl text-sm transition shadow-lg shadow-indigo-500/10">
                        Create Account & Join
                    </button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
