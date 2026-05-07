<?php
defined('CMS_LOADED') or die('Direct access denied.');

define('DATA_FILE',   __DIR__ . '/../data/projects.json');
define('PROJECTS_JS', __DIR__ . '/../src/js/projects.js');
define('IMG_BASE',    __DIR__ . '/../assets/project-images');
define('IMG_URL',     'assets/project-images');

// ── Load ────────────────────────────────────────────────
function loadProjects(): array {
    if (!file_exists(DATA_FILE)) {
        initFromJs();
    }
    if (!file_exists(DATA_FILE)) return [];
    $data = json_decode(file_get_contents(DATA_FILE), true);
    return is_array($data) ? $data : [];
}

// ── Save + regenerate projects.js ───────────────────────
function saveProjects(array $projects): void {
    $projects = array_values($projects);
    file_put_contents(
        DATA_FILE,
        json_encode($projects, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    regenerateJs($projects);
}

function regenerateJs(array $projects): void {
    $out = "export const projects = [\n";
    $last = count($projects) - 1;
    foreach ($projects as $i => $p) {
        $gallery   = $p['gallery'] ?? [];
        $lastG     = count($gallery) - 1;
        $trailingP = $i < $last ? ',' : '';

        $out .= "    {\n";
        $out .= "        id: " . intval($p['id']) . ",\n";
        $out .= "        title: "       . json_encode((string)($p['title']       ?? ''), JSON_UNESCAPED_UNICODE) . ",\n";
        $out .= "        description: " . json_encode((string)($p['description'] ?? ''), JSON_UNESCAPED_UNICODE) . ",\n";
        $out .= "        image: "       . json_encode((string)($p['image']       ?? ''), JSON_UNESCAPED_SLASHES) . ",\n";
        $out .= "        gallery: [\n";
        foreach ($gallery as $j => $img) {
            $trailingG = $j < $lastG ? ',' : '';
            $out .= "            " . json_encode((string)$img, JSON_UNESCAPED_SLASHES) . "$trailingG\n";
        }
        $out .= "        ]\n";
        $out .= "    }$trailingP\n";
    }
    $out .= "];\n";
    file_put_contents(PROJECTS_JS, $out);
}

// ── Bootstrap from existing projects.js (runs once) ─────
function initFromJs(): void {
    if (!file_exists(PROJECTS_JS)) return;
    $js = file_get_contents(PROJECTS_JS);

    // Strip: export const projects =
    $js = preg_replace('/export\s+const\s+\w+\s*=\s*/', '', $js);
    $js = trim($js, " \t\n\r\0\x0B;");
    // Remove single-line comments
    $js = preg_replace('/\/\/[^\n]*/', '', $js);
    // Quote bare keys (only at line-start indentation)
    $js = preg_replace('/^(\s*)(id|title|description|image|gallery)\s*:/m', '$1"$2":', $js);
    // Remove trailing commas before ] or }
    $js = preg_replace('/,(\s*[\]\}])/s', '$1', $js);

    $data = json_decode($js, true);
    if (is_array($data)) {
        $dir = dirname(DATA_FILE);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents(
            DATA_FILE,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }
}

// ── Helpers ─────────────────────────────────────────────
function nextId(array $projects): int {
    if (empty($projects)) return 1;
    return max(array_column($projects, 'id')) + 1;
}

function findProject(array $projects, int $id): int {
    foreach ($projects as $i => $p) {
        if ((int)$p['id'] === $id) return $i;
    }
    return -1;
}

function safePath(string $path): string {
    return str_replace(['..', '\\', "\0"], '', $path);
}

function deleteDir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
        $p = "$dir/$f";
        is_dir($p) ? deleteDir($p) : unlink($p);
    }
    rmdir($dir);
}
