<?php
defined('ACC_LOADED') or die('Direct access denied.');

/**
 * Server readiness checks for Settings → System check. Mostly about what
 * file uploads need, because shared hosts differ (missing GD/fileinfo,
 * small upload limits, read-only folders) and a gap there otherwise shows
 * up only as "Internal Server Error".
 */

function ini_bytes(string $key): int {
    $v = trim((string)ini_get($key));
    if ($v === '' || $v === '-1') return $v === '-1' ? PHP_INT_MAX : 0;
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
}

/** @return array<int, array{group:string, label:string, status:string, value:string, help:string}> status: ok | warn | fail */
function system_checks(): array {
    $c = [];
    $add = function (string $group, string $label, string $status, string $value, string $help = '') use (&$c) {
        $c[] = compact('group', 'label', 'status', 'value', 'help');
    };

    $add('PHP', 'PHP version', version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'fail', PHP_VERSION, 'PHP 8.1 or newer is required.');
    foreach ([
        ['pdo_mysql', 'fail', 'Database connection.'],
        ['mbstring', 'warn', 'Unicode text handling (names in Nepali, dashes …).'],
        ['gd', 'warn', 'Shrinks and cleans uploaded photos. Without it photos are stored as uploaded (the browser has already shrunk them).'],
        ['fileinfo', 'warn', 'Detects file types. Without it the app reads the file header instead.'],
        ['exif', 'warn', 'Turns sideways phone photos upright.'],
        ['openssl', 'warn', 'Secure connection to the mail server for payslip emails.'],
    ] as [$ext, $failStatus, $help]) {
        $add('PHP extensions', $ext, extension_loaded($ext) ? 'ok' : $failStatus, extension_loaded($ext) ? 'installed' : 'missing', $help);
    }
    if (function_exists('gd_info')) {
        $gd = gd_info();
        $formats = array_filter(['JPEG' => $gd['JPEG Support'] ?? false, 'PNG' => $gd['PNG Support'] ?? false, 'WebP' => $gd['WebP Support'] ?? false]);
        $add('PHP extensions', 'GD formats', isset($formats['JPEG'], $formats['PNG']) ? 'ok' : 'warn', implode(', ', array_keys($formats)) ?: 'none', 'JPEG and PNG support are needed for photos.');
    }

    $upload = ini_bytes('upload_max_filesize');
    $post = ini_bytes('post_max_size');
    $mb = fn(int $b) => $b === PHP_INT_MAX ? 'unlimited' : round($b / 1048576, 1) . ' MB';
    $add('Upload limits', 'File uploads enabled', ini_get('file_uploads') ? 'ok' : 'fail', ini_get('file_uploads') ? 'yes' : 'no', 'file_uploads must be On.');
    $add('Upload limits', 'Largest single file (upload_max_filesize)', $upload >= 2 << 20 ? 'ok' : 'warn', $mb($upload), 'Photos are shrunk to well under 1 MB; PDFs must fit within this.');
    $add('Upload limits', 'Largest single save (post_max_size)', $post >= 8 << 20 ? 'ok' : 'warn', $mb($post), 'All files attached in one save, plus the form.');
    $add('Upload limits', 'Files per save (max_file_uploads)', (int)ini_get('max_file_uploads') >= 5 ? 'ok' : 'warn', (string)ini_get('max_file_uploads'), '');

    $mem = ini_bytes('memory_limit');
    $raisable = false;
    if ($mem !== PHP_INT_MAX) {
        $old = ini_get('memory_limit');
        $raisable = @ini_set('memory_limit', '512M') !== false && ini_bytes('memory_limit') >= 512 << 20;
        @ini_set('memory_limit', $old);
    }
    $add('Memory', 'memory_limit', $mem >= 256 << 20 || $raisable ? 'ok' : 'warn', $mb($mem) . ($raisable ? ' (can be raised for large photos)' : ''),
        'Very large phone photos need ~250 MB to process. If memory can\'t be raised, such photos are refused with a message (the browser normally shrinks them first).');
    $add('Memory', 'Max script time', (int)ini_get('max_execution_time') === 0 || (int)ini_get('max_execution_time') >= 30 ? 'ok' : 'warn', ini_get('max_execution_time') . ' s', '');

    $attach = ACC_ROOT . '/../data/attachments';
    if (!is_dir($attach)) @mkdir($attach, 0750, true);
    $add('Folders', 'data/attachments (uploaded files)', is_dir($attach) && is_writable($attach) ? 'ok' : 'fail', is_dir($attach) ? (is_writable($attach) ? 'writable' : 'NOT writable') : 'missing',
        'Must exist and be writable by PHP (permissions 750/755, owned by the web user).');
    $logDir = dirname(ACC_ERROR_LOG);
    if (!is_dir($logDir)) @mkdir($logDir, 0750, true);
    $add('Folders', 'data/log (error log)', is_dir($logDir) && is_writable($logDir) ? 'ok' : 'warn', is_dir($logDir) ? (is_writable($logDir) ? 'writable' : 'NOT writable') : 'missing', '');
    $tmp = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
    $add('Folders', 'Upload temp folder', is_dir($tmp) && is_writable($tmp) ? 'ok' : 'fail', $tmp, 'PHP stores uploads here first.');
    $protected = is_file(ACC_ROOT . '/../data/.htaccess') && str_contains((string)file_get_contents(ACC_ROOT . '/../data/.htaccess'), 'denied');
    $add('Folders', 'data/ blocked from the web', $protected ? 'ok' : 'fail', $protected ? 'yes (.htaccess)' : 'NO', 'data/.htaccess must contain "Require all denied" so uploaded bills are private.');

    $add('Database', 'Schema version', (int)setting('schema_version') >= ACC_SCHEMA_VERSION ? 'ok' : 'fail', setting('schema_version') . ' / ' . ACC_SCHEMA_VERSION, '');
    return $c;
}

/** Writes, reads back and deletes a test file — and a test image if GD is present. */
function upload_self_test(): array {
    $dir = ACC_ROOT . '/../data/attachments';
    $file = $dir . '/selftest-' . bin2hex(random_bytes(4));
    $results = [];
    $ok = @file_put_contents($file . '.txt', 'ok') === 2 && @file_get_contents($file . '.txt') === 'ok';
    @unlink($file . '.txt');
    $results[] = ['Write a file to data/attachments', $ok];
    if (gd_available()) {
        $im = imagecreatetruecolor(400, 300);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        $src = $file . '-src.jpg';
        imagejpeg($im, $src, 80);
        imagedestroy($im);
        $r = reencode_image($src, 'image/jpeg', $file . '.jpg');
        $results[] = ['Process a photo (GD)', $r === true && is_file($file . '.jpg') ? true : (is_string($r) ? $r : false)];
        @unlink($src); @unlink($file . '.jpg');
    } else {
        $results[] = ['Process a photo (GD)', 'GD not installed — photos will be stored as uploaded'];
    }
    $results[] = ['Detect a PDF', (function () use ($file) { @file_put_contents($file . '.pdf', "%PDF-1.4\n%%EOF"); $m = detect_mime($file . '.pdf'); @unlink($file . '.pdf'); return $m === 'application/pdf'; })()];
    return $results;
}

/** Newest-first entries from the accounts error log. */
function recent_errors(int $limit = 20): array {
    if (!is_file(ACC_ERROR_LOG)) return [];
    $lines = array_slice(file(ACC_ERROR_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -$limit);
    return array_reverse(array_filter(array_map(fn($l) => json_decode($l, true), $lines)));
}
