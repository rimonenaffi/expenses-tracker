<?php
// Copy this file to config.php and fill in your real values.
// config.php is ignored by git so secrets never reach GitHub.
return [
    // username => password hash. Generate a hash with:
    //   php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
    'users' => [
        'romio' => '$2y$10$replace.with.a.real.password.hash.................',
    ],

    // PIN asked before exporting to Excel
    'export_pin' => '0000',

    // Gmail account used to send the email reports (use a Google App Password)
    'mail_address' => 'you@example.com',
    'mail_app_password' => 'xxxx xxxx xxxx xxxx',

    // Where the reports go (defaults to mail_address)
    'report_email' => 'you@example.com',

    // Telegram bot from @BotFather, and your chat id
    // (send your bot a message, then run: php cron.php --telegram-chat-id)
    'telegram_bot_token' => '',
    'telegram_chat_id' => '',

    // Local time zone used for "today" and the scheduled reports
    'timezone' => 'Africa/Cairo',
];
