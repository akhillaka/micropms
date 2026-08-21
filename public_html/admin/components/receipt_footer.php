<?php
declare(strict_types=1);
/**
 * Shared receipt footer — hotel thank-you + MicroPMS digital receipt branding.
 * Set $receipt_thanks / $receipt_contact before include to customize copy.
 */
$receipt_thanks = $receipt_thanks ?? 'Thank you for your business.';
$receipt_contact = $receipt_contact ?? 'If you have any questions regarding this receipt, please contact us.';
?>
<div class="pt-8 print:pt-4 border-t border-slate-200 text-center break-inside-avoid">
    <p class="text-xs font-medium text-slate-400 mb-1"><?= htmlspecialchars((string)$receipt_thanks, ENT_QUOTES, 'UTF-8') ?></p>
    <p class="text-xs text-slate-400 mb-4"><?= htmlspecialchars((string)$receipt_contact, ENT_QUOTES, 'UTF-8') ?></p>
    <div class="flex items-center justify-center gap-1.5 text-[10px] text-slate-400 print:text-[9px]">
        <span>Digital receipt</span>
        <span aria-hidden="true">·</span>
        <span class="inline-flex items-center gap-1">
            Powered by
            <img src="/icons/logo-wordmark.svg" alt="MicroPMS" class="h-5 w-auto object-contain" width="90" height="20">
        </span>
    </div>
</div>
