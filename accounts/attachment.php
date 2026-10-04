<?php
/** Streams an attachment to signed-in users only. ?id=…[&download=1] */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/attachments.php';
$user = require_login();

$stmt = db()->prepare('SELECT * FROM acc_attachments WHERE id = ?');
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$att = $stmt->fetch();
$path = $att ? attachment_dir() . '/' . basename($att['stored_name']) : null;
if (!$att || !is_file($path)) { http_response_code(404); exit('File not found.'); }

$disposition = !empty($_GET['download']) ? 'attachment' : 'inline';
$filename = preg_replace('/[^\w.\- ]+/u', '_', $att['original_name']);
if ($att['mime_type'] === 'image/jpeg' && !preg_match('/\.jpe?g$/i', $filename)) $filename .= '.jpg';

header('Content-Type: ' . $att['mime_type']);
header('Content-Length: ' . filesize($path));
header("Content-Disposition: {$disposition}; filename=\"{$filename}\"");
header('Cache-Control: private, max-age=3600');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
readfile($path);
