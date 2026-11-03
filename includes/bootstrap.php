<?php
// Shared setup for every page: session, config, language, data helpers.
session_start();

define('APP_ROOT', dirname(__DIR__));
define('EXPENSES_FILE', APP_ROOT . '/data/expenses.json');
define('INCOME_FILE', APP_ROOT . '/data/income.json');
define('BALANCE_FILE', APP_ROOT . '/data/balance.json');
define('SETTINGS_FILE', APP_ROOT . '/data/settings.json');
define('SUBSCRIPTIONS_FILE', APP_ROOT . '/data/subscriptions.json');

$config = file_exists(APP_ROOT . '/config.php')
    ? require APP_ROOT . '/config.php'
    : require APP_ROOT . '/config.example.php';

// Defaults for the email / Telegram / schedule settings
$settingDefaults = [
    'mail_host' => 'smtp.gmail.com',
    'mail_port' => 587,
    'mail_encryption' => 'tls',          // tls (STARTTLS) | ssl | none
    'mail_username' => $config['mail_address'] ?? '',
    'mail_app_password' => $config['mail_app_password'] ?? '',
    'mail_from' => $config['mail_address'] ?? '',
    'mail_from_name' => 'Expense Tracker',
    'report_email' => $config['report_email'] ?? ($config['mail_address'] ?? ''),
    'email_monthly_enabled' => true,
    'telegram_bot_token' => $config['telegram_bot_token'] ?? '',
    'telegram_chat_id' => $config['telegram_chat_id'] ?? '',
    'telegram_daily_enabled' => true,
    'telegram_monthly_enabled' => true,
    'report_hour' => 9,
    'timezone' => $config['timezone'] ?? 'Africa/Cairo',
    'users' => $config['users'] ?? [],   // username => password hash
];
// Values saved from the Settings page override config.php
$config = array_replace($config, $settingDefaults, readJson(SETTINGS_FILE, []));

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
function readJson($file, $default) {
    if (!file_exists($file)) {
        return $default;
    }
    $data = json_decode(file_get_contents($file), true);
    return $data === null ? $default : $data;
}

function writeJson($file, $data) {
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0755, true);
    }
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function loadExpenses() {
    $expenses = readJson(EXPENSES_FILE, []);

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
    writeJson(EXPENSES_FILE, array_values($expenses));
}

// Income entries: {id, amount, date, reason, created_at}
function loadIncome() {
    return readJson(INCOME_FILE, []);
}

function saveIncome(array $income) {
    writeJson(INCOME_FILE, array_values($income));
}

// Monthly subscriptions: {id, name, amount, category, place, day (1-31), start (Y-m-d),
// active, last_charged (Y-m of the last month an expense was added), created_at}
function loadSubscriptions() {
    return readJson(SUBSCRIPTIONS_FILE, []);
}

function saveSubscriptions(array $subscriptions) {
    writeJson(SUBSCRIPTIONS_FILE, array_values($subscriptions));
}

// The payment date in a month; day 31 falls on the last day of shorter months
function subscriptionDate(array $subscription, $month) {
    $day = min((int)$subscription['day'], (int)date('t', strtotime("$month-01")));
    return sprintf('%s-%02d', $month, $day);
}

// The next payment that hasn't been added as an expense yet
function nextSubscriptionDate(array $subscription) {
    $month = $subscription['last_charged']
        ? date('Y-m', strtotime($subscription['last_charged'] . '-01 +1 month'))
        : substr($subscription['start'], 0, 7);
    $date = subscriptionDate($subscription, $month);
    if ($date < $subscription['start']) {
        $date = subscriptionDate($subscription, date('Y-m', strtotime("$month-01 +1 month")));
    }
    return $date;
}

// Adds an expense for every active subscription payment that is due (today or missed).
// Runs on each dashboard visit and from cron, so it is safe to call repeatedly.
function chargeDueSubscriptions() {
    $subscriptions = loadSubscriptions();
    if (!$subscriptions) {
        return 0;
    }
    $today = date('Y-m-d');
    $expenses = null;
    $added = 0;

    foreach ($subscriptions as &$subscription) {
        if (empty($subscription['active'])) {
            continue;
        }
        while (($date = nextSubscriptionDate($subscription)) <= $today) {
            $expenses ??= loadExpenses();
            $expenses[] = [
                'id' => bin2hex(random_bytes(6)),
                'description' => $subscription['name'],
                'place' => $subscription['place'] ?? '',
                'amount' => (float)$subscription['amount'],
                'date' => $date,
                'category' => $subscription['category'],
                'created_at' => time(),
                'subscription_id' => $subscription['id'],
            ];
            $subscription['last_charged'] = substr($date, 0, 7);
            $added++;
        }
    }
    unset($subscription);

    if ($added) {
        saveExpenses($expenses);
        saveSubscriptions($subscriptions);
    }
    return $added;
}

// Active subscriptions due between two dates (inclusive), sorted by date
function upcomingSubscriptions($from, $to) {
    $upcoming = [];
    foreach (loadSubscriptions() as $subscription) {
        if (!empty($subscription['active'])) {
            $date = nextSubscriptionDate($subscription);
            if ($date >= $from && $date <= $to) {
                $upcoming[] = $subscription + ['next' => $date];
            }
        }
    }
    usort($upcoming, fn($a, $b) => strcmp($a['next'], $b['next']));
    return $upcoming;
}

// The balance you set by hand: {amount, set_at (unix time)}
function loadBalance() {
    return readJson(BALANCE_FILE, null);
}

function saveBalance($amount) {
    writeJson(BALANCE_FILE, ['amount' => (float)$amount, 'set_at' => time()]);
}

// Money you have now: the balance you set, plus income and minus expenses
// that were added after it was set. Null until a balance is set.
function currentBalance(array $expenses = null, array $income = null) {
    $balance = loadBalance();
    if (!$balance) {
        return null;
    }
    $total = $balance['amount'];
    foreach ($income ?? loadIncome() as $entry) {
        if (($entry['created_at'] ?? 0) > $balance['set_at']) {
            $total += $entry['amount'];
        }
    }
    foreach ($expenses ?? loadExpenses() as $expense) {
        if (($expense['created_at'] ?? 0) > $balance['set_at']) {
            $total -= $expense['amount'];
        }
    }
    return $total;
}

// Saves only the known setting keys
function saveSettings(array $changes) {
    global $settingDefaults;
    $settings = array_replace(readJson(SETTINGS_FILE, []), array_intersect_key($changes, $settingDefaults));
    writeJson(SETTINGS_FILE, $settings);
    @chmod(SETTINGS_FILE, 0600);
}

// --- CSRF protection for forms that change settings ---
function csrfToken() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function checkCsrf() {
    $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals(csrfToken(), (string)$token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid session, reload the page']);
        exit;
    }
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
            $haystack = ($expense['description'] ?? '') . ' ' . ($expense['place'] ?? '') . ' ' . ($expense['reason'] ?? '');
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
