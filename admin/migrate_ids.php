<?php
// One-time migration: convert integer project IDs to random 8-char hex strings.
// Run this once via browser (must be logged in) or CLI, then delete the file.
session_start();
define('CMS_LOADED', 1);
$credFile = __DIR__ . '/../data/cms_credentials.txt';
if (!file_exists($credFile) || !isset($_SESSION['cms_auth'])) {
    http_response_code(403); echo 'Unauthorized — log in to the CMS first.'; exit;
}

require_once __DIR__ . '/functions.php';

$projects = loadProjects();
$changed  = 0;
$log      = [];

foreach ($projects as &$p) {
    $oldId = (string)($p['id'] ?? '');

    // Skip projects that already have a valid 8-char hex ID
    if (preg_match('/^[a-f0-9]{8}$/', $oldId)) {
        $log[] = "SKIP  id=$oldId  \"{$p['title']}\"";
        continue;
    }

    $newId = generateId($projects);

    // Rename image directory on disk
    $oldDir = IMG_BASE . '/' . $oldId;
    $newDir = IMG_BASE . '/' . $newId;
    if (is_dir($oldDir)) {
        if (!rename($oldDir, $newDir)) {
            $log[] = "ERROR could not rename $oldDir → $newDir";
            continue;
        }
    }

    // Rewrite path prefix in all arrays/fields
    $oldPrefix = IMG_URL . '/' . $oldId . '/';
    $newPrefix = IMG_URL . '/' . $newId . '/';

    $rewrite = fn(string $path) => str_starts_with($path, $oldPrefix)
        ? $newPrefix . substr($path, strlen($oldPrefix))
        : $path;

    $p['gallery']            = array_map($rewrite, $p['gallery'] ?? []);
    if (!empty($p['image']))           $p['image']            = $rewrite($p['image']);
    if (!empty($p['unpublished_images']))
        $p['unpublished_images'] = array_map($rewrite, $p['unpublished_images']);

    $p['id'] = $newId;
    $log[]   = "MIGRATED  $oldId → $newId  \"{$p['title']}\"";
    $changed++;
}
unset($p);

if ($changed > 0) {
    saveProjects($projects);
}

header('Content-Type: text/plain; charset=utf-8');
echo "Migration complete — $changed project(s) updated.\n\n";
echo implode("\n", $log) . "\n";
echo "\nDELETE this file after confirming everything works.\n";
