<?php
defined('JEIWS_CONFIG') or die('Direct access denied.');

// Copy this file to config/db.php (local) and/or config/db.live.php
// (production) and fill in real credentials. Both are gitignored so
// actual passwords never get committed.
return [
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'dbname'   => 'jeiws',
    'username' => 'CHANGE_ME',
    'password' => 'CHANGE_ME',
];