<?php
// One-time backfill: mirror all existing projects.json entries into the
// Postgres projects table (used as an FK anchor by the site/ portal).
// Run this once via browser (must be logged in) after the database is
// set up, then it's safe to delete — ongoing sync happens automatically
// from admin/api.php on every save/delete.
session_start();
define('CMS_LOADED', 1);
$credFile = __DIR__ . '/../data/cms_credentials.txt';
if (!file_exists($credFile) || !isset($_SESSION['cms_auth'])) {
    http_response_code(403); echo 'Unauthorized — log in to the CMS first.'; exit;
}

require_once __DIR__ . '/functions.php';

$projects = loadProjects();
$log = [];

foreach ($projects as $p) {
    $id    = (string)($p['id'] ?? '');
    $title = (string)($p['title'] ?? '');
    if ($id === '') continue;
    syncProjectToDb($id, $title, empty($p['is_draft']));
    $log[] = "synced  id=$id  \"$title\"";
}

header('Content-Type: text/plain; charset=utf-8');
echo "Backfill complete — " . count($log) . " project(s) synced.\n\n";
echo implode("\n", $log) . "\n";
echo "\nDELETE this file after confirming everything works.\n";
