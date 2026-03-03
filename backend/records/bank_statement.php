<?php
require_once(__DIR__ . '/../includes/secure_api_header.php'); // $pdo, $user_id
require_once(__DIR__ . '/../includes/tcpdf/tcpdf.php'); // TCPDF library

header('Content-Type: application/pdf');

// Optional: Get dates from GET or POST
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date   = $_GET['end_date'] ?? date('Y-m-d');

// Fetch user info
$stmt = $pdo->prepare("SELECT name, phone FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch transactions
$stmt = $pdo->prepare("
    SELECT created_at, type, amount, status, description 
    FROM transactions 
    WHERE user_id = ? AND DATE(created_at) BETWEEN ? AND ?
    ORDER BY created_at DESC
");
$stmt->execute([$user_id, $start_date, $end_date]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Initialize TCPDF
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('SaccusSalis Bank');
$pdf->SetAuthor('SaccusSalis Bank');
$pdf->SetTitle('Bank Statement');
$pdf->SetHeaderData('', 0, 'SaccusSalis Bank', "Bank Statement for {$user['name']} ({$user['phone']})\nPeriod: $start_date to $end_date");
$pdf->setHeaderFont(['helvetica', '', 10]);
$pdf->setFooterFont(['helvetica', '', 8]);
$pdf->SetMargins(15, 30, 15);
$pdf->SetAutoPageBreak(TRUE, 20);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 10);

// Build table
$html = '<table border="1" cellpadding="5">
<tr style="background-color:#f2f2f2;">
<th>Date</th>
<th>Type</th>
<th>Description</th>
<th>Status</th>
<th>Amount (R)</th>
</tr>';

foreach ($transactions as $t) {
    $html .= '<tr>
    <td>'.date('Y-m-d', strtotime($t['created_at'])).'</td>
    <td>'.htmlspecialchars($t['type']).'</td>
    <td>'.htmlspecialchars($t['description']).'</td>
    <td>'.htmlspecialchars($t['status']).'</td>
    <td style="text-align:right;">'.number_format($t['amount'],2).'</td>
    </tr>';
}

$html .= '</table>';

// Output table
$pdf->writeHTML($html, true, false, true, false, '');

// Footer summary
$pdf->Ln(5);
$total = array_sum(array_column($transactions, 'amount'));
$pdf->Write(0, "Total transactions in period: R" . number_format($total,2));

// Output PDF to browser
$pdf->Output('bank_statement.pdf', 'I');
