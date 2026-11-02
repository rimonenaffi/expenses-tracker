<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/reports.php';
requireLogin();

// Turns posted form fields into setting values. Empty secrets keep the saved value.
function settingsFromPost(array $post) {
    $settings = [];
    foreach (['mail_host', 'mail_username', 'mail_from', 'mail_from_name', 'report_email', 'telegram_chat_id'] as $key) {
        if (isset($post[$key])) {
            $settings[$key] = trim($post[$key]);
        }
    }
    foreach (['mail_app_password', 'telegram_bot_token'] as $key) {
        if (isset($post[$key]) && trim($post[$key]) !== '') {
            $settings[$key] = trim($post[$key]);
        }
    }
    if (isset($post['mail_port'])) {
        $settings['mail_port'] = max(1, min(65535, (int)$post['mail_port']));
    }
    if (isset($post['mail_encryption']) && in_array($post['mail_encryption'], ['tls', 'ssl', 'none'], true)) {
        $settings['mail_encryption'] = $post['mail_encryption'];
    }
    if (isset($post['report_hour'])) {
        $settings['report_hour'] = max(0, min(23, (int)$post['report_hour']));
    }
    if (isset($post['timezone']) && in_array($post['timezone'], DateTimeZone::listIdentifiers(), true)) {
        $settings['timezone'] = $post['timezone'];
    }
    // Checkboxes are only meaningful for the section that was submitted
    $checkboxes = [
        'email' => ['email_monthly_enabled'],
        'telegram' => ['telegram_daily_enabled', 'telegram_monthly_enabled'],
    ];
    foreach ($checkboxes[$post['section'] ?? ''] ?? [] as $key) {
        $settings[$key] = !empty($post[$key]);
    }
    return $settings;
}

// Changes the signed-in user's username and/or password after checking the current password
function changeAccount(array $config, array $post) {
    $current = $_SESSION['username'] ?? '';
    $users = $config['users'];
    if (!isset($users[$current]) || !password_verify($post['current_password'] ?? '', $users[$current])) {
        sleep(1);
        return ['success' => false, 'message' => t('wrong_current_password')];
    }

    $newName = trim($post['new_username'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._@-]{3,32}$/', $newName)) {
        return ['success' => false, 'message' => t('username_invalid')];
    }
    if ($newName !== $current && isset($users[$newName])) {
        return ['success' => false, 'message' => t('username_taken')];
    }

    $hash = $users[$current];
    $newPassword = $post['new_password'] ?? '';
    if ($newPassword !== '') {
        if (strlen($newPassword) < 8) {
            return ['success' => false, 'message' => t('password_too_short')];
        }
        if ($newPassword !== ($post['confirm_password'] ?? '')) {
            return ['success' => false, 'message' => t('password_mismatch')];
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    }

    unset($users[$current]);
    $users[$newName] = $hash;
    saveSettings(['users' => $users]);

    session_regenerate_id(true);
    $_SESSION['username'] = $newName;
    return ['success' => true, 'message' => t('account_saved'), 'username' => $newName];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    checkCsrf();
    $action = $_POST['action'] ?? 'save';
    $changes = settingsFromPost($_POST);
    // Tests use what is in the form, even before saving
    $testConfig = array_replace($config, $changes);

    switch ($action) {
        case 'save':
            saveSettings($changes);
            echo json_encode(['success' => true, 'message' => t('saved')]);
            break;

        case 'account':
            echo json_encode(changeAccount($config, $_POST));
            break;

        case 'test_email':
            $report = buildReport(loadExpenses(), date('Y-m-01'), date('Y-m-d'), loadIncome());
            $error = sendReportEmail($testConfig, '[Test] Expense Tracker email settings',
                reportEmailHtml($report, 'Test — ' . date('F Y') . ' so far', null, false));
            echo json_encode($error ? ['success' => false, 'message' => $error] : ['success' => true, 'message' => t('test_sent')]);
            break;

        case 'test_telegram':
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $text = "🧪 <b>Test message</b>\n\n" . telegramDailyText(
                buildReport(loadExpenses(), $yesterday, $yesterday, loadIncome()),
                buildReport(loadExpenses(), date('Y-m-01', strtotime($yesterday)), $yesterday, loadIncome()),
                currentBalance()
            );
            $error = sendTelegram($testConfig, $text);
            echo json_encode($error ? ['success' => false, 'message' => $error] : ['success' => true, 'message' => t('test_sent')]);
            break;

        case 'find_chat_id':
            $updates = telegramApi($testConfig['telegram_bot_token'], 'getUpdates');
            if (!($updates['ok'] ?? false)) {
                echo json_encode(['success' => false, 'message' => 'Telegram: ' . ($updates['description'] ?? 'check the bot token')]);
                break;
            }
            $chats = [];
            foreach ($updates['result'] as $update) {
                $chat = $update['message']['chat'] ?? null;
                if ($chat) {
                    $chats[$chat['id']] = trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''));
                }
            }
            echo $chats
                ? json_encode(['success' => true, 'chat_id' => (string)array_key_last($chats), 'name' => end($chats)])
                : json_encode(['success' => false, 'message' => t('chat_id_hint')]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
    exit;
}

$hasPassword = ($config['mail_app_password'] ?? '') !== '';
$hasToken = ($config['telegram_bot_token'] ?? '') !== '';
$checked = fn($key) => !empty($config[$key]) ? 'checked' : '';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(t('settings')) ?> | <?= e(t('app_title')) ?></title>
    <link rel="icon" type="image/x-icon" href="https://i.postimg.cc/SsfMBzwf/romio001.png">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php include __DIR__ . '/includes/theme-head.php'; ?>
    <?php include __DIR__ . '/includes/pwa-head.php'; ?>
    <link rel="stylesheet" href="assets/css/style.css?v=6">
</head>
<body>
    <div class="container">
        <header>
            <div class="topbar">
                <a class="icon-btn" href="index.php"><i class="fas fa-arrow-left"></i> <?= e(t('back')) ?></a>
                <div class="topbar-actions">
                    <a class="icon-btn" href="?lang=<?= $lang === 'ar' ? 'en' : 'ar' ?>"><i class="fas fa-language"></i> <?= e(t('switch_lang')) ?></a>
                    <button type="button" class="icon-btn" id="themeToggle" aria-label="<?= e(t('toggle_theme')) ?>"><i class="fas fa-moon"></i></button>
                </div>
            </div>
            <h1 class="reveal"><i class="fas fa-gear"></i> <?= e(t('settings')) ?></h1>
        </header>

        <div class="settings-grid">
            <form class="panel reveal settings-form" style="--i:1" data-section="email" autocomplete="off">
                <h2><i class="fas fa-envelope"></i> <?= e(t('email_settings')) ?></h2>
                <p class="panel-intro"><?= e(t('gmail_hint')) ?></p>

                <div class="form-row">
                    <div class="form-group" style="flex:2">
                        <label for="mail_host"><?= e(t('smtp_host')) ?></label>
                        <input type="text" id="mail_host" name="mail_host" value="<?= e($config['mail_host']) ?>" placeholder="smtp.gmail.com">
                    </div>
                    <div class="form-group">
                        <label for="mail_port"><?= e(t('smtp_port')) ?></label>
                        <input type="number" id="mail_port" name="mail_port" value="<?= e($config['mail_port']) ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="mail_encryption"><?= e(t('encryption')) ?></label>
                    <select id="mail_encryption" name="mail_encryption">
                        <?php foreach (['tls', 'ssl', 'none'] as $enc): ?>
                            <option value="<?= $enc ?>" <?= $config['mail_encryption'] === $enc ? 'selected' : '' ?>><?= e(t('enc_' . $enc)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="mail_username"><?= e(t('smtp_username')) ?></label>
                    <input type="email" id="mail_username" name="mail_username" value="<?= e($config['mail_username']) ?>">
                </div>

                <div class="form-group">
                    <label for="mail_app_password"><?= e(t('smtp_password')) ?></label>
                    <input type="password" id="mail_app_password" name="mail_app_password" autocomplete="new-password"
                           placeholder="<?= $hasPassword ? e(t('secret_keep')) : '' ?>">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="mail_from"><?= e(t('sender_email')) ?></label>
                        <input type="email" id="mail_from" name="mail_from" value="<?= e($config['mail_from']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="mail_from_name"><?= e(t('sender_name')) ?></label>
                        <input type="text" id="mail_from_name" name="mail_from_name" value="<?= e($config['mail_from_name']) ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="report_email"><?= e(t('receiver_email')) ?></label>
                    <input type="text" id="report_email" name="report_email" value="<?= e($config['report_email']) ?>">
                </div>
                <p class="hint"><?= e(t('receiver_hint')) ?></p>

                <label class="check-row"><input type="checkbox" name="email_monthly_enabled" value="1" <?= $checked('email_monthly_enabled') ?>> <?= e(t('monthly_email_on')) ?></label>

                <div class="settings-actions">
                    <button type="submit" class="btn-submit"><i class="fas fa-save"></i> <?= e(t('save_settings')) ?></button>
                    <button type="button" class="btn-ghost" data-test="test_email"><i class="fas fa-paper-plane"></i> <?= e(t('send_test')) ?></button>
                </div>
            </form>

            <form class="panel reveal settings-form" style="--i:2" data-section="telegram" autocomplete="off">
                <h2><i class="fab fa-telegram"></i> <?= e(t('telegram_settings')) ?></h2>
                <p class="panel-intro"><?= e(t('bot_token_hint')) ?></p>

                <div class="form-group">
                    <label for="telegram_bot_token"><?= e(t('bot_token')) ?></label>
                    <input type="password" id="telegram_bot_token" name="telegram_bot_token" autocomplete="off"
                           placeholder="<?= $hasToken ? e(t('secret_keep')) : '123456789:AA...' ?>">
                </div>

                <div class="form-group">
                    <label for="telegram_chat_id"><?= e(t('chat_id')) ?></label>
                    <div class="input-with-btn">
                        <input type="text" id="telegram_chat_id" name="telegram_chat_id" value="<?= e($config['telegram_chat_id']) ?>">
                        <button type="button" class="btn-ghost" id="findChatId"><i class="fas fa-magnifying-glass"></i> <?= e(t('find_chat_id')) ?></button>
                    </div>
                </div>
                <p class="hint"><?= e(t('chat_id_hint')) ?></p>

                <label class="check-row"><input type="checkbox" name="telegram_daily_enabled" value="1" <?= $checked('telegram_daily_enabled') ?>> <?= e(t('daily_tg_on')) ?></label>
                <label class="check-row"><input type="checkbox" name="telegram_monthly_enabled" value="1" <?= $checked('telegram_monthly_enabled') ?>> <?= e(t('monthly_tg_on')) ?></label>

                <div class="settings-actions">
                    <button type="submit" class="btn-submit"><i class="fas fa-save"></i> <?= e(t('save_settings')) ?></button>
                    <button type="button" class="btn-ghost" data-test="test_telegram"><i class="fas fa-paper-plane"></i> <?= e(t('send_test')) ?></button>
                </div>
            </form>

            <form class="panel reveal settings-form wide" style="--i:3" data-section="schedule">
                <h2><i class="fas fa-clock"></i> <?= e(t('schedule')) ?></h2>
                <div class="form-row">
                    <div class="form-group">
                        <label for="report_hour"><?= e(t('report_hour')) ?></label>
                        <select id="report_hour" name="report_hour">
                            <?php for ($h = 0; $h < 24; $h++): ?>
                                <option value="<?= $h ?>" <?= (int)$config['report_hour'] === $h ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="timezone"><?= e(t('timezone')) ?></label>
                        <select id="timezone" name="timezone">
                            <?php foreach (DateTimeZone::listIdentifiers() as $zone): ?>
                                <option value="<?= e($zone) ?>" <?= $config['timezone'] === $zone ? 'selected' : '' ?>><?= e($zone) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="settings-actions">
                    <button type="submit" class="btn-submit"><i class="fas fa-save"></i> <?= e(t('save_settings')) ?></button>
                </div>
            </form>

            <form class="panel reveal settings-form wide" style="--i:4" data-section="account" data-action="account">
                <h2><i class="fas fa-user-lock"></i> <?= e(t('account_settings')) ?></h2>
                <p class="panel-intro"><?= e(t('account_hint')) ?></p>
                <div class="form-row">
                    <div class="form-group">
                        <label for="new_username"><?= e(t('username')) ?></label>
                        <input type="text" id="new_username" name="new_username" value="<?= e($_SESSION['username']) ?>" required autocomplete="username">
                    </div>
                    <div class="form-group">
                        <label for="current_password"><?= e(t('current_password')) ?></label>
                        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="new_password"><?= e(t('new_password')) ?></label>
                        <input type="password" id="new_password" name="new_password" minlength="8" autocomplete="new-password" placeholder="<?= e(t('new_password_ph')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="confirm_password"><?= e(t('confirm_password')) ?></label>
                        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password">
                    </div>
                </div>
                <div class="settings-actions">
                    <button type="submit" class="btn-submit"><i class="fas fa-save"></i> <?= e(t('save_account')) ?></button>
                </div>
            </form>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        const CSRF = <?= json_encode(csrfToken()) ?>;

        function toast(message, isError) {
            const el = document.getElementById('toast');
            el.textContent = message;
            el.classList.toggle('error', !!isError);
            el.classList.add('show');
            clearTimeout(el._timer);
            el._timer = setTimeout(() => el.classList.remove('show'), isError ? 6000 : 3000);
        }

        function post(form, action) {
            const data = new FormData(form);
            data.set('action', action);
            data.set('section', form.dataset.section);
            data.set('csrf', CSRF);
            return fetch('settings.php', { method: 'POST', body: data })
                .then(r => r.json())
                .catch(() => ({ success: false, message: 'Network error' }));
        }

        function withSpinner(button, promise) {
            const html = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            return promise.finally(() => { button.disabled = false; button.innerHTML = html; });
        }

        document.querySelectorAll('.settings-form').forEach(form => {
            form.addEventListener('submit', e => {
                e.preventDefault();
                withSpinner(form.querySelector('[type=submit]'), post(form, form.dataset.action || 'save').then(res => {
                    toast(res.message, !res.success);
                    // The account form clears its password boxes instead of showing "saved" placeholders
                    if (form.dataset.action) {
                        form.querySelectorAll('input[type=password]').forEach(input => { input.value = ''; });
                        if (res.username) form.new_username.value = res.username;
                        return;
                    }
                    // Secrets are never shown again; clear them after saving
                    form.querySelectorAll('input[type=password]').forEach(input => {
                        if (input.value) { input.value = ''; input.placeholder = <?= json_encode(t('secret_keep')) ?>; }
                    });
                }));
            });

            const testButton = form.querySelector('[data-test]');
            if (testButton) {
                testButton.addEventListener('click', () => {
                    withSpinner(testButton, post(form, testButton.dataset.test).then(res => toast(res.message, !res.success)));
                });
            }
        });

        document.getElementById('findChatId').addEventListener('click', function () {
            const form = this.closest('form');
            withSpinner(this, post(form, 'find_chat_id').then(res => {
                if (res.success) {
                    document.getElementById('telegram_chat_id').value = res.chat_id;
                    toast(res.chat_id + ' — ' + res.name);
                } else {
                    toast(res.message, true);
                }
            }));
        });
    </script>
    <script src="assets/js/theme.js?v=4"></script>
    <script src="assets/js/pwa.js?v=1"></script>
</body>
</html>
