<?php
session_start();
define('CMS_LOADED', 1);
$credFile = __DIR__ . '/../data/cms_credentials.txt';
if (!file_exists($credFile) || !isset($_SESSION['cms_auth'])) {
    header('Location: login.php'); exit;
}

// ── Date range ───────────────────────────────────────────────────────────────
$range   = $_GET['range'] ?? '7d';
$allowed = ['today' => 0, '7d' => 6, '30d' => 29, 'all' => 36500];
if (!array_key_exists($range, $allowed)) $range = '7d';
$daysBack = $allowed[$range];
$cutoff   = date('Y-m-d', strtotime("-{$daysBack} days"));
$prevCutoff = $range !== 'all'
    ? date('Y-m-d', strtotime('-' . ($daysBack * 2 + 1) . ' days'))
    : '';

// ── Read log (single pass, split into current + previous periods) ────────────
$logFile = __DIR__ . '/../data/log/analytics.log';
$entries = [];
$prevEntries = [];
if (file_exists($logFile)) {
    $fh = fopen($logFile, 'r');
    while (($line = fgets($fh)) !== false) {
        $e = json_decode(trim($line), true);
        if (!is_array($e)) continue;
        $d = $e['date'] ?? '';
        if ($range !== 'all' && $d < $cutoff) {
            if ($prevCutoff && $d >= $prevCutoff) $prevEntries[] = $e;
            continue;
        }
        $entries[] = $e;
    }
    fclose($fh);
}

// ── UA parsers ───────────────────────────────────────────────────────────────
function parseBrowser(string $ua): string {
    if (stripos($ua, 'EdgA/') !== false || stripos($ua, 'Edg/') !== false) return 'Edge';
    if (stripos($ua, 'OPR/')  !== false || stripos($ua, 'Opera') !== false) return 'Opera';
    if (stripos($ua, 'Firefox') !== false)       return 'Firefox';
    if (stripos($ua, 'SamsungBrowser') !== false) return 'Samsung';
    if (stripos($ua, 'Chrome') !== false)        return 'Chrome';
    if (stripos($ua, 'Safari') !== false)        return 'Safari';
    return 'Other';
}
function parseOS(string $ua): string {
    if (stripos($ua, 'Android') !== false)  return 'Android';
    if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) return 'iOS';
    if (stripos($ua, 'Windows') !== false)  return 'Windows';
    if (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS') !== false) return 'macOS';
    if (stripos($ua, 'Linux') !== false)    return 'Linux';
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
    return preg_replace('/^www\./', '', strtolower($host)) ?: '(direct)';
}

// ── Aggregate current period ─────────────────────────────────────────────────
$uniqueVids = $uniqueSids = $pageCounts = $browserCts = $osCts = $deviceCts = $refCts = $dailyCts = $loadTimes = [];
$hourlyCts  = array_fill(0, 24, 0);

foreach ($entries as $e) {
    $vid = $e['vid'] ?? ''; $sid = $e['sid'] ?? '';
    if ($vid) $uniqueVids[$vid] = true;
    if ($sid) $uniqueSids[$sid] = true;

    $path = $e['path'] ?: '/';
    $pageCounts[$path] = ($pageCounts[$path] ?? 0) + 1;

    $ua = $e['ua'] ?? ''; $vp = $e['vp'] ?? '';
    $b = parseBrowser($ua); $os = parseOS($ua); $dev = parseDevice($ua, $vp);
    $browserCts[$b]  = ($browserCts[$b]  ?? 0) + 1;
    $osCts[$os]      = ($osCts[$os]      ?? 0) + 1;
    $deviceCts[$dev] = ($deviceCts[$dev] ?? 0) + 1;

    $dom = refDomain($e['ref'] ?? '');
    $refCts[$dom] = ($refCts[$dom] ?? 0) + 1;

    $date = $e['date'] ?? '';
    if ($date) $dailyCts[$date] = ($dailyCts[$date] ?? 0) + 1;

    if (!empty($e['time'])) $hourlyCts[(int)substr($e['time'], 0, 2)]++;

    $ms = (int)($e['load_ms'] ?? 0);
    if ($ms > 0 && $ms < 60000) $loadTimes[] = $ms;
}

arsort($pageCounts); arsort($browserCts); arsort($osCts);
arsort($deviceCts);  arsort($refCts);     ksort($dailyCts);

$totalViews     = count($entries);
$uniqueVisitors = count($uniqueVids);
$uniqueSessions = count($uniqueSids);
$avgLoad        = $loadTimes ? round(array_sum($loadTimes) / count($loadTimes) / 1000, 2) : 0;

// ── Aggregate previous period (for trends) ───────────────────────────────────
$prevViews = count($prevEntries);
$prevVids  = [];
foreach ($prevEntries as $pe) { $v = $pe['vid'] ?? ''; if ($v) $prevVids[$v] = true; }
$prevVisitors = count($prevVids);

function trendPct(int $curr, int $prev): ?int {
    return $prev > 0 ? (int)round(($curr - $prev) / $prev * 100) : null;
}

// ── Chart data ───────────────────────────────────────────────────────────────
$chartDays = ($range === 'all' || $daysBack > 30) ? 30 : ($daysBack + 1);
$chartSeries = [];
for ($i = $chartDays - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $chartSeries[$d] = $dailyCts[$d] ?? 0;
}

// For "today" use hourly labels
$isToday = ($range === 'today');
if ($isToday) {
    $chartLabels = array_map(fn($h) => sprintf('%02d:00', $h), range(0, 23));
    $chartData   = array_values($hourlyCts);
} else {
    $chartLabels = array_map(fn($d) => date('M j', strtotime($d)), array_keys($chartSeries));
    $chartData   = array_values($chartSeries);
}

$topPages  = array_slice($pageCounts, 0, 8, true);
$topRef    = array_slice($refCts, 0, 7, true);
$recent    = array_slice(array_reverse($entries), 0, 50);

$rangeLabels = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'all' => 'All time'];

// Bounce rate: sessions with only 1 pageview
$sessionPageCount = [];
foreach ($entries as $e) {
    $sid = $e['sid'] ?? 'x';
    $sessionPageCount[$sid] = ($sessionPageCount[$sid] ?? 0) + 1;
}
$bounced = count(array_filter($sessionPageCount, fn($c) => $c === 1));
$bounceRate = $uniqueSessions > 0 ? round($bounced / $uniqueSessions * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Analytics — JEIWS CMS</title>
<link rel="stylesheet" href="cms.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ── Base ─────────────────────────────────────────────── */
.an-wrap{max-width:1300px;margin:0 auto;padding:0 0 60px}

/* ── Range bar ────────────────────────────────────────── */
.an-range-bar{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:28px}
.an-range-btn{padding:8px 18px;min-height:36px;display:inline-flex;align-items:center;border-radius:100px;border:1.5px solid var(--border);background:var(--surface);
  color:var(--text);font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;
  transition:background var(--t),border-color var(--t),color var(--t);line-height:1}
.an-range-btn:hover{border-color:var(--blue);color:var(--blue)}
.an-range-btn.active{background:var(--blue);border-color:var(--blue);color:#fff}

/* ── Stat cards ───────────────────────────────────────── */
.an-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;margin-bottom:24px}
.an-stat{background:var(--surface);border-radius:var(--r-lg);padding:22px 20px 18px;
  box-shadow:var(--s1);border:1px solid var(--border-2);position:relative;overflow:hidden}
.an-stat::after{content:'';position:absolute;top:0;left:0;right:0;height:3px;
  background:linear-gradient(90deg,var(--gold-dim),var(--gold))}
.an-stat-icon{color:var(--blue);font-size:15px;margin-bottom:8px;display:block}
.an-stat-num{font-family:'Sora',sans-serif;font-size:2rem;font-weight:800;color:var(--navy);line-height:1;letter-spacing:-1px}
.an-stat-lbl{font-size:11px;color:var(--muted);font-weight:700;margin-top:6px;
  text-transform:uppercase;letter-spacing:.6px}
.an-stat-trend{display:inline-flex;align-items:center;gap:4px;margin-top:8px;
  font-size:12px;font-weight:700;padding:2px 8px;border-radius:20px}
.an-stat-trend.up{background:#E8F7EF;color:#1A9966}
.an-stat-trend.down{background:#FDE8E8;color:#C0392B}
.an-stat-trend.neu{background:var(--bg);color:var(--muted)}

/* ── Cards ────────────────────────────────────────────── */
.an-card{background:var(--surface);border-radius:var(--r-lg);padding:22px;box-shadow:var(--s1);border:1px solid var(--border-2)}
.an-card-hdr{display:flex;align-items:center;justify-content:space-between;
  margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid var(--border-2)}
.an-card-title{font-size:13px;font-weight:700;color:var(--navy);
  text-transform:uppercase;letter-spacing:.5px;margin:0}
.an-card-sub{font-size:11px;color:var(--muted-2)}

/* ── Layout grids ─────────────────────────────────────── */
.an-row-2{display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:20px}
.an-row-3{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:20px}
.an-row-2b{display:grid;grid-template-columns:1fr 2fr;gap:20px;margin-bottom:20px}
@media(max-width:1024px){.an-row-2,.an-row-2b{grid-template-columns:1fr}}
@media(max-width:760px){.an-row-3{grid-template-columns:1fr}}

/* ── Chart canvases ───────────────────────────────────── */
.an-chart-wrap{position:relative;height:260px}
.an-donut-wrap{position:relative;height:220px}

/* ── Horizontal bar rows ──────────────────────────────── */
.an-hrow{padding:9px 0;border-bottom:1px solid var(--border-2);display:flex;align-items:center;gap:10px;font-size:13px}
.an-hrow:last-child{border:none;padding-bottom:0}
.an-hrow-rank{font-size:11px;font-weight:700;color:#B0BEC5;min-width:18px;text-align:center}
.an-hrow-label{flex:1;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  min-width:0;font-weight:500}
.an-hrow-bar-bg{width:80px;height:6px;background:var(--blue-pale);border-radius:3px;overflow:hidden;flex-shrink:0}
.an-hrow-bar-fill{height:100%;border-radius:3px;background:linear-gradient(90deg,var(--blue-dim),var(--blue))}
.an-hrow-count{font-weight:700;color:var(--blue);min-width:28px;text-align:right;font-size:13px}
.an-hrow-pct{font-size:11px;color:var(--muted-2);min-width:32px;text-align:right}

/* ── Recent table ─────────────────────────────────────── */
.an-table-wrap{overflow-x:auto}
.an-table{width:100%;border-collapse:collapse;font-size:12px;min-width:700px}
.an-table th{text-align:left;padding:9px 12px;background:#F6F9FC;color:var(--muted);
  font-weight:700;text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid var(--border-2);
  white-space:nowrap}
.an-table td{padding:9px 12px;border-bottom:1px solid var(--border-2);color:var(--text);
  max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.an-table tr:hover td{background:#F8FAFC}
.an-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700}
.an-badge-m{background:#DBEAFE;color:var(--blue)}
.an-badge-d{background:#DCFCE7;color:#166534}
.an-badge-t{background:#FEF3C7;color:#92400E}

/* ── Empty state ──────────────────────────────────────── */
.an-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;
  padding:48px 24px;color:var(--muted-2);font-size:14px;gap:10px;text-align:center}
.an-empty-icon{font-size:32px;opacity:.5;color:var(--border)}
</style>
</head>
<body>

<nav class="cms-nav">
  <a href="index.php" class="cms-brand">
    <img src="../assets/logo.png" alt="">JEIWS <span>CMS</span>
  </a>
  <div class="cms-nav-right">
    <a href="index.php">Projects</a>
    <a href="analytics.php" class="active">Analytics</a>
    <a href="users.php">Site Users</a>
    <a href="materials.php">Materials</a>
    <a href="../index.html" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i> View Site</a>
    <a href="logout.php" class="btn-logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
  </div>
</nav>

<main class="cms-main">
<div class="an-wrap">

  <div class="page-hdr">
    <div>
      <h1>Analytics</h1>
      <p><?= htmlspecialchars($rangeLabels[$range]) ?> &middot; <?= number_format($totalViews) ?> page view<?= $totalViews !== 1 ? 's' : '' ?></p>
    </div>
  </div>

  <!-- Range selector -->
  <div class="an-range-bar">
    <?php foreach ($rangeLabels as $key => $lbl): ?>
    <a href="?range=<?= $key ?>" class="an-range-btn <?= $range === $key ? 'active' : '' ?>"><?= $lbl ?></a>
    <?php endforeach ?>
  </div>

  <!-- ── Stat cards ────────────────────────────────────── -->
  <div class="an-stats">
    <?php
    function statCard(string $num, string $label, ?int $trendPct, string $iconClass): void {
        echo "<div class='an-stat'>";
        echo "<i class='an-stat-icon fa-solid $iconClass'></i>";
        echo "<div class='an-stat-num'>$num</div>";
        echo "<div class='an-stat-lbl'>$label</div>";
        if ($trendPct !== null) {
            $cls  = $trendPct > 0 ? 'up' : ($trendPct < 0 ? 'down' : 'neu');
            $arrIcon = $trendPct > 0 ? 'fa-arrow-up' : ($trendPct < 0 ? 'fa-arrow-down' : 'fa-arrow-right');
            $abs  = abs($trendPct);
            echo "<div class='an-stat-trend $cls'><i class='fa-solid $arrIcon'></i> $abs% vs prev</div>";
        }
        echo "</div>";
    }
    statCard(number_format($totalViews),     'Page Views',       trendPct($totalViews, $prevViews),         'fa-file-lines');
    statCard(number_format($uniqueVisitors), 'Unique Visitors',  trendPct($uniqueVisitors, $prevVisitors),  'fa-users');
    statCard(number_format($uniqueSessions), 'Sessions',         null,                                      'fa-rotate');
    statCard($bounceRate . '%',              'Bounce Rate',      null,                                      'fa-arrow-turn-down-left');
    statCard($avgLoad > 0 ? $avgLoad . 's' : '—', 'Avg Load Time', null,                                   'fa-bolt');
    ?>
  </div>

  <?php if ($totalViews === 0): ?>
  <div class="an-card">
    <div class="an-empty">
      <div class="an-empty-icon"><i class="fa-solid fa-chart-simple"></i></div>
      <strong>No data for this period</strong>
      <span>Visits will appear here once <code>analytics.js</code> is active on your pages.</span>
    </div>
  </div>
  <?php else: ?>

  <!-- ── Line chart + Devices doughnut ─────────────────── -->
  <div class="an-row-2">

    <div class="an-card">
      <div class="an-card-hdr">
        <span class="an-card-title"><?= $isToday ? 'Hourly Traffic — Today' : 'Daily Page Views' ?></span>
        <span class="an-card-sub">Total: <?= number_format($totalViews) ?></span>
      </div>
      <div class="an-chart-wrap">
        <canvas id="lineChart"></canvas>
      </div>
    </div>

    <div class="an-card">
      <div class="an-card-hdr">
        <span class="an-card-title">Devices</span>
        <span class="an-card-sub"><?= number_format($totalViews) ?> views</span>
      </div>
      <div class="an-donut-wrap">
        <canvas id="deviceChart"></canvas>
      </div>
      <!-- Legend row below chart -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;justify-content:center">
        <?php
        $devColors = ['Desktop' => '#1b6799', 'Mobile' => '#F0A030', 'Tablet' => '#2a9d8f'];
        foreach ($deviceCts as $dev => $cnt):
          $pct = $totalViews ? round($cnt / $totalViews * 100) : 0;
          $col = $devColors[$dev] ?? '#888';
        ?>
        <span style="font-size:12px;display:flex;align-items:center;gap:5px">
          <span style="width:10px;height:10px;border-radius:2px;background:<?= $col ?>;flex-shrink:0"></span>
          <?= htmlspecialchars($dev) ?> <strong><?= $pct ?>%</strong>
        </span>
        <?php endforeach ?>
      </div>
    </div>
  </div>

  <!-- ── Browsers / OS / Top pages ─────────────────────── -->
  <div class="an-row-3">

    <div class="an-card">
      <div class="an-card-hdr">
        <span class="an-card-title">Browsers</span>
        <span class="an-card-sub"><?= count($browserCts) ?> types</span>
      </div>
      <div class="an-donut-wrap">
        <canvas id="browserChart"></canvas>
      </div>
    </div>

    <div class="an-card">
      <div class="an-card-hdr">
        <span class="an-card-title">Operating Systems</span>
        <span class="an-card-sub"><?= count($osCts) ?> types</span>
      </div>
      <div class="an-donut-wrap">
        <canvas id="osChart"></canvas>
      </div>
    </div>

    <div class="an-card">
      <div class="an-card-hdr">
        <span class="an-card-title">Top Pages</span>
        <span class="an-card-sub"><?= count($pageCounts) ?> unique</span>
      </div>
      <?php
      $maxPage = $topPages ? max($topPages) : 1;
      $rank = 1;
      foreach ($topPages as $path => $cnt):
        $pct = $totalViews ? round($cnt / $totalViews * 100) : 0;
        $barW = round($cnt / $maxPage * 100);
      ?>
      <div class="an-hrow">
        <span class="an-hrow-rank"><?= $rank++ ?></span>
        <span class="an-hrow-label" title="<?= htmlspecialchars($path) ?>"><?= htmlspecialchars($path) ?></span>
        <div class="an-hrow-bar-bg"><div class="an-hrow-bar-fill" style="width:<?= $barW ?>%"></div></div>
        <span class="an-hrow-count"><?= $cnt ?></span>
        <span class="an-hrow-pct"><?= $pct ?>%</span>
      </div>
      <?php endforeach ?>
    </div>

  </div>

  <!-- ── Referrers + Recent visits ─────────────────────── -->
  <div class="an-row-2b">

    <div class="an-card">
      <div class="an-card-hdr">
        <span class="an-card-title">Traffic Sources</span>
        <span class="an-card-sub"><?= count($refCts) ?> sources</span>
      </div>
      <div class="an-donut-wrap" style="height:200px">
        <canvas id="refChart"></canvas>
      </div>
      <div style="margin-top:16px">
      <?php
      $maxRef = $topRef ? max($topRef) : 1;
      $rank = 1;
      foreach ($topRef as $dom => $cnt):
        $barW = round($cnt / $maxRef * 100);
        $pct  = $totalViews ? round($cnt / $totalViews * 100) : 0;
      ?>
      <div class="an-hrow">
        <span class="an-hrow-rank"><?= $rank++ ?></span>
        <span class="an-hrow-label" title="<?= htmlspecialchars($dom) ?>"><?= htmlspecialchars($dom) ?></span>
        <div class="an-hrow-bar-bg"><div class="an-hrow-bar-fill" style="width:<?= $barW ?>%"></div></div>
        <span class="an-hrow-count"><?= $cnt ?></span>
        <span class="an-hrow-pct"><?= $pct ?>%</span>
      </div>
      <?php endforeach ?>
      </div>
    </div>

    <div class="an-card">
      <div class="an-card-hdr">
        <span class="an-card-title">Recent Visits</span>
        <span class="an-card-sub">Last <?= min(50, count($entries)) ?> entries</span>
      </div>
      <div class="an-table-wrap">
      <table class="an-table">
        <thead>
          <tr>
            <th>Time</th>
            <th>Page</th>
            <th>Device</th>
            <th>Browser</th>
            <th>OS</th>
            <th>Referrer</th>
            <th>Load</th>
            <th>Lang</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($recent as $e):
          $ua  = $e['ua']  ?? '';
          $vp  = $e['vp']  ?? '';
          $dev = parseDevice($ua, $vp);
          $cls = ['Mobile' => 'an-badge-m', 'Tablet' => 'an-badge-t', 'Desktop' => 'an-badge-d'][$dev] ?? '';
          $ms  = (int)($e['load_ms'] ?? 0);
        ?>
        <tr>
          <td title="<?= htmlspecialchars(($e['date'] ?? '') . ' ' . ($e['time'] ?? '')) ?>">
            <?= htmlspecialchars($e['date'] ?? '') ?> <?= htmlspecialchars($e['time'] ?? '') ?>
          </td>
          <td title="<?= htmlspecialchars($e['path'] ?? '') ?>"><?= htmlspecialchars($e['path'] ?? '') ?></td>
          <td><span class="an-badge <?= $cls ?>"><?= $dev ?></span></td>
          <td><?= htmlspecialchars(parseBrowser($ua)) ?></td>
          <td><?= htmlspecialchars(parseOS($ua)) ?></td>
          <td title="<?= htmlspecialchars($e['ref'] ?? '') ?>"><?= htmlspecialchars(refDomain($e['ref'] ?? '')) ?></td>
          <td><?= $ms > 0 ? round($ms / 1000, 1) . 's' : '—' ?></td>
          <td><?= htmlspecialchars($e['lang'] ?? '') ?></td>
        </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      </div>
    </div>

  </div>
  <?php endif ?>

</div><!-- /an-wrap -->
</main>

<script>
// ── Shared config ────────────────────────────────────────
const PALETTE  = ['#1b6799','#F0A030','#2a9d8f','#e76f51','#8338ec','#3a86ff','#06d6a0','#ef476f'];
const FONT     = "'Segoe UI', system-ui, sans-serif";
Chart.defaults.font.family = FONT;
Chart.defaults.color       = '#6b849a';

function donut(id, labels, data) {
    const total = data.reduce((a,b) => a+b, 0);
    new Chart(document.getElementById(id), {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data,
                backgroundColor: PALETTE.slice(0, labels.length),
                borderWidth: 2,
                borderColor: '#fff',
                hoverOffset: 10,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '66%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { font:{size:12}, padding:10, boxWidth:11, boxHeight:11, usePointStyle:true }
                },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const pct = total > 0 ? Math.round(ctx.parsed / total * 100) : 0;
                            return `  ${ctx.label}: ${ctx.parsed} (${pct}%)`;
                        }
                    }
                }
            }
        }
    });
}

// ── Line / area chart ────────────────────────────────────
(function() {
    const labels = <?= json_encode($chartLabels) ?>;
    const data   = <?= json_encode($chartData) ?>;
    const ctx    = document.getElementById('lineChart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: 'Page Views',
                data,
                fill: true,
                backgroundColor: function(ctx) {
                    const canvas = ctx.chart.ctx;
                    const grad = canvas.createLinearGradient(0, 0, 0, 240);
                    grad.addColorStop(0, 'rgba(27,103,153,0.25)');
                    grad.addColorStop(1, 'rgba(27,103,153,0)');
                    return grad;
                },
                borderColor: '#1b6799',
                borderWidth: 2.5,
                tension: 0.4,
                pointRadius: data.length > 20 ? 2 : 4,
                pointBackgroundColor: '#1b6799',
                pointBorderColor: '#fff',
                pointBorderWidth: 1.5,
                pointHoverRadius: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#0c1e2d',
                    padding: 10,
                    titleFont: { size: 12, weight: '700' },
                    bodyFont:  { size: 13 },
                    callbacks: {
                        label: ctx => `  ${ctx.parsed.y} view${ctx.parsed.y !== 1 ? 's' : ''}`
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 11 }, maxTicksLimit: 10 }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,0.04)' },
                    ticks: { font: { size: 11 }, precision: 0 }
                }
            }
        }
    });
})();

// ── Doughnut charts ──────────────────────────────────────
donut('deviceChart',  <?= json_encode(array_keys($deviceCts)) ?>,  <?= json_encode(array_values($deviceCts)) ?>);
donut('browserChart', <?= json_encode(array_keys($browserCts)) ?>, <?= json_encode(array_values($browserCts)) ?>);
donut('osChart',      <?= json_encode(array_keys($osCts)) ?>,      <?= json_encode(array_values($osCts)) ?>);
donut('refChart',     <?= json_encode(array_keys($topRef)) ?>,     <?= json_encode(array_values($topRef)) ?>);
</script>
</body>
</html>
