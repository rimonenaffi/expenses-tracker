<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/reports.php';
requireLogin(true);
header('Content-Type: application/json');

// Receive JSON input
$input = json_decode(file_get_contents('php://input'), true);
$period = $input['period'] ?? 'last7days';

$expenses = loadExpenses();
if (!$expenses) {
    echo json_encode(['success' => false, 'message' => 'No data found']);
    exit;
}

$days = ['last3days' => 3, 'last7days' => 7, 'last30days' => 30][$period] ?? 7;
$periodTitle = "Last $days Days";
$endDate = date('Y-m-d');
$startDate = date('Y-m-d', strtotime("-$days days"));

// Same-length window just before, for the comparison line
$previous = buildReport($expenses, date('Y-m-d', strtotime("-" . ($days * 2 + 1) . " days")), date('Y-m-d', strtotime("-" . ($days + 1) . " days")));
$report = buildReport($expenses, $startDate, $endDate);

$error = sendReportEmail($config, "Expense Report: $periodTitle", reportEmailHtml($report, $periodTitle, $previous, true));
echo json_encode($error ? ['success' => false, 'message' => $error] : ['success' => true]);
