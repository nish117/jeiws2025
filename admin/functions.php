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
    // Only publish projects that are not drafts
    $published = array_values(array_filter($projects, fn($p) => empty($p['is_draft'])));
    $out  = "export const projects = [\n";
    $last = count($published) - 1;
    foreach ($published as $i => $p) {
        $allGallery  = $p['gallery'] ?? [];
        $unpubImages = $p['unpublished_images'] ?? [];
        $gallery     = array_values(array_filter($allGallery, fn($img) => !in_array($img, $unpubImages, true)));
        $lastG       = count($gallery) - 1;
        $trailingP = $i < $last ? ',' : '';

        $out .= "    {\n";
        $out .= "        id: " . intval($p['id']) . ",\n";
        // JSON_HEX_TAG prevents </script> injection when values land inside a <script> block
        $out .= "        title: "       . json_encode((string)($p['title']       ?? ''), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) . ",\n";
        $out .= "        description: " . json_encode((string)($p['description'] ?? ''), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) . ",\n";
        $out .= "        image: "       . json_encode((string)($p['image']       ?? ''), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) . ",\n";
        $out .= "        gallery: [\n";
        foreach ($gallery as $j => $img) {
            $trailingG = $j < $lastG ? ',' : '';
            $out .= "            " . json_encode((string)$img, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) . "$trailingG\n";
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

// Validate and return an 8-char hex project ID, or '' if invalid
function parseId(string $raw): string {
    $s = trim($raw);
    return preg_match('/^[a-f0-9]{8}$/', $s) ? $s : '';
}

// Generate a unique random 8-char hex ID not already in use
function generateId(array $projects): string {
    $existing = array_column($projects, 'id');
    do {
        $id = bin2hex(random_bytes(4));
    } while (in_array($id, $existing, true));
    return $id;
}

function findProject(array $projects, string $id): int {
    foreach ($projects as $i => $p) {
        if ((string)$p['id'] === $id) return $i;
    }
    return -1;
}

// Validate that a photo path is safely within IMG_BASE and exists on disk
function validatePhotoPath(string $path): bool {
    $real    = realpath(__DIR__ . '/../' . $path);
    $baseDir = realpath(IMG_BASE);
    return $real !== false
        && $baseDir !== false
        && strncmp($real, $baseDir, strlen($baseDir)) === 0
        && is_file($real);
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

// ── Image processing ────────────────────────────────────
// Resizes the image to at most MAX_IMG_DIM on its longest side (never upscales),
// auto-corrects JPEG EXIF orientation, and re-encodes at quality 88.
// Returns true on success, false if GD is unavailable or the source is unreadable.
define('MAX_IMG_DIM', 2400);

function processImage(string $tmpPath, string $dest, string $mime): bool {
    if (!extension_loaded('gd')) {
        return copy($tmpPath, $dest);
    }

    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($tmpPath),
        'image/png'  => @imagecreatefrompng($tmpPath),
        'image/webp' => @imagecreatefromwebp($tmpPath),
        default      => false,
    };
    if (!$src) return false;

    // Auto-correct JPEG rotation from EXIF orientation tag
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif        = @exif_read_data($tmpPath);
        $orientation = $exif['Orientation'] ?? 1;
        $rotated = match ((int) $orientation) {
            3 => imagerotate($src, 180, 0),
            6 => imagerotate($src, -90, 0),
            8 => imagerotate($src,  90, 0),
            default => null,
        };
        if ($rotated) { imagedestroy($src); $src = $rotated; }
    }

    $origW = imagesx($src);
    $origH = imagesy($src);

    // Scale down only when the image exceeds the max dimension
    if ($origW > MAX_IMG_DIM || $origH > MAX_IMG_DIM) {
        $scale = min(MAX_IMG_DIM / $origW, MAX_IMG_DIM / $origH);
        $newW  = (int) round($origW * $scale);
        $newH  = (int) round($origH * $scale);
    } else {
        $newW = $origW;
        $newH = $origH;
    }

    $dst = imagecreatetruecolor($newW, $newH);

    // Preserve transparency for PNG and WebP
    if ($mime === 'image/png' || $mime === 'image/webp') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
    imagedestroy($src);

    // quality 88 = visually lossless with good compression for JPEG/WebP;
    // PNG uses lossless compression level 6 (balanced speed vs file size)
    $ok = match ($mime) {
        'image/jpeg' => imagejpeg($dst, $dest, 88),
        'image/png'  => imagepng($dst, $dest, 6),
        'image/webp' => imagewebp($dst, $dest, 88),
        default      => false,
    };

    imagedestroy($dst);
    return $ok;
}

// ── CSRF ─────────────────────────────────────────────────
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']); exit;
    }
}
