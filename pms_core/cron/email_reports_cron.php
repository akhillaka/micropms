<?php
/**
 * Cron script to dispatch daily night audit and weekly revenue reports via Email.
 * Usage: php pms_core/cron/email_reports_cron.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../EmailService.php';

try {
    $db = Database::getInstance()->getConnection();
    
    // Get all active email configurations
    $configsStmt = $db->query("SELECT * FROM email_report_config WHERE is_active = 1");
    $configs = $configsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $isMonday = (date('N') === '1'); // 1 = Monday
    $lastWeekStart = date('Y-m-d', strtotime('-7 days'));
    
    foreach ($configs as $config) {
        $propertyId = (int)$config['property_id'];
        
        // 1. Dispatch Daily Audit
        if (!empty($config['daily_audit_emails'])) {
            // Fetch yesterday's audit
            $auditStmt = $db->prepare("SELECT * FROM night_audit_log WHERE property_id = ? AND audit_date = ?");
            $auditStmt->execute([$propertyId, $yesterday]);
            $audit = $auditStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($audit) {
                $subject = "Daily Night Audit Report - {$yesterday}";
                
                $htmlBody = "
                <div style='font-family: sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;'>
                    <h2 style='color: #4F46E5;'>Night Audit Summary</h2>
                    <p><strong>Date:</strong> {$yesterday}</p>
                    <table style='width: 100%; border-collapse: collapse; margin-top: 15px;'>
                        <tr><td style='padding: 8px; border-bottom: 1px solid #eee;'><strong>Total Revenue:</strong></td><td style='padding: 8px; border-bottom: 1px solid #eee;'>₹" . number_format((float)$audit['total_revenue'], 2) . "</td></tr>
                        <tr><td style='padding: 8px; border-bottom: 1px solid #eee;'><strong>Total Payments:</strong></td><td style='padding: 8px; border-bottom: 1px solid #eee;'>₹" . number_format((float)$audit['total_payments'], 2) . "</td></tr>
                        <tr><td style='padding: 8px; border-bottom: 1px solid #eee;'><strong>Occupancy Rate:</strong></td><td style='padding: 8px; border-bottom: 1px solid #eee;'>" . number_format((float)$audit['occupancy_pct'], 1) . "%</td></tr>
                        <tr><td style='padding: 8px; border-bottom: 1px solid #eee;'><strong>ARR (Avg Room Rate):</strong></td><td style='padding: 8px; border-bottom: 1px solid #eee;'>₹" . number_format((float)$audit['arr'], 2) . "</td></tr>
                    </table>
                    <p style='color: #888; font-size: 12px; margin-top: 20px;'>Generated automatically by MicroPMS.</p>
                </div>";
                
                $emails = array_map('trim', explode(',', $config['daily_audit_emails']));
                foreach ($emails as $email) {
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        EmailService::send($email, $subject, $htmlBody);
                        echo "Sent Daily Audit to $email for Property ID $propertyId\n";
                    }
                }
            }
        }
        
        // 2. Dispatch Weekly Revenue (Only on Mondays)
        if ($isMonday && !empty($config['weekly_revenue_emails'])) {
            $subject = "Weekly Revenue Report ({$lastWeekStart} to {$yesterday})";
            
            // Calculate weekly totals
            $revStmt = $db->prepare("SELECT COALESCE(SUM(-amount), 0) as total FROM folio_ledger WHERE property_id = ? AND transaction_type = 'payment' AND (is_refund = 0 OR is_refund IS NULL) AND recorded_at BETWEEN ? AND ?");
            $revStmt->execute([$propertyId, $lastWeekStart . ' 00:00:00', $yesterday . ' 23:59:59']);
            $weeklyRev = (float)$revStmt->fetchColumn();
            
            $htmlBody = "
            <div style='font-family: sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;'>
                <h2 style='color: #059669;'>Weekly Revenue Report</h2>
                <p><strong>Period:</strong> {$lastWeekStart} to {$yesterday}</p>
                <div style='background: #ECFDF5; padding: 15px; border-radius: 8px; margin-top: 15px;'>
                    <h3 style='margin: 0; color: #065F46;'>Total Collected</h3>
                    <p style='font-size: 24px; font-weight: bold; margin: 5px 0 0 0;'>₹" . number_format($weeklyRev, 2) . "</p>
                </div>
                <p style='color: #888; font-size: 12px; margin-top: 20px;'>Generated automatically by MicroPMS.</p>
            </div>";
            
            $emails = array_map('trim', explode(',', $config['weekly_revenue_emails']));
            foreach ($emails as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    EmailService::send($email, $subject, $htmlBody);
                    echo "Sent Weekly Report to $email for Property ID $propertyId\n";
                }
            }
        }
    }
    
    echo "Email reports dispatched successfully.\n";
} catch (\Exception $e) {
    echo "Cron Error: " . $e->getMessage() . "\n";
}
