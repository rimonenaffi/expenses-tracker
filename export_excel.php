<?php
require __DIR__ . '/includes/bootstrap.php';
requireLogin();

if (!hash_equals((string)$config['export_pin'], (string)($_POST['pin'] ?? ''))) {
    http_response_code(403);
    die(t('wrong_pin'));
}

// Export exactly what the history view is showing
$filters = getFilters($_POST);
$expenses = sortExpenses(applyFilters(loadExpenses(), $filters));

// Send headers for Excel (CSV format)
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="expenses-' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
// UTF-8 BOM so Excel shows Arabic text correctly
fwrite($output, "\xEF\xBB\xBF");

fputcsv($output, ['Date', 'Description', 'Place', 'Category', 'Amount', 'Currency']);

foreach ($expenses as $expense) {
    fputcsv($output, [
        $expense['date'],
        $expense['description'],
        $expense['place'] ?? '',
        $expense['category'],
        $expense['amount'],
        $expense['currency'] ?? 'E£',
    ]);
}

fclose($output);
exit;
