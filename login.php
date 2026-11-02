<?php
require __DIR__ . '/includes/bootstrap.php';

// Check if user is already logged in
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$loginError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $users = $config['users'];

    if (isset($users[$username]) && password_verify($password, $users[$username])) {
        session_regenerate_id(true);
        $_SESSION['loggedin'] = true;
        $_SESSION['username'] = $username;
        header('Location: index.php');
        exit;
    } else {
        $loginError = t('invalid_login');
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(t('sign_in')) ?> | <?= e(t('app_title')) ?></title>
    <link rel="icon" type="image/x-icon" href="https://i.postimg.cc/fLSNVqq7/Amr-Bedir-2022-Logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php include __DIR__ . '/includes/theme-head.php'; ?>
    <?php include __DIR__ . '/includes/pwa-head.php'; ?>
    <style>
        :root {
            --primary: #4361ee;
            --primary-light: #e0e7ff;
            --primary-dark: #3a56d9;
            --text: #1e293b;
            --text-light: #64748b;
            --background: #f8fafc;
            --white: #ffffff;
            --error: #ef4444;
            --border: #e2e8f0;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        [data-theme="dark"] {
            --primary: #6d8bff;
            --primary-light: rgba(109, 139, 255, 0.18);
            --primary-dark: #5a78f0;
            --text: #e2e8f0;
            --text-light: #94a3b8;
            --background: #0b1120;
            --white: #151d2e;
            --border: #273349;
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.45);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Cairo', sans-serif;
            background-color: var(--background);
            color: var(--text);
            line-height: 1.5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 1rem;
            transition: background-color 0.5s ease, color 0.5s ease;
        }

        [hidden] { display: none !important; }

        .top-actions {
            position: fixed;
            top: 16px;
            inset-inline-end: 16px;
            display: flex;
            gap: 8px;
        }

        .icon-btn {
            height: 40px;
            min-width: 40px;
            padding: 0 12px;
            border-radius: 20px;
            border: 1px solid var(--border);
            background: var(--white);
            color: var(--text);
            cursor: pointer;
            font-family: inherit;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-decoration: none;
            transition: var(--transition);
        }

        .icon-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .login-container {
            background-color: var(--white);
            border-radius: 16px;
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 440px;
            padding: 3rem 2.5rem;
            position: relative;
            overflow: hidden;
            transition: background-color 0.5s ease;
            animation: rise 0.6s cubic-bezier(0.2, 0.8, 0.2, 1);
        }

        @keyframes rise {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 8px;
            background: linear-gradient(90deg, var(--primary), #3a0ca3, var(--primary));
            background-size: 200% 100%;
            animation: shift 6s linear infinite;
        }

        @keyframes shift {
            to { background-position: 200% 0; }
        }

        .login-header {
            margin-bottom: 2.5rem;
            text-align: center;
        }

        .logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 56px;
            background-color: var(--primary-light);
            border-radius: 12px;
            margin-bottom: 1.25rem;
        }

        .logo i {
            color: var(--primary);
            font-size: 1.75rem;
        }

        .login-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text);
        }

        .login-header p {
            color: var(--text-light);
            font-size: 0.9375rem;
        }

        .login-form .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }

        .login-form label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text);
        }

        .input-wrapper {
            position: relative;
        }

        .login-form input {
            width: 100%;
            padding: 0.875rem 1rem;
            padding-inline-start: 3rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.9375rem;
            font-family: inherit;
            color: var(--text);
            transition: var(--transition);
            background-color: var(--background);
        }

        .login-form input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        .input-icon {
            position: absolute;
            inset-inline-start: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            font-size: 1.1rem;
        }

        .login-btn {
            background-color: var(--primary);
            color: #fff;
            border: none;
            padding: 1rem;
            border-radius: 8px;
            width: 100%;
            font-size: 1rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: var(--transition);
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .login-btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        [dir="rtl"] .login-btn i {
            transform: scaleX(-1);
        }

        .error-message {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--error);
            padding: 0.875rem 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border-inline-start: 4px solid var(--error);
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 2rem 1.5rem;
            }

            .login-header h1 {
                font-size: 1.5rem;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body>
    <div class="top-actions">
        <a class="icon-btn" href="?lang=<?= $lang === 'ar' ? 'en' : 'ar' ?>"><i class="fas fa-language"></i> <?= e(t('switch_lang')) ?></a>
        <button type="button" class="icon-btn" id="installApp" hidden><i class="fas fa-download"></i> <?= e(t('install_app')) ?></button>
        <button type="button" class="icon-btn" id="themeToggle" aria-label="<?= e(t('toggle_theme')) ?>"><i class="fas fa-moon"></i></button>
    </div>

    <div class="login-container">
        <div class="login-header">
            <div class="logo">
                <i class="fas fa-chart-pie"></i>
            </div>
            <h1><?= e(t('welcome_back')) ?></h1>
            <p><?= e(t('sign_in_to')) ?></p>
        </div>

        <?php if ($loginError): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= e($loginError) ?></span>
            </div>
        <?php endif; ?>

        <form class="login-form" method="POST">
            <div class="form-group">
                <label for="username"><?= e(t('username')) ?></label>
                <div class="input-wrapper">
                    <i class="fas fa-user input-icon"></i>
                    <input type="text" id="username" name="username" required placeholder="<?= e(t('username_ph')) ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="password"><?= e(t('password')) ?></label>
                <div class="input-wrapper">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" id="password" name="password" required placeholder="<?= e(t('password_ph')) ?>">
                </div>
            </div>

            <button type="submit" class="login-btn">
                <i class="fas fa-sign-in-alt"></i>
                <span><?= e(t('sign_in')) ?></span>
            </button>
        </form>
    </div>

    <script src="assets/js/theme.js"></script>
    <script src="assets/js/pwa.js?v=1"></script>
</body>
</html>
