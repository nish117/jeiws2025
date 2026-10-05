<?php
defined('JEIWS_CONFIG') or die('Direct access denied.');

// Copy to config/mail.php and fill in the real SMTP account.
// config/mail.php is git-ignored — never commit the real password.
return [
    'host'     => 'mail.example.com',
    'username' => 'info@example.com',
    'password' => 'change-me',
    'port'     => 587,
    'from'     => 'info@example.com',
    'to'       => 'info@example.com',
    'fromName' => 'JEIWS Website',
];
