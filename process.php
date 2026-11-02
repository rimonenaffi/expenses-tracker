<?php
require __DIR__ . '/includes/bootstrap.php';
requireLogin(true);
header('Content-Type: application/json');

$expenses = loadExpenses();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    checkCsrf();
}

$action = $_POST['action'] ?? 'expense';

// Set the money you currently have
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'balance') {
    if (!is_numeric($_POST['amount'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Invalid amount']);
        exit;
    }
    saveBalance($_POST['amount']);
    echo json_encode(['success' => true]);
    exit;
}

// Add an income / transfer you received
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'income') {
    $amount = (float)($_POST['amount'] ?? 0);
    $date = validDate($_POST['date'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    if ($amount <= 0 || !$date || $reason === '') {
        echo json_encode(['success' => false, 'error' => 'Invalid data']);
        exit;
    }

    $income = loadIncome();
    $income[] = [
        'id' => bin2hex(random_bytes(6)),
        'amount' => $amount,
        'date' => $date,
        'reason' => $reason,
        'created_at' => time(),
    ];
    saveIncome($income);
    echo json_encode(['success' => true]);
    exit;
}

// Handle POST request (add new expense)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = (float)($_POST['amount'] ?? 0);
    $date = validDate($_POST['date'] ?? '');
    $category = $_POST['category'] ?? '';
    $description = trim($_POST['description'] ?? '');

    if ($amount <= 0 || !$date || !in_array($category, $categories, true) || $description === '') {
        echo json_encode(['success' => false, 'error' => 'Invalid data']);
        exit;
    }

    $newExpense = [
        'id' => bin2hex(random_bytes(6)),
        'description' => $description,
        'place' => trim($_POST['place'] ?? ''),
        'amount' => $amount,
        'date' => $date,
        'category' => $category,
        'created_at' => time(),
    ];

    $expenses[] = $newExpense;
    saveExpenses($expenses);

    echo json_encode(['success' => true, 'expense' => $newExpense]);
    exit;
}

// Handle DELETE request (remove expense by id)
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    parse_str(file_get_contents('php://input'), $_DELETE);
    $id = $_DELETE['id'] ?? '';

    if (($_DELETE['type'] ?? '') === 'income') {
        $income = loadIncome();
        foreach ($income as $i => $entry) {
            if ($entry['id'] === $id) {
                array_splice($income, $i, 1);
                saveIncome($income);
                echo json_encode(['success' => true]);
                exit;
            }
        }
        echo json_encode(['success' => false, 'error' => 'Income not found']);
        exit;
    }

    foreach ($expenses as $i => $expense) {
        if ($expense['id'] === $id) {
            array_splice($expenses, $i, 1);
            saveExpenses($expenses);
            echo json_encode(['success' => true]);
            exit;
        }
    }

    echo json_encode(['success' => false, 'error' => 'Expense not found']);
    exit;
}

// Handle GET request (chart data for the current filters)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $filters = getFilters($_GET);
    $filtered = applyFilters($expenses, $filters);

    $categoryTotals = array_fill_keys($categories, 0);
    foreach ($filtered as $expense) {
        if (isset($categoryTotals[$expense['category']])) {
            $categoryTotals[$expense['category']] += $expense['amount'];
        }
    }

    echo json_encode([
        'labels' => array_map('categoryLabel', array_keys($categoryTotals)),
        'data' => array_values($categoryTotals),
        'colors' => array_map('getCategoryColor', array_keys($categoryTotals)),
        'trend' => getTrendData($filtered, $filters),
    ]);
    exit;
}

// Daily totals for short ranges, monthly totals for long ones
function getTrendData(array $expenses, array $filters) {
    $dates = array_column($expenses, 'date');
    $from = $filters['from'] ?: ($dates ? min($dates) : date('Y-m-d'));
    $to = $filters['to'] ?: ($dates ? max($dates) : date('Y-m-d'));

    $monthly = (strtotime($to) - strtotime($from)) / 86400 > 62;
    $keyFormat = $monthly ? 'Y-m' : 'Y-m-d';

    $buckets = [];
    $cursor = strtotime($monthly ? date('Y-m-01', strtotime($from)) : $from);
    $end = strtotime($to);
    while ($cursor <= $end && count($buckets) < 400) {
        $buckets[date($keyFormat, $cursor)] = 0;
        $cursor = strtotime($monthly ? '+1 month' : '+1 day', $cursor);
    }

    foreach ($expenses as $expense) {
        $key = $monthly ? substr($expense['date'], 0, 7) : $expense['date'];
        if (isset($buckets[$key])) {
            $buckets[$key] += $expense['amount'];
        }
    }

    $months = t('months_short');
    $labels = array_map(function ($key) use ($monthly, $months) {
        $ts = strtotime($monthly ? "$key-01" : $key);
        $month = $months[(int)date('n', $ts) - 1];
        return $monthly ? $month . ' ' . date('Y', $ts) : $month . ' ' . date('j', $ts);
    }, array_keys($buckets));

    return [
        'labels' => $labels,
        'data' => array_values($buckets),
    ];
}
