<?php
// Scheduled reports. Run once a day from a cron job (hPanel → Advanced → Cron Jobs):
//   /usr/bin/php /home/<user>/domains/<site>/public_html/cron.php >> /home/<user>/expenses-tracker-cron.log 2>&1
//
// Every day:            Telegram message with yesterday's expenses.
// On the 1st of month:  email (Gmail) + Telegram summary of the previous month.
//
// Options:
//   --dry-run            print the reports instead of sending them
//   --date=YYYY-MM-DD    pretend today is this date
//   --force              send again even if today's reports were already sent
//   --monthly            send the monthly report today even if it is not the 1st
//   --telegram-chat-id   show the chat ids that recently messaged the bot
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/reports.php';

$options = getopt('', ['dry-run', 'date:', 'force', 'monthly', 'telegram-chat-id']);
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

$today = validDate($options['date'] ?? '') ?: date('Y-m-d');
$stateFile = APP_ROOT . '/data/cron_state.json';
$state = file_exists($stateFile) ? (json_decode(file_get_contents($stateFile), true) ?: []) : [];
$expenses = loadExpenses();

function logLine($message) {
    echo '[' . date('Y-m-d H:i:s') . "] $message\n";
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

// --- Daily Telegram report (yesterday) ---
if ($force || ($state['daily'] ?? '') !== $today) {
    $yesterday = date('Y-m-d', strtotime("$today -1 day"));
    $day = buildReport($expenses, $yesterday, $yesterday);
    $monthToDate = buildReport($expenses, date('Y-m-01', strtotime($yesterday)), $yesterday);
    $text = telegramDailyText($day, $monthToDate);

    if (deliver('Daily Telegram report', $dryRun, fn() => sendTelegram($config, $text), strip_tags($text)) && !$dryRun) {
        $state['daily'] = $today;
    }
} else {
    logLine('Daily report already sent today');
}

// --- Monthly report (previous month) on the 1st ---
$monthKey = date('Y-m', strtotime("$today -1 month"));
if (date('j', strtotime($today)) === '1' || isset($options['monthly'])) {
    if ($force || ($state['monthly'] ?? '') !== $monthKey) {
        $from = "$monthKey-01";
        $to = date('Y-m-t', strtotime($from));
        $month = buildReport($expenses, $from, $to);
        $prevFrom = date('Y-m-01', strtotime("$from -1 month"));
        $previous = buildReport($expenses, $prevFrom, date('Y-m-t', strtotime($prevFrom)));
        $title = date('F Y', strtotime($from));

        $html = reportEmailHtml($month, $title, $previous, true);
        $emailOk = deliver('Monthly email report', $dryRun,
            fn() => sendReportEmail($config, "Monthly Expense Report: $title", $html),
            "Subject: Monthly Expense Report: $title\nTotal: E£ " . number_format($month['total'], 2) . " ({$month['count']} transactions)");

        $text = telegramMonthlyText($month, $previous);
        deliver('Monthly Telegram summary', $dryRun, fn() => sendTelegram($config, $text), strip_tags($text));

        if ($emailOk && !$dryRun) {
            $state['monthly'] = $monthKey;
        }
    } else {
        logLine("Monthly report for $monthKey already sent");
    }
}

if (!$dryRun) {
    file_put_contents($stateFile, json_encode($state), LOCK_EX);
}
