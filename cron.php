<?php
// Scheduled reports. Run EVERY HOUR from a cron job (hPanel → Advanced → Cron Jobs, "0 * * * *"):
//   /usr/bin/php /home/<user>/domains/<site>/public_html/cron.php >> /home/<user>/expenses-tracker-cron.log 2>&1
// It only sends at the hour chosen on the Settings page, and never twice for the same day/month.
//
// Every day:            Telegram message with yesterday's expenses and income.
// On the 1st of month:  email + Telegram summary of the previous month.
// Each one can be switched off on the Settings page.
//
// Options:
//   --dry-run            print the reports instead of sending them
//   --date=YYYY-MM-DD    pretend today is this date
//   --now                ignore the send hour
//   --force              send again even if today's reports were already sent
//   --monthly            send the monthly report today even if it is not the 1st
//   --telegram-chat-id   show the chat ids that recently messaged the bot
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/reports.php';

$options = getopt('', ['dry-run', 'date:', 'now', 'force', 'monthly', 'telegram-chat-id']);
$dryRun = isset($options['dry-run']);
$force = isset($options['force']);

if (isset($options['telegram-chat-id'])) {
    $updates = telegramApi($config['telegram_bot_token'] ?? '', 'getUpdates');
    if (!($updates['ok'] ?? false)) {
        exit("Could not read bot updates: " . ($updates['description'] ?? 'check telegram_bot_token') . "\n");
    }
    foreach ($updates['result'] as $update) {
        $chat = $update['message']['chat'] ?? null;
        if ($chat) {
            echo "chat id {$chat['id']} — " . trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? '') . ' @' . ($chat['username'] ?? '')) . "\n";
        }
    }
    exit($updates['result'] ? 0 : "No messages yet. Send any message to your bot first, then run this again.\n");
}

function logLine($message) {
    echo '[' . date('Y-m-d H:i:s') . "] $message\n";
}

// Wait for the hour chosen on the Settings page (any run after it that day also works)
if (!isset($options['now']) && !isset($options['date']) && (int)date('G') < (int)$config['report_hour']) {
    exit;
}

function deliver($label, $dryRun, callable $send, $preview) {
    if ($dryRun) {
        echo "----- $label (dry run) -----\n$preview\n\n";
        return true;
    }
    $error = $send();
    logLine($error ? "$label FAILED: $error" : "$label sent");
    return $error === null;
}

$today = validDate($options['date'] ?? '') ?: date('Y-m-d');
$stateFile = APP_ROOT . '/data/cron_state.json';
$state = readJson($stateFile, []);
$expenses = loadExpenses();
$income = loadIncome();
$balance = currentBalance($expenses, $income);

// --- Daily Telegram report (yesterday) ---
if (!empty($config['telegram_daily_enabled']) && ($force || ($state['daily'] ?? '') !== $today)) {
    $yesterday = date('Y-m-d', strtotime("$today -1 day"));
    $day = buildReport($expenses, $yesterday, $yesterday, $income);
    $monthToDate = buildReport($expenses, date('Y-m-01', strtotime($yesterday)), $yesterday, $income);
    $text = telegramDailyText($day, $monthToDate, $balance);

    if (deliver('Daily Telegram report', $dryRun, fn() => sendTelegram($config, $text), strip_tags($text)) && !$dryRun) {
        $state['daily'] = $today;
    }
}

// --- Monthly report (previous month) on the 1st ---
$monthKey = date('Y-m', strtotime("$today -1 month"));
$isMonthlyDay = date('j', strtotime($today)) === '1' || isset($options['monthly']);
if ($isMonthlyDay) {
    $from = "$monthKey-01";
    $to = date('Y-m-t', strtotime($from));
    $month = buildReport($expenses, $from, $to, $income);
    $prevFrom = date('Y-m-01', strtotime("$from -1 month"));
    $previous = buildReport($expenses, $prevFrom, date('Y-m-t', strtotime($prevFrom)), $income);
    $title = date('F Y', strtotime($from));

    // Each channel is tracked on its own so a failed one is retried next hour without repeating the other
    $emailOn = !empty($config['email_monthly_enabled']);
    $emailDone = ($state['monthly_email'] ?? '') === $monthKey;
    if ($emailOn && ($force || !$emailDone)) {
        $html = reportEmailHtml($month, $title, $previous, true);
        $emailDone = deliver('Monthly email report', $dryRun,
            fn() => sendReportEmail($config, "Monthly Expense Report: $title", $html),
            "Subject: Monthly Expense Report: $title\nTotal: E£ " . number_format($month['total'], 2) . " ({$month['count']} transactions)");
        if ($emailDone && !$dryRun) {
            $state['monthly_email'] = $monthKey;
        }
    }

    if (!empty($config['telegram_monthly_enabled']) && ($force || ($state['monthly_telegram'] ?? '') !== $monthKey)) {
        $text = telegramMonthlyText($month, $previous, $balance, $emailOn && $emailDone);
        if (deliver('Monthly Telegram summary', $dryRun, fn() => sendTelegram($config, $text), strip_tags($text)) && !$dryRun) {
            $state['monthly_telegram'] = $monthKey;
        }
    }
}

if (!$dryRun) {
    writeJson($stateFile, $state);
}
