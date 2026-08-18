<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/fpdf.php';
require_once __DIR__ . '/../Database.php';

class PdfGenerator {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function generateDailyShiftReport(int $propertyId): string {
        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(40, 10, 'Daily Shift Report');
        $pdf->Ln(10);
        $pdf->SetFont('Arial', '', 12);
        
        $today = date('Y-m-d');
        $pdf->Cell(40, 10, 'Date: ' . $today);
        $pdf->Ln(10);
        
        // Fetch revenue
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(ABS(amount)), 0) as rev 
            FROM folio_ledger 
            WHERE property_id = ? AND transaction_type = 'payment' AND DATE(recorded_at) = ? AND IFNULL(is_refund, 0) = 0
        ");
        $stmt->execute([$propertyId, $today]);
        $revenue = $stmt->fetchColumn();
        
        $pdf->Cell(40, 10, 'Total Revenue: ' . number_format((float)$revenue, 2));
        $pdf->Ln(10);
        
        // Arrivals
        $stmt = $this->db->prepare("
            SELECT b.id, r.room_number, g.name 
            FROM bookings b 
            JOIN rooms r ON b.room_id = r.id 
            JOIN guests g ON b.guest_id = g.id 
            WHERE b.property_id = ? AND b.check_in = ? AND b.booking_status = 'confirmed'
        ");
        $stmt->execute([$propertyId, $today]);
        $arrivals = $stmt->fetchAll();
        
        $pdf->Cell(40, 10, 'Arrivals:');
        $pdf->Ln(10);
        foreach ($arrivals as $a) {
            $pdf->Cell(40, 10, '- Room ' . $a['room_number'] . ': ' . $a['name']);
            $pdf->Ln(8);
        }
        $pdf->Ln(2);

        // Departures
        $stmt = $this->db->prepare("
            SELECT b.id, r.room_number, g.name 
            FROM bookings b 
            JOIN rooms r ON b.room_id = r.id 
            JOIN guests g ON b.guest_id = g.id 
            WHERE b.property_id = ? AND b.check_out = ? AND b.booking_status = 'checked_in'
        ");
        $stmt->execute([$propertyId, $today]);
        $departures = $stmt->fetchAll();
        
        $pdf->Cell(40, 10, 'Departures:');
        $pdf->Ln(10);
        foreach ($departures as $d) {
            $pdf->Cell(40, 10, '- Room ' . $d['room_number'] . ': ' . $d['name']);
            $pdf->Ln(8);
        }
        
        $fileName = sys_get_temp_dir() . '/shift_report_' . $propertyId . '_' . time() . '.pdf';
        $pdf->Output('F', $fileName);
        
        return $fileName;
    }
}
