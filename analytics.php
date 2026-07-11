<?php
// Analytics beacon receiver — accepts POST only, no auth required (public endpoint)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit;
}

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

// Drop obvious bots
$botKeywords = ['bot', 'crawl', 'spider', 'slurp', 'curl/', 'wget/', 'python-requests',
                'Go-http-client', 'Googlebot', 'Bingbot', 'YandexBot', 'DuckDuckBot'];
foreach ($botKeywords as $kw) {
    if (stripos($ua, $kw) !== false) { http_response_code(204); exit; }
}

$raw = file_get_contents('php://input');
if (!$raw || strlen($raw) > 4096) { http_response_code(204); exit; }

$data = json_decode($raw, true);
if (!is_array($data)) { http_response_code(204); exit; }

$ip      = $_SERVER['REMOTE_ADDR'] ?? '';
$logDir  = __DIR__ . '/data/log';
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
$logFile = $logDir . '/analytics.log';

// Sanitize helpers
$clean = function(string $v, int $max, string $pattern = ''): string {
    $v = trim($v);
    if ($pattern) $v = preg_replace($pattern, '', $v);
    return substr($v, 0, $max);
};

$entry = [
    'ts'      => time(),
    'date'    => date('Y-m-d'),
    'time'    => date('H:i'),
    // IP hashed daily — cannot reconstruct original IP, resets each day
    'ip_hash' => hash('sha256', $ip . '|' . date('Y-m-d')),
    'ua'      => substr($ua, 0, 300),
    'path'    => $clean($data['path']     ?? '', 200, '/[^\w\/\.\-\?\=\&\%\_]/'),
    'title'   => $clean(strip_tags($data['title']    ?? ''), 150),
    'ref'     => $clean($data['referrer'] ?? '', 300),
    'screen'  => $clean($data['screen']   ?? '', 12,  '/[^0-9x]/'),
    'vp'      => $clean($data['viewport'] ?? '', 12,  '/[^0-9x]/'),
    'lang'    => $clean($data['lang']     ?? '', 10,  '/[^a-zA-Z\-]/'),
    'tz'      => $clean($data['tz']       ?? '', 50,  '/[^a-zA-Z\/\_]/'),
    'touch'   => (int)($data['touch']     ?? 0) ? 1 : 0,
    'conn'    => $clean($data['conn']     ?? '', 20,  '/[^a-z0-9\-]/'),
    'load_ms' => max(0, min(60000, (int)($data['load_ms'] ?? 0))),
    'vid'     => $clean($data['visitor']  ?? '', 40,  '/[^a-zA-Z0-9\-]/'),
    'sid'     => $clean($data['session']  ?? '', 40,  '/[^a-zA-Z0-9\-]/'),
];

// Deduplicate: skip if same visitor+session+path logged in the last 5 seconds
// (handles double-fires on slow connections)
if ($entry['vid'] && $entry['sid']) {
    $lockFile = sys_get_temp_dir() . '/jan_' . hash('crc32', $entry['vid'] . $entry['sid'] . $entry['path']) . '.lock';
    if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 5) {
        http_response_code(204); exit;
    }
    @touch($lockFile);
}

file_put_contents($logFile, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
http_response_code(204);
