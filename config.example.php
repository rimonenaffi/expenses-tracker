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
];
