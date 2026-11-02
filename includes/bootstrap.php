<?php
// Shared setup for every page: session, config, language, data helpers.
session_start();

define('APP_ROOT', dirname(__DIR__));
define('EXPENSES_FILE', APP_ROOT . '/data/expenses.json');

$config = file_exists(APP_ROOT . '/config.php')
    ? require APP_ROOT . '/config.php'
    : require APP_ROOT . '/config.example.php';

// The server runs on UTC; dates should follow your local day
date_default_timezone_set($config['timezone'] ?? 'Africa/Cairo');

// Stored values stay in English; they are translated only for display
$categories = ['Food', 'Cafe', 'Rent', 'Transport', 'Entertainment', 'Utilities', 'Shopping', 'Healthcare', 'Other'];

$categoryColors = [
    'Food'          => '#FF6384',
    'Cafe'          => '#A0522D',
    'Rent'          => '#E11D48',
    'Transport'     => '#36A2EB',
    'Entertainment' => '#FFCE56',
    'Utilities'     => '#4BC0C0',
    'Shopping'      => '#9966FF',
    'Healthcare'    => '#FF9F40',
    'Other'         => '#8AC24A',
];

// --- Language ---
$supportedLangs = ['en', 'ar'];
if (isset($_GET['lang']) && in_array($_GET['lang'], $supportedLangs, true)) {
    $lang = $_GET['lang'];
    setcookie('lang', $lang, time() + 60 * 60 * 24 * 365, '/');
} elseif (isset($_COOKIE['lang']) && in_array($_COOKIE['lang'], $supportedLangs, true)) {
    $lang = $_COOKIE['lang'];
} else {
    $lang = 'en';
}
$translations = require APP_ROOT . "/lang/$lang.php";
$dir = $lang === 'ar' ? 'rtl' : 'ltr';

function t($key, ...$args) {
    global $translations;
    $text = $translations[$key] ?? $key;
    return $args ? vsprintf($text, $args) : $text;
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function categoryLabel($category) {
    return t('cat_' . strtolower($category));
}

function getCategoryColor($category) {
    global $categoryColors;
    return $categoryColors[$category] ?? '#CCCCCC';
}

function formatMoney($amount) {
    return t('currency') . ' ' . number_format($amount, 2);
}

function formatDate($date) {
    global $lang;
    $ts = strtotime($date);
    $month = t('months_short')[(int)date('n', $ts) - 1];
    return $lang === 'ar'
        ? sprintf('%d %s %d', date('j', $ts), $month, date('Y', $ts))
        : sprintf('%s %d, %d', $month, date('j', $ts), date('Y', $ts));
}

// Link to the current page with some query parameters changed
function urlWith(array $changes) {
    $params = array_merge($_GET, $changes);
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($params);
}

// --- Auth ---
function isLoggedIn() {
    return isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
}

function requireLogin($json = false) {
    if (isLoggedIn()) {
        return;
    }
    if ($json) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    } else {
        header('Location: login.php');
    }
    exit;
}

// --- Storage ---
function loadExpenses() {
    if (!file_exists(EXPENSES_FILE)) {
        return [];
    }
    $expenses = json_decode(file_get_contents(EXPENSES_FILE), true) ?: [];

    // Older records have no id; give them one so deletes are reliable
    $changed = false;
    foreach ($expenses as &$expense) {
        if (empty($expense['id'])) {
            $expense['id'] = bin2hex(random_bytes(6));
            $changed = true;
        }
    }
    unset($expense);
    if ($changed) {
        saveExpenses($expenses);
    }
    return $expenses;
}

function saveExpenses(array $expenses) {
    if (!is_dir(dirname(EXPENSES_FILE))) {
        mkdir(dirname(EXPENSES_FILE), 0755, true);
    }
    file_put_contents(EXPENSES_FILE, json_encode(array_values($expenses), JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// --- Filters (shared by the page, the chart API and the export) ---
// period: 30days | thismonth | lastmonth | month | custom | all
function getFilters(array $query) {
    $period = $query['period'] ?? '30days';
    // Backwards compatibility with the old "showAll" toggle
    if (($query['showAll'] ?? '') === 'true') {
        $period = 'all';
    }

    $from = null;
    $to = null;
    switch ($period) {
        case 'all':
            break;
        case 'thismonth':
            $from = date('Y-m-01');
            $to = date('Y-m-t');
            break;
        case 'lastmonth':
            $from = date('Y-m-01', strtotime('first day of last month'));
            $to = date('Y-m-t', strtotime('first day of last month'));
            break;
        case 'month':
            $month = preg_match('/^\d{4}-\d{2}$/', $query['month'] ?? '') ? $query['month'] : date('Y-m');
            $from = "$month-01";
            $to = date('Y-m-t', strtotime($from));
            break;
        case 'custom':
            $from = validDate($query['from'] ?? '') ?: null;
            $to = validDate($query['to'] ?? '') ?: null;
            break;
        default:
            $period = '30days';
            $from = date('Y-m-d', strtotime('-30 days'));
            $to = date('Y-m-d');
    }

    $category = $query['category'] ?? '';
    global $categories;
    if (!in_array($category, $categories, true)) {
        $category = '';
    }

    return [
        'period'   => $period,
        'month'    => $query['month'] ?? date('Y-m'),
        'from'     => $from,
        'to'       => $to,
        'category' => $category,
        'search'   => trim($query['q'] ?? ''),
    ];
}

function validDate($value) {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
}

function applyFilters(array $expenses, array $filters) {
    return array_values(array_filter($expenses, function ($expense) use ($filters) {
        if ($filters['from'] && $expense['date'] < $filters['from']) return false;
        if ($filters['to'] && $expense['date'] > $filters['to']) return false;
        if ($filters['category'] && $expense['category'] !== $filters['category']) return false;
        if ($filters['search'] !== '') {
            $haystack = ($expense['description'] ?? '') . ' ' . ($expense['place'] ?? '');
            $found = function_exists('mb_stripos')
                ? mb_stripos($haystack, $filters['search'])
                : stripos($haystack, $filters['search']);
            if ($found === false) return false;
        }
        return true;
    }));
}

// Newest first (reverse first so same-day entries show the latest added on top)
function sortExpenses(array $expenses) {
    $expenses = array_reverse($expenses);
    usort($expenses, fn($a, $b) => strcmp($b['date'], $a['date']));
    return $expenses;
}
