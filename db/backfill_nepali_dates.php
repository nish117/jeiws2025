<?php
// One-time backfill: fills nepali_date on rows created before that column
// existed. Safe to re-run — only touches rows where nepali_date IS NULL.
//
// Usage: copy this file to admin/backfill_nepali_dates.php on the live
// server, log into the admin CMS in your browser, then open
// yoursite.com/admin/backfill_nepali_dates.php — then delete the file.
session_start();
define('CMS_LOADED', 1);
$credFile = __DIR__ . '/../data/cms_credentials.txt';
if (!file_exists($credFile) || !isset($_SESSION['cms_auth'])) {
    die('Log into the admin CMS in this browser first, then reload this page.');
}
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/NepaliDate.php';

$pdo = db();
header('Content-Type: text/plain');

$stmt = $pdo->query("SELECT id, attendance_date FROM labour_attendance WHERE nepali_date IS NULL");
$upd  = $pdo->prepare("UPDATE labour_attendance SET nepali_date = :nd WHERE id = :id");
$n = 0;
foreach ($stmt as $row) {
    $nd = NepaliDate::adToBs($row['attendance_date']);
    if ($nd) { $upd->execute(['nd' => $nd, 'id' => $row['id']]); $n++; }
}
echo "labour_attendance backfilled: $n\n";

$stmt = $pdo->query("SELECT id, txn_date FROM materials_stock WHERE nepali_date IS NULL");
$upd  = $pdo->prepare("UPDATE materials_stock SET nepali_date = :nd WHERE id = :id");
$n = 0;
foreach ($stmt as $row) {
    $nd = NepaliDate::adToBs($row['txn_date']);
    if ($nd) { $upd->execute(['nd' => $nd, 'id' => $row['id']]); $n++; }
}
echo "materials_stock backfilled: $n\n";
echo "Done — delete this file now.\n";