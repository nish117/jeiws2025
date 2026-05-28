<?php
session_start();
define('CMS_LOADED', 1);
$credFile = __DIR__ . '/../data/cms_credentials.txt';
if (!file_exists($credFile) || !isset($_SESSION['cms_auth'])) {
    header('Location: login.php'); exit;
}

// ── Date range ───────────────────────────────────────────
$range   = $_GET['range'] ?? '7d';
$allowed = ['today' => 0, '7d' => 6, '30d' => 29, 'all' => 36500];
if (!array_key_exists($range, $allowed)) $range = '7d';
$daysBack = $allowed[$range];
$cutoff   = date('Y-m-d', strtotime("-{$daysBack} days"));

// ── Read log ─────────────────────────────────────────────
$logFile = __DIR__ . '/../data/analytics.log';
$entries = [];
if (file_exists($logFile)) {
    $fh = fopen($logFile, 'r');
    while (($line = fgets($fh)) !== false) {
        $e = json_decode(trim($line), true);
        if (!is_array($e)) continue;
        if ($range !== 'all' && ($e['date'] ?? '') < $cutoff) continue;
        $entries[] = $e;
    }
    fclose($fh);
}

// ── UA parsers ───────────────────────────────────────────
function parseBrowser(string $ua): string {
    if (stripos($ua, 'EdgA/')  !== false || stripos($ua, 'Edg/') !== false) return 'Edge';
    if (stripos($ua, 'OPR/')   !== false || stripos($ua, 'Opera') !== false) return 'Opera';
    if (stripos($ua, 'Firefox') !== false) return 'Firefox';
    if (stripos($ua, 'SamsungBrowser') !== false) return 'Samsung';
    if (stripos($ua, 'Chrome') !== false) return 'Chrome';
    if (stripos($ua, 'Safari') !== false) return 'Safari';
    return 'Other';
}
function parseOS(string $ua): string {
    if (stripos($ua, 'Android') !== false) return 'Android';
    if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) return 'iOS';
    if (stripos($ua, 'Windows') !== false) return 'Windows';
    if (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS') !== false) return 'macOS';
    if (stripos($ua, 'Linux') !== false) return 'Linux';
    return 'Other';
}
function parseDevice(string $ua, string $vp): string {
    $w = (int)explode('x', $vp)[0];
    if (stripos($ua, 'Mobile') !== false || ($w > 0 && $w < 768)) return 'Mobile';
    if (stripos($ua, 'iPad') !== false || stripos($ua, 'Tablet') !== false
        || ($w >= 768 && $w <= 1100)) return 'Tablet';
    return 'Desktop';
}
function refDomain(string $ref): string {
    if (!$ref) return '(direct)';
    $host = parse_url($ref, PHP_URL_HOST) ?: $ref;
    $host = preg_replace('/^www\./', '', strtolower($host));
    return $host ?: '(direct)';
}

// ── Aggregate ────────────────────────────────────────────
$totalViews  = count($entries);
$uniqueVids  = [];
$uniqueSids  = [];
$pageCounts  = [];
$browserCts  = [];
$osCts       = [];
$deviceCts   = [];
$refCts      = [];
$dailyCts    = [];
$loadTimes   = [];

foreach ($entries as $e) {
    $vid = $e['vid'] ?? '';
    $sid = $e['sid'] ?? '';
    if ($vid) $uniqueVids[$vid] = true;
    if ($sid) $uniqueSids[$sid] = true;

    $path = $e['path'] ?? '/';
    if (!$path) $path = '/';
    $pageCounts[$path] = ($pageCounts[$path] ?? 0) + 1;

    $ua  = $e['ua']  ?? '';
    $vp  = $e['vp']  ?? '';
    $b   = parseBrowser($ua);
    $os  = parseOS($ua);
    $dev = parseDevice($ua, $vp);
    $browserCts[$b]  = ($browserCts[$b]  ?? 0) + 1;
    $osCts[$os]      = ($osCts[$os]      ?? 0) + 1;
    $deviceCts[$dev] = ($deviceCts[$dev] ?? 0) + 1;

    $dom = refDomain($e['ref'] ?? '');
    $refCts[$dom] = ($refCts[$dom] ?? 0) + 1;

    $date = $e['date'] ?? '';
    if ($date) $dailyCts[$date] = ($dailyCts[$date] ?? 0) + 1;

    $ms = (int)($e['load_ms'] ?? 0);
    if ($ms > 0 && $ms < 60000) $loadTimes[] = $ms;
}

arsort($pageCounts);
arsort($browserCts);
arsort($osCts);
arsort($deviceCts);
arsort($refCts);
ksort($dailyCts);

$uniqueVisitors = count($uniqueVids);
$uniqueSessions = count($uniqueSids);
$avgLoad = $loadTimes ? round(array_sum($loadTimes) / count($loadTimes) / 1000, 2) : 0;

// Build full date series for chart (fill gaps with 0)
$chartDays = ($range === 'all' || $daysBack > 30) ? 30 : ($daysBack + 1);
$chartSeries = [];
for ($i = $chartDays - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $chartSeries[$d] = $dailyCts[$d] ?? 0;
}
$chartMax = max(1, max($chartSeries));

// Recent entries (last 50, newest first)
$recent = array_slice(array_reverse($entries), 0, 50);

// Helper to render a % bar
function pct(int $n, int $total): string {
    return $total ? round($n / $total * 100) . '%' : '0%';
}

$rangeLabels = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'all' => 'All time'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Analytics — JEIWS CMS</title>
<link rel="stylesheet" href="cms.css">
<style>
/* ── Analytics-specific styles ─────────────── */
.an-range-bar{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:28px}
.an-range-btn{padding:6px 16px;border-radius:20px;border:1.5px solid #c8d8e4;background:#fff;
  color:#3a5a72;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;transition:.15s}
.an-range-btn:hover{border-color:#1b6799;color:#1b6799}
.an-range-btn.active{background:#1b6799;border-color:#1b6799;color:#fff}

.an-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px;margin-bottom:28px}
.an-stat{background:#fff;border-radius:10px;padding:20px 20px 16px;box-shadow:0 1px 4px rgba(0,0,0,.07)}
.an-stat-num{font-size:2rem;font-weight:800;color:#0c1e2d;line-height:1}
.an-stat-lbl{font-size:12px;color:#6b849a;font-weight:600;margin-top:6px;text-transform:uppercase;letter-spacing:.5px}

.an-grid{display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:20px}
@media(max-width:900px){.an-grid{grid-template-columns:1fr}}

.an-card{background:#fff;border-radius:10px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.07)}
.an-card-title{font-size:13px;font-weight:700;color:#3a5a72;text-transform:uppercase;
  letter-spacing:.5px;margin-bottom:16px;padding-bottom:10px;border-bottom:1px solid #e4ecf2}

/* Bar chart */
.an-chart{display:flex;align-items:flex-end;gap:4px;height:120px;padding-bottom:24px;position:relative}
.an-chart-wrap{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;min-width:0}
.an-bar{width:100%;background:#1b6799;border-radius:4px 4px 0 0;min-height:2px;transition:.2s}
.an-bar:hover{background:#145d88}
.an-bar-lbl{font-size:9px;color:#8fa5b5;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
  max-width:100%;text-align:center;transform:rotate(-45deg);transform-origin:top left;
  position:absolute;bottom:0;margin-left:2px}

/* Stat rows */
.an-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f0f4f8;font-size:13px}
.an-row:last-child{border:none}
.an-row-label{flex:1;color:#1a2b3c;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.an-row-count{font-weight:700;color:#1b6799;min-width:36px;text-align:right}
.an-row-bar-wrap{width:90px;height:6px;background:#e4ecf2;border-radius:3px;overflow:hidden}
.an-row-bar{height:100%;background:#1b6799;border-radius:3px}

/* Recent table */
.an-table{width:100%;border-collapse:collapse;font-size:12px}
.an-table th{text-align:left;padding:8px 10px;background:#f0f4f8;color:#6b849a;
  font-weight:700;text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #dde8f0}
.an-table td{padding:8px 10px;border-bottom:1px solid #f0f4f8;color:#1a2b3c;
  max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.an-table tr:hover td{background:#f8fafc}
.an-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700}
.an-badge-m{background:#e0f0ff;color:#1b6799}
.an-badge-d{background:#e8f4e8;color:#2a7a3a}
.an-badge-t{background:#fff3e0;color:#c06000}
.an-empty{text-align:center;padding:40px;color:#8fa5b5;font-size:14px}
</style>
</head>
<body>

<nav class="cms-nav">
  <a href="index.php" class="cms-brand">
    <img src="../assets/logo.png" alt="">
    JEIWS <span>CMS</span>
  </a>
  <div class="cms-nav-right">
    <a href="analytics.php" style="color:#F0A030;font-weight:700">Analytics</a>
    <a href="../index.html" target="_blank">← View Site</a>
    <a href="logout.php" class="btn-logout">Logout</a>
  </div>
</nav>

<main class="cms-main">
  <div class="page-hdr">
    <div>
      <h1>Analytics</h1>
      <p><?= htmlspecialchars($rangeLabels[$range]) ?> &middot; <?= $totalViews ?> page view<?= $totalViews !== 1 ? 's' : '' ?></p>
    </div>
  </div>

  <!-- Range selector -->
  <div class="an-range-bar">
    <?php foreach ($rangeLabels as $key => $label): ?>
    <a href="?range=<?= $key ?>"
       class="an-range-btn <?= $range === $key ? 'active' : '' ?>">
      <?= $label ?>
    </a>
    <?php endforeach ?>
  </div>

  <!-- Summary stats -->
  <div class="an-stats">
    <div class="an-stat">
      <div class="an-stat-num"><?= number_format($totalViews) ?></div>
      <div class="an-stat-lbl">Page Views</div>
    </div>
    <div class="an-stat">
      <div class="an-stat-num"><?= number_format($uniqueVisitors) ?></div>
      <div class="an-stat-lbl">Unique Visitors</div>
    </div>
    <div class="an-stat">
      <div class="an-stat-num"><?= number_format($uniqueSessions) ?></div>
      <div class="an-stat-lbl">Sessions</div>
    </div>
    <div class="an-stat">
      <div class="an-stat-num"><?= $avgLoad > 0 ? $avgLoad . 's' : '—' ?></div>
      <div class="an-stat-lbl">Avg Load Time</div>
    </div>
  </div>

  <?php if ($totalViews === 0): ?>
  <div class="an-empty">No data recorded yet for this period.<br>
    Make sure <code>analytics.js</code> is loaded on your public pages.</div>
  <?php else: ?>

  <!-- Daily chart + Top pages -->
  <div class="an-grid">

    <!-- Daily visits chart -->
    <div class="an-card">
      <div class="an-card-title">Daily Page Views</div>
      <div class="an-chart">
        <?php foreach ($chartSeries as $day => $count): ?>
        <div class="an-chart-wrap" style="position:relative">
          <div class="an-bar"
               style="height:<?= round($count / $chartMax * 100) ?>%"
               title="<?= $day ?>: <?= $count ?> view<?= $count !== 1 ? 's' : '' ?>">
          </div>
          <span class="an-bar-lbl"><?= date('M j', strtotime($day)) ?></span>
        </div>
        <?php endforeach ?>
      </div>
    </div>

    <!-- Top pages -->
    <div class="an-card">
      <div class="an-card-title">Top Pages</div>
      <?php if (empty($pageCounts)): ?>
        <div class="an-empty">No data</div>
      <?php else:
        $topPages = array_slice($pageCounts, 0, 8, true);
        $maxPage  = max($topPages);
        foreach ($topPages as $path => $cnt): ?>
      <div class="an-row">
        <span class="an-row-label" title="<?= htmlspecialchars($path) ?>">
          <?= htmlspecialchars($path) ?>
        </span>
        <div class="an-row-bar-wrap">
          <div class="an-row-bar" style="width:<?= pct($cnt, $maxPage) ?>"></div>
        </div>
        <span class="an-row-count"><?= $cnt ?></span>
      </div>
      <?php endforeach; endif ?>
    </div>
  </div>

  <!-- Browsers / Devices / OS / Referrers -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:20px;margin-bottom:20px">

    <!-- Browsers -->
    <div class="an-card">
      <div class="an-card-title">Browsers</div>
      <?php $maxB = max(1, max($browserCts)); foreach ($browserCts as $b => $cnt): ?>
      <div class="an-row">
        <span class="an-row-label"><?= htmlspecialchars($b) ?></span>
        <div class="an-row-bar-wrap">
          <div class="an-row-bar" style="width:<?= pct($cnt, $maxB) ?>"></div>
        </div>
        <span class="an-row-count"><?= $cnt ?></span>
      </div>
      <?php endforeach ?>
    </div>

    <!-- Devices -->
    <div class="an-card">
      <div class="an-card-title">Devices</div>
      <?php $maxD = max(1, max($deviceCts)); foreach ($deviceCts as $dev => $cnt): ?>
      <div class="an-row">
        <span class="an-row-label"><?= htmlspecialchars($dev) ?></span>
        <div class="an-row-bar-wrap">
          <div class="an-row-bar" style="width:<?= pct($cnt, $maxD) ?>"></div>
        </div>
        <span class="an-row-count"><?= $cnt ?></span>
      </div>
      <?php endforeach ?>
    </div>

    <!-- OS -->
    <div class="an-card">
      <div class="an-card-title">Operating Systems</div>
      <?php $maxO = max(1, max($osCts)); foreach ($osCts as $os => $cnt): ?>
      <div class="an-row">
        <span class="an-row-label"><?= htmlspecialchars($os) ?></span>
        <div class="an-row-bar-wrap">
          <div class="an-row-bar" style="width:<?= pct($cnt, $maxO) ?>"></div>
        </div>
        <span class="an-row-count"><?= $cnt ?></span>
      </div>
      <?php endforeach ?>
    </div>

    <!-- Referrers -->
    <div class="an-card">
      <div class="an-card-title">Top Referrers</div>
      <?php
        $topRef = array_slice($refCts, 0, 8, true);
        $maxR   = max(1, max($topRef));
        foreach ($topRef as $dom => $cnt): ?>
      <div class="an-row">
        <span class="an-row-label" title="<?= htmlspecialchars($dom) ?>">
          <?= htmlspecialchars($dom) ?>
        </span>
        <div class="an-row-bar-wrap">
          <div class="an-row-bar" style="width:<?= pct($cnt, $maxR) ?>"></div>
        </div>
        <span class="an-row-count"><?= $cnt ?></span>
      </div>
      <?php endforeach ?>
    </div>

  </div>

  <!-- Recent visits table -->
  <div class="an-card">
    <div class="an-card-title">Recent Visits (last 50)</div>
    <?php if (empty($recent)): ?>
      <div class="an-empty">No visits recorded.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="an-table">
      <thead>
        <tr>
          <th>Date / Time</th>
          <th>Page</th>
          <th>Device</th>
          <th>Browser</th>
          <th>OS</th>
          <th>Language</th>
          <th>Timezone</th>
          <th>Referrer</th>
          <th>Load</th>
          <th>Screen</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recent as $e):
          $ua  = $e['ua']   ?? '';
          $vp  = $e['vp']   ?? '';
          $dev = parseDevice($ua, $vp);
          $devClass = ['Mobile' => 'an-badge-m', 'Tablet' => 'an-badge-t', 'Desktop' => 'an-badge-d'][$dev] ?? '';
          $ms  = (int)($e['load_ms'] ?? 0);
        ?>
        <tr>
          <td><?= htmlspecialchars(($e['date'] ?? '') . ' ' . ($e['time'] ?? '')) ?></td>
          <td title="<?= htmlspecialchars($e['path'] ?? '') ?>"><?= htmlspecialchars($e['path'] ?? '') ?></td>
          <td><span class="an-badge <?= $devClass ?>"><?= $dev ?></span></td>
          <td><?= htmlspecialchars(parseBrowser($ua)) ?></td>
          <td><?= htmlspecialchars(parseOS($ua)) ?></td>
          <td><?= htmlspecialchars($e['lang'] ?? '') ?></td>
          <td><?= htmlspecialchars($e['tz']   ?? '') ?></td>
          <td title="<?= htmlspecialchars($e['ref'] ?? '') ?>"><?= htmlspecialchars(refDomain($e['ref'] ?? '')) ?></td>
          <td><?= $ms > 0 ? round($ms / 1000, 1) . 's' : '—' ?></td>
          <td><?= htmlspecialchars($e['screen'] ?? '') ?></td>
        </tr>
        <?php endforeach ?>
      </tbody>
    </table>
    </div>
    <?php endif ?>
  </div>

  <?php endif ?>
</main>
</body>
</html>
