<?php
declare(strict_types=1);
/**
 * MicroPMS product mark for app headers (not the hotel property logo).
 */
$size = $micropms_mark_class ?? 'w-9 h-9';
?>
<img src="/icons/logo.svg" alt="MicroPMS" class="micropms-header-mark <?= htmlspecialchars($size, ENT_QUOTES, 'UTF-8') ?> rounded-xl object-contain bg-white border border-slate-200 shrink-0" width="36" height="36">
