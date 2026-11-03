<?php
// Report building and delivery (email + Telegram), shared by send_report.php and cron.php.
require_once APP_ROOT . '/phpmailer/Exception.php';
require_once APP_ROOT . '/phpmailer/PHPMailer.php';
require_once APP_ROOT . '/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

// Summary of all expenses (and income) between $from and $to (inclusive)
function buildReport(array $expenses, $from, $to, array $income = []) {
    $inRange = fn($e) => $e['date'] >= $from && $e['date'] <= $to;
    $items = sortExpenses(array_filter($expenses, $inRange));
    $incomeItems = sortExpenses(array_filter($income, $inRange));

    $categories = [];
    $places = [];
    foreach ($items as $expense) {
        $categories[$expense['category']] = ($categories[$expense['category']] ?? 0) + $expense['amount'];
        if (!empty($expense['place'])) {
            $places[$expense['place']] = ($places[$expense['place']] ?? 0) + $expense['amount'];
        }
    }
    arsort($categories);
    arsort($places);

    $total = array_sum(array_column($items, 'amount'));
    $days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $biggest = null;
    foreach ($items as $expense) {
        if (!$biggest || $expense['amount'] > $biggest['amount']) {
            $biggest = $expense;
        }
    }

    return [
        'from' => $from,
        'to' => $to,
        'items' => $items,
        'count' => count($items),
        'total' => $total,
        'dailyAverage' => $days > 0 ? $total / $days : 0,
        'categories' => $categories,
        'places' => array_slice($places, 0, 5, true),
        'biggest' => $biggest,
        'incomeItems' => $incomeItems,
        'incomeTotal' => array_sum(array_column($incomeItems, 'amount')),
    ];
}

function percentChange($current, $previous) {
    if ($previous <= 0) {
        return null;
    }
    return round((($current - $previous) / $previous) * 100);
}

// --- Email ---

function reportEmailHtml(array $report, $title, array $previous = null, $withTransactions = false) {
    $money = fn($v) => 'E£ ' . number_format($v, 2);
    $cell = "padding: 10px 0; border-bottom: 1px solid #eee;";

    $comparison = '';
    if ($previous) {
        $change = percentChange($report['total'], $previous['total']);
        if ($change !== null) {
            $color = $change > 0 ? '#e11d48' : '#10b981';
            $arrow = $change > 0 ? '▲' : '▼';
            $comparison = "<p style='color: $color; font-size: 13px; margin: 6px 0 0;'>$arrow " . abs($change)
                . "% vs previous period (" . $money($previous['total']) . ")</p>";
        }
    }

    $html = "
<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;'>
    <div style='background-color: #4a6cf7; padding: 20px; text-align: center; color: white;'>
        <h1 style='margin: 0; font-size: 24px;'>Expense Report</h1>
        <p style='margin: 5px 0 0; opacity: 0.9;'>" . e($title) . "</p>
    </div>

    <div style='padding: 25px; background-color: #f9f9f9;'>
        <div style='text-align: center; margin-bottom: 25px;'>
            <p style='color: #666; margin: 0; font-size: 14px; text-transform: uppercase; letter-spacing: 1px;'>Total Spent</p>
            <h2 style='color: #333; margin: 5px 0 0; font-size: 36px; font-weight: bold;'>" . $money($report['total']) . "</h2>
            <p style='color: #888; font-size: 12px; margin-top: 5px;'>From {$report['from']} to {$report['to']}</p>
            $comparison
        </div>

        <table style='width: 100%; border-collapse: collapse; margin-bottom: 25px; text-align: center;'>
            <tr>
                <td style='padding: 10px; background: #fff; border: 1px solid #eee;'><div style='color:#888; font-size:12px;'>Transactions</div><strong style='font-size:18px; color:#333;'>{$report['count']}</strong></td>
                <td style='padding: 10px; background: #fff; border: 1px solid #eee;'><div style='color:#888; font-size:12px;'>Daily average</div><strong style='font-size:18px; color:#333;'>" . $money($report['dailyAverage']) . "</strong></td>
            </tr>" . ($report['incomeTotal'] > 0 ? "
            <tr>
                <td style='padding: 10px; background: #fff; border: 1px solid #eee;'><div style='color:#888; font-size:12px;'>Income</div><strong style='font-size:18px; color:#10b981;'>+" . $money($report['incomeTotal']) . "</strong></td>
                <td style='padding: 10px; background: #fff; border: 1px solid #eee;'><div style='color:#888; font-size:12px;'>Net (income − spent)</div><strong style='font-size:18px; color:" . ($report['incomeTotal'] >= $report['total'] ? '#10b981' : '#e11d48') . ";'>" . $money($report['incomeTotal'] - $report['total']) . "</strong></td>
            </tr>" : "") . "
        </table>

        <h3 style='border-bottom: 2px solid #e0e0e0; padding-bottom: 10px; color: #444; margin-top: 0;'>Category Breakdown</h3>
        <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px;'>";

    foreach ($report['categories'] as $category => $amount) {
        $percentage = $report['total'] > 0 ? round(($amount / $report['total']) * 100) : 0;
        $color = getCategoryColor($category);
        $html .= "
            <tr>
                <td style='$cell color: #555;'><span style='display:inline-block; width:10px; height:10px; border-radius:50%; background:$color; margin-right:8px;'></span>" . e($category) . "</td>
                <td style='$cell text-align: right; font-weight: bold; color: #333;'>" . $money($amount) . "</td>
                <td style='$cell text-align: right; color: #888; font-size: 12px; width: 50px;'>$percentage%</td>
            </tr>";
    }
    $html .= "</table>";

    if ($report['places']) {
        $html .= "<h3 style='border-bottom: 2px solid #e0e0e0; padding-bottom: 10px; color: #444;'>Top Places</h3>
        <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px;'>";
        foreach ($report['places'] as $place => $amount) {
            $html .= "<tr><td style='$cell color: #555;'>" . e($place) . "</td><td style='$cell text-align: right; font-weight: bold; color: #333;'>" . $money($amount) . "</td></tr>";
        }
        $html .= "</table>";
    }

    if ($withTransactions && $report['incomeItems']) {
        $html .= "<h3 style='border-bottom: 2px solid #e0e0e0; padding-bottom: 10px; color: #444;'>Income &amp; Transfers</h3>
        <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 13px;'>";
        foreach ($report['incomeItems'] as $entry) {
            $html .= "<tr>
                <td style='padding: 8px 0; border-bottom: 1px solid #eee; color: #888; white-space: nowrap;'>" . e($entry['date']) . "</td>
                <td style='padding: 8px; border-bottom: 1px solid #eee; color: #444;'>" . e($entry['reason']) . "</td>
                <td style='padding: 8px 0; border-bottom: 1px solid #eee; text-align: right; font-weight: bold; color: #10b981; white-space: nowrap;'>+" . $money($entry['amount']) . "</td>
            </tr>";
        }
        $html .= "</table>";
    }

    if ($withTransactions && $report['items']) {
        $html .= "<h3 style='border-bottom: 2px solid #e0e0e0; padding-bottom: 10px; color: #444;'>All Transactions</h3>
        <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 13px;'>";
        foreach ($report['items'] as $expense) {
            $place = !empty($expense['place']) ? "<div style='color:#999; font-size:11px;'>" . e($expense['place']) . "</div>" : '';
            $html .= "<tr>
                <td style='padding: 8px 0; border-bottom: 1px solid #eee; color: #888; white-space: nowrap;'>" . e($expense['date']) . "</td>
                <td style='padding: 8px; border-bottom: 1px solid #eee; color: #444;'>" . e($expense['description']) . "$place</td>
                <td style='padding: 8px 0; border-bottom: 1px solid #eee; color: #666;'>" . e($expense['category']) . "</td>
                <td style='padding: 8px 0; border-bottom: 1px solid #eee; text-align: right; font-weight: bold; color: #333; white-space: nowrap;'>" . $money($expense['amount']) . "</td>
            </tr>";
        }
        $html .= "</table>";
    }

    $html .= "
        <div style='background-color: #fff; padding: 15px; border-radius: 6px; border: 1px solid #eee; text-align: center; color: #777; font-size: 12px;'>
            <p>Generated automatically by your <strong>Expense Tracker</strong> App.</p>
        </div>
    </div>
</div>";

    return $html;
}

// Sends an HTML email using the SMTP settings (Settings page / config.php).
// Returns null on success or the error message.
function sendReportEmail(array $config, $subject, $html) {
    $recipients = array_filter(array_map('trim', explode(',', $config['report_email'] ?? '')));
    if (!$recipients || ($config['mail_username'] ?? '') === '') {
        return 'Email is not configured (check the Settings page)';
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $config['mail_host'];
        $mail->Port       = (int)$config['mail_port'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['mail_username'];
        $mail->Password   = $config['mail_app_password'];
        $mail->Timeout    = 20;
        if ($config['mail_encryption'] === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($config['mail_encryption'] === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom($config['mail_from'] ?: $config['mail_username'], $config['mail_from_name'] ?: 'Expense Tracker');
        foreach ($recipients as $recipient) {
            $mail->addAddress($recipient);
        }

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $html;

        $mail->send();
        return null;
    } catch (\Exception $e) {
        return "Mailer Error: {$mail->ErrorInfo}";
    }
}

// --- Telegram ---

function tg($text) {
    return htmlspecialchars((string)$text, ENT_NOQUOTES, 'UTF-8');
}

function telegramDailyText(array $day, array $monthToDate, $balance = null, array $upcoming = []) {
    $money = fn($v) => 'E£ ' . number_format($v, 2);
    $date = date('l, j M Y', strtotime($day['from']));

    $lines = ["📊 <b>Daily expense report</b>", "🗓 $date", ""];

    if ($day['count'] === 0) {
        $lines[] = "✅ No expenses recorded.";
    } else {
        $lines[] = "💰 <b>Total: " . $money($day['total']) . "</b> · {$day['count']} transaction" . ($day['count'] > 1 ? 's' : '');
        $lines[] = "";
        foreach ($day['items'] as $expense) {
            $place = !empty($expense['place']) ? ' @ ' . tg($expense['place']) : '';
            $lines[] = "• " . tg($expense['description']) . $place . " — <b>" . $money($expense['amount']) . "</b> <i>(" . tg($expense['category']) . ")</i>";
        }
        if (count($day['categories']) > 1) {
            $lines[] = "";
            $lines[] = "<b>By category</b>";
            foreach ($day['categories'] as $category => $amount) {
                $lines[] = "▫️ " . tg($category) . ": " . $money($amount);
            }
        }
    }

    if ($day['incomeItems']) {
        $lines[] = "";
        $lines[] = "💵 <b>Income: +" . $money($day['incomeTotal']) . "</b>";
        foreach ($day['incomeItems'] as $entry) {
            $lines[] = "• " . tg($entry['reason']) . " — <b>+" . $money($entry['amount']) . "</b>";
        }
    }

    $lines[] = "";
    $lines[] = "📅 <b>" . date('F', strtotime($monthToDate['from'])) . " so far:</b> " . $money($monthToDate['total'])
        . " ({$monthToDate['count']} transactions)";
    if ($monthToDate['incomeTotal'] > 0) {
        $lines[] = "💵 Income this month: +" . $money($monthToDate['incomeTotal']);
    }
    if ($balance !== null) {
        $lines[] = "👛 <b>Balance now: " . $money($balance) . "</b>";
    }

    if ($upcoming) {
        $lines[] = "";
        $lines[] = "🔁 <b>Subscriptions coming up</b>";
        foreach ($upcoming as $subscription) {
            $lines[] = "• " . tg($subscription['name']) . " — <b>" . $money($subscription['amount']) . "</b> on " . date('D j M', strtotime($subscription['next']));
        }
    }

    return implode("\n", $lines);
}

function telegramMonthlyText(array $month, array $previous, $balance = null, $emailed = true) {
    $money = fn($v) => 'E£ ' . number_format($v, 2);
    $lines = [
        "🗂 <b>Monthly report — " . date('F Y', strtotime($month['from'])) . "</b>",
        "",
        "💰 Total spent: <b>" . $money($month['total']) . "</b> ({$month['count']} transactions)",
        "📈 Daily average: " . $money($month['dailyAverage']),
    ];
    if ($month['incomeTotal'] > 0) {
        $lines[] = "💵 Income: +" . $money($month['incomeTotal']) . " · Net: " . $money($month['incomeTotal'] - $month['total']);
    }
    if ($balance !== null) {
        $lines[] = "👛 Balance now: " . $money($balance);
    }
    $change = percentChange($month['total'], $previous['total']);
    if ($change !== null) {
        $lines[] = ($change > 0 ? "🔺 " : "🔻 ") . abs($change) . "% vs " . date('F', strtotime($previous['from']));
    }
    if ($month['categories']) {
        $lines[] = "";
        foreach (array_slice($month['categories'], 0, 5, true) as $category => $amount) {
            $lines[] = "▫️ " . tg($category) . ": " . $money($amount);
        }
    }
    if ($emailed) {
        $lines[] = "";
        $lines[] = "📧 Full report sent to your email.";
    }
    return implode("\n", $lines);
}

// Sends a Telegram message. Returns null on success or the error message.
function sendTelegram(array $config, $text, $chatId = null) {
    $token = $config['telegram_bot_token'] ?? '';
    $chatId = $chatId ?? ($config['telegram_chat_id'] ?? '');
    if ($token === '' || $chatId === '') {
        return 'Telegram is not configured (telegram_bot_token / telegram_chat_id in config.php)';
    }

    $response = telegramApi($token, 'sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ]);
    if (!($response['ok'] ?? false)) {
        return 'Telegram error: ' . ($response['description'] ?? 'no response');
    }
    return null;
}

function telegramApi($token, $method, array $params = []) {
    $url = "https://api.telegram.org/bot$token/$method";

    // Hostinger disables allow_url_fopen, so prefer cURL
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return $body === false ? null : json_decode($body, true);
    }

    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query($params),
        'timeout' => 20,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $context);
    return $body === false ? null : json_decode($body, true);
}
