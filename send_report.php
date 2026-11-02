<?php
require __DIR__ . '/includes/bootstrap.php';
requireLogin(true);
header('Content-Type: application/json');

// Include PHPMailer files directly from the folder
require 'phpmailer/Exception.php';
require 'phpmailer/PHPMailer.php';
require 'phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Receive JSON input
$input = json_decode(file_get_contents('php://input'), true);
$period = $input['period'] ?? 'last7days';

// ---------------------------------------------------------
// Email Configuration
// ---------------------------------------------------------
$myEmail = $config['mail_address'];
$myAppPassword = $config['mail_app_password']; // App Password (kept in config.php)

// Load Expenses
$expenses = loadExpenses();
if (!$expenses) {
    echo json_encode(['success' => false, 'message' => 'No data found']);
    exit;
}

// Calculate Date Range
$endDate = date('Y-m-d');
$startDate = $endDate;
$periodTitle = "";

switch ($period) {
    case 'last3days':
        $startDate = date('Y-m-d', strtotime('-3 days'));
        $periodTitle = "Last 3 Days";
        break;
    case 'last30days':
        $startDate = date('Y-m-d', strtotime('-30 days'));
        $periodTitle = "Last 30 Days";
        break;
    case 'last7days':
    default:
        $startDate = date('Y-m-d', strtotime('-7 days'));
        $periodTitle = "Last 7 Days";
        break;
}

// Filter Data
$filteredExpenses = [];
$totalSpent = 0;
$categories = [];

foreach ($expenses as $expense) {
    if ($expense['date'] >= $startDate && $expense['date'] <= $endDate) {
        $filteredExpenses[] = $expense;
        $totalSpent += $expense['amount'];
        
        if (!isset($categories[$expense['category']])) {
            $categories[$expense['category']] = 0;
        }
        $categories[$expense['category']] += $expense['amount'];
    }
}

// Sort categories by amount (High to Low)
arsort($categories);

// Build HTML Email Content
$emailBody = "
<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;'>
    <div style='background-color: #4a6cf7; padding: 20px; text-align: center; color: white;'>
        <h1 style='margin: 0; font-size: 24px;'>Expense Report</h1>
        <p style='margin: 5px 0 0; opacity: 0.9;'>$periodTitle</p>
    </div>
    
    <div style='padding: 25px; background-color: #f9f9f9;'>
        <div style='text-align: center; margin-bottom: 25px;'>
            <p style='color: #666; margin: 0; font-size: 14px; text-transform: uppercase; letter-spacing: 1px;'>Total Spent</p>
            <h2 style='color: #333; margin: 5px 0 0; font-size: 36px; font-weight: bold;'>E£ " . number_format($totalSpent, 2) . "</h2>
            <p style='color: #888; font-size: 12px; margin-top: 5px;'>From $startDate to $endDate</p>
        </div>

        <h3 style='border-bottom: 2px solid #e0e0e0; padding-bottom: 10px; color: #444; margin-top: 0;'>Category Breakdown</h3>
        <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px;'>";

foreach ($categories as $catName => $catAmount) {
    $percentage = ($totalSpent > 0) ? round(($catAmount / $totalSpent) * 100) : 0;
    $emailBody .= "
        <tr>
            <td style='padding: 10px 0; border-bottom: 1px solid #eee; color: #555;'>$catName</td>
            <td style='padding: 10px 0; border-bottom: 1px solid #eee; text-align: right; font-weight: bold; color: #333;'>E£ " . number_format($catAmount, 2) . "</td>
            <td style='padding: 10px 0; border-bottom: 1px solid #eee; text-align: right; color: #888; font-size: 12px; width: 50px;'>$percentage%</td>
        </tr>";
}

$emailBody .= "
        </table>
        <div style='background-color: #fff; padding: 15px; border-radius: 6px; border: 1px solid #eee; text-align: center; color: #777; font-size: 12px;'>
<p>Generated automatically by your <strong>Expense Tracker</strong> App.</p>
</div>
    </div>
</div>";

// Send Email using PHPMailer
$mail = new PHPMailer(true);

try {
    // Server settings
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $myEmail;
    $mail->Password   = $myAppPassword;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    // Recipients
    $mail->setFrom($myEmail, 'Expense Tracker');
    $mail->addAddress($myEmail); // Send to self

    // Content
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = "Expense Report: $periodTitle";
    $mail->Body    = $emailBody;

    $mail->send();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => "Mailer Error: {$mail->ErrorInfo}"]);
}
?>