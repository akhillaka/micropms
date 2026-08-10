<?php
require_once __DIR__ . '/../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
require_once __DIR__ . '/../../pms_core/ErrorPage.php';
AuthHelper::requireLoginOrRedirect();
CsrfToken::checkTimeout();

require_once __DIR__ . '/../../pms_core/Database.php';
$db = Database::getInstance()->getConnection();

$guestId = $_GET['id'] ?? null;
if (!$guestId) render_error_page('Missing Guest ID', 'A guest ID is required to view the profile.', 400);

$propertyId = AuthHelper::getPropertyId();

$guestStmt = $db->prepare("SELECT * FROM guests WHERE id = :id AND property_id = :property_id");
$guestStmt->execute(['id' => $guestId, 'property_id' => $propertyId]);
$guest = $guestStmt->fetch(PDO::FETCH_ASSOC);

if (!$guest) render_error_page('Guest Not Found', 'The requested guest profile does not exist.', 404);

// Metrics Query
$metricsStmt = $db->prepare("
    SELECT COUNT(id) as total_bookings, 
           SUM(total_amount) as total_spent, 
           MAX(check_in) as last_visit,
           AVG(total_amount) as avg_booking_value
    FROM bookings 
    WHERE guest_id = :id AND property_id = :property_id AND booking_status != 'cancelled'
");
$metricsStmt->execute(['id' => $guestId, 'property_id' => $propertyId]);
$metrics = $metricsStmt->fetch(PDO::FETCH_ASSOC);

$totalBookings = (int)($metrics['total_bookings'] ?? 0);
$totalSpent = (float)($metrics['total_spent'] ?? 0);
$avgBooking = (float)($metrics['avg_booking_value'] ?? 0);
$lastVisit = $metrics['last_visit'] ? date('M j, Y', strtotime($metrics['last_visit'])) : 'Never';

// Bookings Query
$bookingsStmt = $db->prepare("
    SELECT b.*, r.room_number 
    FROM bookings b
    JOIN rooms r ON b.room_id = r.id
    WHERE b.guest_id = :id AND b.property_id = :property_id
    ORDER BY b.check_in DESC
");
$bookingsStmt->execute(['id' => $guestId, 'property_id' => $propertyId]);
$bookings = $bookingsStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= CsrfToken::meta() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, ">
    <title>Guest Profile | MicroPMS</title>
    <?php include __DIR__ . '/components/ui_head.php'; ?>
    <?php include __DIR__ . '/components/mobile_nav.php'; ?>
</head>
<body class="flex flex-col min-h-screen bg-slate-50">
    <div class="w-full min-h-screen relative flex flex-col max-w-6xl mx-auto pb-24">
        
        <!-- App Bar -->
        <header class="bg-white px-5 py-4 flex items-center justify-between z-10 border-b border-slate-100 sticky top-0 mb-6">
            <div class="flex items-center gap-3">
                <a href="guests.php" class="p-2 -ml-2 rounded-full hover:bg-slate-100 active:bg-slate-200 transition-colors">
                    <i class="ph ph-caret-left text-2xl text-slate-800"></i>
                </a>
                <div>
                    <h1 class="text-xl font-extrabold text-slate-900 tracking-tight leading-none">Guest Profile</h1>
                    <span class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">ID #<?= htmlspecialchars((string)($guest['id'])) ?></span>
                </div>
            </div>
            <?php include __DIR__ . '/components/desktop_nav.php'; ?>
        </header>

        <main class="flex-1 p-4 md:p-8 space-y-8">
            
            <!-- Top Section: Profile + Metrics -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Profile Card -->
                <div class="card-minimal p-6 lg:col-span-2 flex flex-col sm:flex-row gap-6 items-start sm:items-center relative">
                    <div class="w-24 h-24 sm:w-32 sm:h-32 rounded-2xl bg-brand-900 flex items-center justify-center text-white text-4xl sm:text-5xl font-black shadow-minimal overflow-hidden shrink-0 border-4 border-white">
                        <?php if($guest['photo']): ?>
                            <img src="/api/admin/view_document?file=<?= htmlspecialchars((string)($guest['photo'])) ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                            <?= htmlspecialchars((string)(strtoupper(substr($guest['name'] ?: '?', 0, 1))), ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h2 class="text-3xl font-black text-brand-900 tracking-tight truncate"><?= htmlspecialchars((string)($guest['name'])) ?></h2>
                        
                        <div class="flex flex-wrap gap-2 mt-3">
                            <?php if($guest['phone']): ?>
                            <div class="bg-white border border-brand-200 px-3 py-1.5 rounded-lg flex items-center gap-2 text-sm font-bold text-brand-900 shadow-sm">
                                <i class="ph-fill ph-phone text-brand-400"></i> <?= htmlspecialchars((string)($guest['phone'])) ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if($guest['email']): ?>
                            <div class="bg-white border border-brand-200 px-3 py-1.5 rounded-lg flex items-center gap-2 text-sm font-bold text-brand-900 shadow-sm">
                                <i class="ph-fill ph-envelope text-brand-400"></i> <?= htmlspecialchars((string)($guest['email'])) ?>
                            </div>
                            <?php endif; ?>
                            
                            <div class="bg-white border border-brand-200 px-3 py-1.5 rounded-lg flex items-center gap-2 text-sm font-bold text-brand-900 shadow-sm">
                                <i class="ph-fill ph-map-pin text-brand-400"></i> 
                                <?= htmlspecialchars((string)(implode(', ', array_filter([$guest['city'], $guest['state']])))) ?: 'No Location' ?>
                            </div>
                        </div>

                        <div class="mt-4 flex gap-3">
                            <button onclick="openEditModal()" class="bg-brand-900 text-white font-bold px-5 py-2.5 rounded-xl hover:-translate-y-0.5 transition-all text-sm shadow-minimal flex items-center gap-2">
                                <i class="ph-bold ph-pencil-simple"></i> Edit Profile
                            </button>
                            <?php if(!empty($guest['phone'])): ?>
                                <a href="https://wa.me/<?= htmlspecialchars((string)(preg_replace('/[^0-9]/', '', $guest['phone'])), ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="bg-emerald-50 text-emerald-700 border border-emerald-200 font-bold px-5 py-2.5 rounded-xl hover:-translate-y-0.5 transition-all text-sm shadow-minimal flex items-center gap-2">
                                    <i class="ph-bold ph-whatsapp-logo text-lg"></i> Chat
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Metrics Column -->
                <div class="flex flex-col gap-4">
                    <div class="card-minimal p-5 flex items-center justify-between bg-gradient-to-br from-brand-900 to-brand-800 text-white">
                        <div>
                            <div class="text-[10px] font-bold text-white/60 uppercase tracking-widest mb-1">Lifetime Spent</div>
                            <div class="text-2xl font-black">₹<?= htmlspecialchars((string)(number_format($totalSpent, 2)), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="w-12 h-12 bg-white/10 rounded-full flex items-center justify-center">
                            <i class="ph-fill ph-money text-2xl"></i>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4 flex-1">
                        <div class="card-minimal p-4 flex flex-col justify-center">
                            <div class="text-[10px] font-bold text-brand-500 uppercase tracking-widest mb-1">Total Stays</div>
                            <div class="text-xl font-black text-brand-900"><?= htmlspecialchars((string)($totalBookings), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="card-minimal p-4 flex flex-col justify-center">
                            <div class="text-[10px] font-bold text-brand-500 uppercase tracking-widest mb-1">Avg Value</div>
                            <div class="text-xl font-black text-brand-900">₹<?= htmlspecialchars((string)(number_format($avgBooking)), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Documents Section -->
            <div>
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-8 h-8 rounded-lg bg-brand-200 text-brand-900 flex items-center justify-center font-bold">
                        <i class="ph-bold ph-identification-card"></i>
                    </div>
                    <h3 class="text-xl font-black text-brand-900 tracking-tight">Documents & Verification</h3>
                </div>
                <div class="card-minimal p-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <!-- ID Proof Front -->
                        <div class="border-2 border-dashed border-brand-300 rounded-2xl p-4 flex flex-col items-center justify-center text-center hover:border-brand-900 hover:bg-brand-50 transition-all relative overflow-hidden group min-h-[220px]">
                            <?php if($guest['id_proof_front']): ?>
                                <img src="/api/admin/view_document?file=<?= htmlspecialchars((string)($guest['id_proof_front'])) ?>" class="absolute inset-0 w-full h-full object-cover z-0 cursor-pointer" onclick="UI.viewImage('/api/admin/view_document?file=<?= htmlspecialchars((string)($guest['id_proof_front'])) ?>')">
                                <div class="absolute inset-0 bg-brand-900/80 opacity-0 group-hover:opacity-100 transition-opacity z-10 flex flex-col items-center justify-center gap-3 backdrop-blur-sm">
                                    <button onclick="UI.viewImage('/api/admin/view_document?file=<?= htmlspecialchars((string)($guest['id_proof_front'])) ?>')" class="cursor-pointer bg-white text-brand-900 px-5 py-2.5 rounded-xl text-sm font-bold shadow-minimal hover:-translate-y-0.5 transition-transform flex items-center gap-2"><i class="ph-bold ph-eye"></i> View</button>
                                    <label class="cursor-pointer bg-brand-800 text-white border border-brand-700 px-5 py-2.5 rounded-xl text-sm font-bold shadow-minimal hover:-translate-y-0.5 transition-transform">Replace<input type="file" class="hidden" onchange="uploadDoc('id_proof_front', this)"></label>
                                </div>
                            <?php else: ?>
                                <div class="w-16 h-16 rounded-full bg-brand-100 text-brand-400 flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                                    <i class="ph-fill ph-identification-card text-3xl"></i>
                                </div>
                                <p class="text-sm font-bold text-brand-900">ID Proof (Front)</p>
                                <p class="text-xs text-brand-500 font-medium mt-1 mb-3">Upload a clear photo</p>
                                <label class="cursor-pointer bg-brand-900 text-white px-5 py-2 rounded-xl text-xs font-bold hover:shadow-minimal transition-all">Upload File<input type="file" class="hidden" onchange="uploadDoc('id_proof_front', this)"></label>
                            <?php endif; ?>
                        </div>
                        
                        <!-- ID Proof Back -->
                        <div class="border-2 border-dashed border-brand-300 rounded-2xl p-4 flex flex-col items-center justify-center text-center hover:border-brand-900 hover:bg-brand-50 transition-all relative overflow-hidden group min-h-[220px]">
                            <?php if($guest['id_proof_back']): ?>
                                <img src="/api/admin/view_document?file=<?= htmlspecialchars((string)($guest['id_proof_back'])) ?>" class="absolute inset-0 w-full h-full object-cover z-0 cursor-pointer" onclick="UI.viewImage('/api/admin/view_document?file=<?= htmlspecialchars((string)($guest['id_proof_back'])) ?>')">
                                <div class="absolute inset-0 bg-brand-900/80 opacity-0 group-hover:opacity-100 transition-opacity z-10 flex flex-col items-center justify-center gap-3 backdrop-blur-sm">
                                    <button onclick="UI.viewImage('/api/admin/view_document?file=<?= htmlspecialchars((string)($guest['id_proof_back'])) ?>')" class="cursor-pointer bg-white text-brand-900 px-5 py-2.5 rounded-xl text-sm font-bold shadow-minimal hover:-translate-y-0.5 transition-transform flex items-center gap-2"><i class="ph-bold ph-eye"></i> View</button>
                                    <label class="cursor-pointer bg-brand-800 text-white border border-brand-700 px-5 py-2.5 rounded-xl text-sm font-bold shadow-minimal hover:-translate-y-0.5 transition-transform">Replace<input type="file" class="hidden" onchange="uploadDoc('id_proof_back', this)"></label>
                                </div>
                            <?php else: ?>
                                <div class="w-16 h-16 rounded-full bg-brand-100 text-brand-400 flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                                    <i class="ph-fill ph-identification-card text-3xl"></i>
                                </div>
                                <p class="text-sm font-bold text-brand-900">ID Proof (Back)</p>
                                <p class="text-xs text-brand-500 font-medium mt-1 mb-3">Upload a clear photo</p>
                                <label class="cursor-pointer bg-brand-900 text-white px-5 py-2 rounded-xl text-xs font-bold hover:shadow-minimal transition-all">Upload File<input type="file" class="hidden" onchange="uploadDoc('id_proof_back', this)"></label>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Guest Photo -->
                        <div class="border-2 border-dashed border-brand-300 rounded-2xl p-4 flex flex-col items-center justify-center text-center hover:border-brand-900 hover:bg-brand-50 transition-all relative overflow-hidden group min-h-[220px]">
                            <?php if($guest['photo']): ?>
                                <img src="/api/admin/view_document?file=<?= htmlspecialchars((string)($guest['photo'])) ?>" class="absolute inset-0 w-full h-full object-cover z-0 cursor-pointer" onclick="UI.viewImage('/api/admin/view_document?file=<?= htmlspecialchars((string)($guest['photo'])) ?>')">
                                <div class="absolute inset-0 bg-brand-900/80 opacity-0 group-hover:opacity-100 transition-opacity z-10 flex flex-col items-center justify-center gap-3 backdrop-blur-sm">
                                    <button onclick="UI.viewImage('/api/admin/view_document?file=<?= htmlspecialchars((string)($guest['photo'])) ?>')" class="cursor-pointer bg-white text-brand-900 px-5 py-2.5 rounded-xl text-sm font-bold shadow-minimal hover:-translate-y-0.5 transition-transform flex items-center gap-2"><i class="ph-bold ph-eye"></i> View</button>
                                    <label class="cursor-pointer bg-brand-800 text-white border border-brand-700 px-5 py-2.5 rounded-xl text-sm font-bold shadow-minimal hover:-translate-y-0.5 transition-transform">Replace<input type="file" class="hidden" onchange="uploadDoc('photo', this)"></label>
                                </div>
                            <?php else: ?>
                                <div class="w-16 h-16 rounded-full bg-brand-100 text-brand-400 flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                                    <i class="ph-fill ph-camera text-3xl"></i>
                                </div>
                                <p class="text-sm font-bold text-brand-900">Guest Photo</p>
                                <p class="text-xs text-brand-500 font-medium mt-1 mb-3">Face clearly visible</p>
                                <label class="cursor-pointer bg-brand-900 text-white px-5 py-2 rounded-xl text-xs font-bold hover:shadow-minimal transition-all">Upload File<input type="file" class="hidden" onchange="uploadDoc('photo', this)"></label>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Booking History -->
            <div>
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-8 h-8 rounded-lg bg-brand-200 text-brand-900 flex items-center justify-center font-bold">
                        <i class="ph-bold ph-clock-counter-clockwise"></i>
                    </div>
                    <h3 class="text-xl font-black text-brand-900 tracking-tight">Stay History</h3>
                </div>
                <div class="card-minimal overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="table-brutal">
                            <thead>
                                <tr class="bg-brand-50 border-b border-brand-100 text-[10px] text-brand-500 uppercase tracking-widest">
                                    <th class="px-6 py-4 font-bold text-left">Folio</th>
                                    <th class="px-6 py-4 font-bold text-left">Room</th>
                                    <th class="px-6 py-4 font-bold text-left">Dates</th>
                                    <th class="px-6 py-4 font-bold text-center">Status</th>
                                    <th class="px-6 py-4 font-bold text-right">Total</th>
                                    <th class="px-6 py-4 font-bold text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach($bookings as $b): ?>
                                <tr class="hover:bg-brand-50 transition-colors group">
                                    <td class="px-6 py-4 font-black text-brand-900">#<?= htmlspecialchars((string)($b['id']), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-6 py-4 font-bold text-brand-900"><?= htmlspecialchars((string)($b['room_number']), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-bold text-brand-900"><?= htmlspecialchars((string)(date('M j, Y', strtotime($b['check_in']))), ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="text-[11px] text-brand-500 font-medium">to <?= htmlspecialchars((string)(date('M j, Y', strtotime($b['check_out']))), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <?php if($b['payment_status'] === 'completed_paid'): ?>
                                            <span class="inline-flex items-center justify-center px-2 py-1 rounded bg-success-100 text-success-700 text-[10px] font-bold uppercase tracking-widest border border-success-200">Paid</span>
                                        <?php elseif($b['payment_status'] === 'cancelled'): ?>
                                            <span class="inline-flex items-center justify-center px-2 py-1 rounded bg-error-100 text-error-700 text-[10px] font-bold uppercase tracking-widest border border-error-200">Cancelled</span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center justify-center px-2 py-1 rounded bg-orange-100 text-orange-700 text-[10px] font-bold uppercase tracking-widest border border-orange-200">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="text-sm font-black text-brand-900">₹<?= htmlspecialchars((string)(number_format($b['total_amount'], 2)), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <a href="folio.php?id=<?= htmlspecialchars((string)($b['id']), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-1.5 text-brand-900 bg-white border border-brand-200 hover:border-brand-900 font-bold text-xs px-3 py-1.5 rounded-lg transition-all shadow-sm opacity-0 group-hover:opacity-100 focus:opacity-100">
                                            <i class="ph-bold ph-receipt"></i> View Folio
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if(empty($bookings)): ?>
                            <div class="p-12 text-center text-brand-400 flex flex-col items-center">
                                <div class="w-16 h-16 bg-brand-50 rounded-full flex items-center justify-center mb-4">
                                    <i class="ph-fill ph-bed text-3xl text-brand-300"></i>
                                </div>
                                <p class="text-lg font-bold text-brand-900/70">No past stays recorded.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </main>
    </div>
    
    <!-- Edit Guest Modal -->
    <div id="edit-guest-modal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeEditModal()"></div>
        <div class="absolute bottom-0 left-0 right-0 md:top-1/2 md:-translate-y-1/2 md:bottom-auto modal-brutal max-h-[90vh] overflow-y-auto w-full max-w-lg mx-auto">
            <div class="w-12 h-1.5 bg-brand-200 rounded-full mx-auto mb-6 md:hidden"></div>
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-black text-brand-900">Edit Guest Details</h2>
                <button onclick="closeEditModal()" class="w-8 h-8 rounded-full bg-brand-50 text-brand-900 flex items-center justify-center hover:bg-brand-100 transition-colors hidden md:flex"><i class="ph-bold ph-x"></i></button>
            </div>
            
            <div class="space-y-4">
                <div>
                    <label class="block text-[10px] font-bold text-brand-500 uppercase tracking-wider mb-1">Guest Name</label>
                    <input type="text" id="edit_g_name" value="<?= htmlspecialchars((string)($guest['name'])) ?>" class="w-full bg-brand-50 border border-brand-200 rounded-xl p-3 font-bold text-sm outline-none focus:bg-white focus:shadow-minimal transition-all">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-brand-500 uppercase tracking-wider mb-1">WhatsApp Number</label>
                    <input type="text" id="edit_g_phone" value="<?= htmlspecialchars((string)($guest['phone'] ?? '')) ?>" class="w-full bg-brand-50 border border-brand-200 rounded-xl p-3 font-mono font-bold text-sm outline-none focus:bg-white focus:shadow-minimal transition-all">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-brand-500 uppercase tracking-wider mb-1">Email Address</label>
                    <input type="email" id="edit_g_email" value="<?= htmlspecialchars((string)($guest['email'] ?? '')) ?>" class="w-full bg-brand-50 border border-brand-200 rounded-xl p-3 font-medium text-sm outline-none focus:bg-white focus:shadow-minimal transition-all">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-brand-500 uppercase tracking-wider mb-1">Age</label>
                    <input type="number" id="edit_g_age" value="<?= htmlspecialchars((string)($guest['age'] ?? '')) ?>" min="1" max="120" class="w-full bg-brand-50 border border-brand-200 rounded-xl p-3 font-medium text-sm outline-none focus:bg-white focus:shadow-minimal transition-all">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold text-brand-500 uppercase tracking-wider mb-1">City</label>
                        <input type="text" id="edit_g_city" value="<?= htmlspecialchars((string)($guest['city'] ?? '')) ?>" class="w-full bg-brand-50 border border-brand-200 rounded-xl p-3 font-medium text-sm outline-none focus:bg-white focus:shadow-minimal transition-all">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-brand-500 uppercase tracking-wider mb-1">State</label>
                        <input type="text" id="edit_g_state" value="<?= htmlspecialchars((string)($guest['state'] ?? '')) ?>" class="w-full bg-brand-50 border border-brand-200 rounded-xl p-3 font-medium text-sm outline-none focus:bg-white focus:shadow-minimal transition-all">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold text-brand-500 uppercase tracking-wider mb-1">Country</label>
                        <input type="text" id="edit_g_country" value="<?= htmlspecialchars((string)($guest['country'] ?? 'India')) ?>" class="w-full bg-brand-50 border border-brand-200 rounded-xl p-3 font-medium text-sm outline-none focus:bg-white focus:shadow-minimal transition-all">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-brand-500 uppercase tracking-wider mb-1">Pincode</label>
                        <input type="text" id="edit_g_pincode" value="<?= htmlspecialchars((string)($guest['pincode'] ?? '')) ?>" maxlength="6" class="w-full bg-brand-50 border border-brand-200 rounded-xl p-3 font-medium text-sm outline-none focus:bg-white focus:shadow-minimal transition-all">
                    </div>
                </div>
                <div class="pt-2">
                    <button onclick="saveGuestEdit(this)" class="w-full bg-brand-900 text-white rounded-xl py-3.5 font-bold shadow-minimal hover:-translate-y-0.5 transition-all">Save Details</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Image Viewer Modal -->
    <div id="image-viewer-modal" class="fixed inset-0 z-[60] hidden">
        <div class="absolute inset-0 bg-black/95 backdrop-blur-md" onclick="UI.closeImageViewer()"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <button onclick="UI.closeImageViewer()" class="absolute top-4 right-4 w-12 h-12 bg-white/10 hover:bg-white/20 text-white rounded-full flex items-center justify-center transition-colors z-10">
                <i class="ph-bold ph-x text-2xl"></i>
            </button>
            <img id="viewer-image" src="" class="max-w-full max-h-full object-contain rounded-lg shadow-2xl ring-1 ring-white/20">
        </div>
    </div>
    
    <script>
        const guestId = <?= htmlspecialchars((string)($guestId), ENT_QUOTES, 'UTF-8') ?>;
        
        function openEditModal() {
            document.getElementById('edit-guest-modal').classList.remove('hidden');
        }
        
        function closeEditModal() {
            document.getElementById('edit-guest-modal').classList.add('hidden');
        }
        
        async function saveGuestEdit(btn) {
            const name = document.getElementById('edit_g_name').value;
            const phone = document.getElementById('edit_g_phone').value;
            const email = document.getElementById('edit_g_email').value;
            const age = document.getElementById('edit_g_age').value;
            const city = document.getElementById('edit_g_city').value;
            const state = document.getElementById('edit_g_state').value;
            const country = document.getElementById('edit_g_country').value;
            const pincode = document.getElementById('edit_g_pincode').value;
            
            btn.disabled = true;
            btn.innerText = 'Saving...';
            
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            try {
                const res = await fetch('/api/admin/edit_guest_profile', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({
                        guest_id: guestId,
                        name: name,
                        phone: phone,
                        email: email,
                        age: age,
                        city: city,
                        state: state,
                        country: country,
                        pincode: pincode
                    })
                });
                const data = await res.json();
                if (data.success) {
                    location.reload();
                } else {
                    showToast(data.message || 'Save failed');
                    btn.disabled = false;
                    btn.innerText = 'Save Details';
                }
            } catch(e) {
                showToast('Connection error');
                btn.disabled = false;
                btn.innerText = 'Save Details';
            }
        }
        
        async function uploadDoc(type, input) {
            if(!input.files || input.files.length === 0) return;
            
            const file = input.files[0];
            const ext = file.name.split('.').pop().toLowerCase();
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            
            if (['jpg', 'jpeg', 'png'].includes(ext)) {
                try {
                    const result = await UI.compressImage(file, 1000, 0.7, 500 * 1024);
                    const compressedFile = new File([result.blob], file.name.replace(/\.[^.]+$/, '.jpg'), { type: 'image/jpeg' });
                    
                    const formData = new FormData();
                    formData.append('file', compressedFile);
                    formData.append('doc_type', type);
                    formData.append('guest_id', guestId);
                    formData.append('_csrf_token', csrfToken);
                    
                    const res = await fetch('/api/admin/upload_document', {
                        method: 'POST', body: formData
                    });
                    const text = await res.text();
                    try {
                        const data = JSON.parse(text);
                        if(data.success) location.reload(); else showToast(data.message);
                    } catch(e) {
                        showToast('Server error: ' + text.substring(0, 200));
                    }
                } catch(e) {
                    showToast('Compression failed: ' + e.message);
                }
            } else {
                const formData = new FormData();
                formData.append('file', file);
                formData.append('doc_type', type);
                formData.append('guest_id', guestId);
                formData.append('_csrf_token', csrfToken);
                
                const res = await fetch('/api/admin/upload_document', {
                    method: 'POST', body: formData
                });
                const text = await res.text();
                try {
                    const data = JSON.parse(text);
                    if(data.success) location.reload(); else showToast(data.message);
                } catch(e) {
                    showToast('Server error: ' + text.substring(0, 200));
                }
            }
        }
    </script>
</body>
</html>
