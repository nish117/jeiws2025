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
        $out .= "        id: " . json_encode((string)($p['id'] ?? ''), JSON_HEX_TAG) . ",\n";
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

// Validate and return a project ID (8-char hex, or 8-char hex + underscore suffix), or '' if invalid
function parseId(string $raw): string {
    $s = trim($raw);
    return preg_match('/^[a-f0-9]{8}(_[a-zA-Z0-9]+)?$/', $s) ? $s : '';
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
// auto-corrects JPEG EXIF orientation, stamps the company logo watermark,
// and re-encodes at quality 88.
// Returns true on success, false if GD is unavailable or the source is unreadable.
define('MAX_IMG_DIM',        2400);
define('WATERMARK_LOGO',     __DIR__ . '/../assets/logo.png');
define('WATERMARK_OPACITY',  55);   // 0 = invisible, 100 = solid
define('WATERMARK_MAX_W',    180);  // max logo width in pixels
define('WATERMARK_SCALE',    0.12); // logo width as fraction of image width

function applyWatermark(GdImage &$canvas, int $imgW, int $imgH): void {
    if (!file_exists(WATERMARK_LOGO)) return;

    $logo = @imagecreatefrompng(WATERMARK_LOGO);
    if (!$logo) return;

    $logoW = imagesx($logo);
    $logoH = imagesy($logo);
    if ($logoW <= 0 || $logoH <= 0) { imagedestroy($logo); return; }

    // Scale: WATERMARK_SCALE% of image width, clamped to WATERMARK_MAX_W
    $wmW = (int) max(40, min(WATERMARK_MAX_W, round($imgW * WATERMARK_SCALE)));
    $wmH = (int) round($logoH * ($wmW / $logoW));

    // Resize logo into a true-colour canvas with alpha channel
    $scaled = imagecreatetruecolor($wmW, $wmH);
    imagealphablending($scaled, false);
    imagesavealpha($scaled, true);
    imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled, 0, 0, 0, 127));
    imagecopyresampled($scaled, $logo, 0, 0, 0, 0, $wmW, $wmH, $logoW, $logoH);
    imagedestroy($logo);

    // Adjust every pixel's alpha to apply the desired opacity.
    // GD alpha: 0 = fully opaque, 127 = fully transparent.
    $opacity = max(0, min(100, WATERMARK_OPACITY));
    for ($px = 0; $px < $wmW; $px++) {
        for ($py = 0; $py < $wmH; $py++) {
            $c       = imagecolorat($scaled, $px, $py);
            $srcA    = ($c >> 24) & 0x7F;
            // Pixels fully transparent in the logo stay fully transparent.
            // Otherwise scale their opacity down to WATERMARK_OPACITY %.
            $newA    = 127 - (int) round((127 - $srcA) * $opacity / 100);
            imagesetpixel($scaled, $px, $py, imagecolorallocatealpha(
                $scaled,
                ($c >> 16) & 0xFF,
                ($c >>  8) & 0xFF,
                $c         & 0xFF,
                $newA
            ));
        }
    }

    // Place in bottom-right corner with padding proportional to image width
    $pad = (int) max(12, round($imgW * 0.015));
    $x   = $imgW - $wmW - $pad;
    $y   = $imgH - $wmH - $pad;

    // Enable alpha blending on the canvas so the semi-transparent logo composites correctly
    imagealphablending($canvas, true);
    imagecopy($canvas, $scaled, $x, $y, 0, 0, $wmW, $wmH);
    imagedestroy($scaled);
}

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

    // Stamp company logo watermark (bottom-right, semi-transparent)
    applyWatermark($dst, $newW, $newH);

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
