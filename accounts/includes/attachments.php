<?php
defined('ACC_LOADED') or die('Direct access denied.');

/**
 * File attachments (invoice photos and PDFs).
 *
 * Files live in data/attachments/ — that folder is denied to the web by
 * data/.htaccess — under random names, and are only ever served through
 * attachment.php after a login check. Images are decoded and re-encoded
 * with GD, which normalises orientation, caps the size and drops anything
 * that isn't pixel data (EXIF/GPS, embedded payloads). PDFs are kept as
 * uploaded after a type check.
 */

const ATTACH_MAX_BYTES = 10 * 1024 * 1024;
const ATTACH_MAX_EDGE  = 2400;   // px, longest side after re-encoding
const ATTACH_TYPES = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'application/pdf' => 'pdf',
];

function attachment_dir(): string {
    $dir = ACC_ROOT . '/../data/attachments';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir;
}

/** True when GD can decode and re-encode the uploaded formats. */
function gd_available(): bool {
    return function_exists('imagecreatefromjpeg') && function_exists('imagecreatefrompng') && function_exists('imagejpeg') && function_exists('imagecopyresampled');
}

/**
 * File type from the file's content, never its name. Uses fileinfo when the
 * host has it; otherwise falls back to the image header (getimagesize, core
 * PHP) and the PDF signature.
 */
function detect_mime(string $path): string {
    if (class_exists('finfo')) {
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($mime !== '') return $mime;
    }
    $head = (string)@file_get_contents($path, false, null, 0, 16);
    if (str_starts_with($head, '%PDF-')) return 'application/pdf';
    if (preg_match('/^.{4}ftyp(heic|heix|hevc|mif1|msf1)/s', $head)) return 'image/heic';
    $info = @getimagesize($path);
    return $info['mime'] ?? 'application/octet-stream';
}

/** Without GD: confirm the file decodes as an image header and isn't absurdly large. */
function image_sanity_check(string $path): bool|string {
    $info = @getimagesize($path);
    if (!$info || $info[0] < 1 || $info[1] < 1) return "couldn't read this image.";
    if ($info[0] * $info[1] / 1_000_000 > ATTACH_MAX_MEGAPIXELS) return 'image is too large in pixels. Please resize it.';
    return true;
}

/** Normalises $_FILES['x'] (single or multiple) into a list of file arrays. */
function uploaded_file_list(?array $field): array {
    if (!$field || !isset($field['name'])) return [];
    if (!is_array($field['name'])) return [$field];
    $files = [];
    foreach ($field['name'] as $i => $name) {
        $files[] = ['name' => $name, 'type' => $field['type'][$i], 'tmp_name' => $field['tmp_name'][$i], 'error' => $field['error'][$i], 'size' => $field['size'][$i]];
    }
    return $files;
}

/**
 * Validates and stores uploaded files against an entity.
 * @return array{saved: int, errors: string[]}
 */
function store_attachments(?array $field, string $entity, int $entityId, int $userId): array {
    $saved = 0;
    $errors = [];
    foreach (uploaded_file_list($field) as $file) {
        if ($file['error'] === UPLOAD_ERR_NO_FILE) continue;
        $name = mb_substr(basename((string)$file['name']), 0, 200) ?: 'file';
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) { $errors[] = "{$name}: too large for the server's upload limit."; continue; }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) { $errors[] = "{$name}: upload failed, please try again."; continue; }
        if ($file['size'] > ATTACH_MAX_BYTES) { $errors[] = "{$name}: larger than 10 MB."; continue; }

        $mime = detect_mime($file['tmp_name']);
        if (in_array($mime, ['image/heic', 'image/heif'], true)) { $errors[] = "{$name}: iPhone HEIC photos can't be read — set the iPhone camera to 'Most Compatible', or upload a screenshot of the bill."; continue; }
        if (!isset(ATTACH_TYPES[$mime])) { $errors[] = "{$name}: only JPG, PNG, WEBP photos or PDF files can be attached."; continue; }

        $dir = attachment_dir();
        if (!is_dir($dir) || !is_writable($dir)) {
            error_log("[accounts] attachment folder not writable: {$dir}");
            $errors[] = "{$name}: the server's file folder (data/attachments) is missing or not writable. Ask whoever manages the hosting to make it writable.";
            continue;
        }
        $stored = bin2hex(random_bytes(16));
        $dest = $dir . '/' . $stored;
        if ($mime === 'application/pdf') {
            $ok = move_uploaded_file($file['tmp_name'], $dest .= '.pdf');
            $finalMime = $mime;
        } elseif (!gd_available()) {
            // No GD on this host: keep the (already browser-shrunk) image as uploaded, after
            // confirming it really is an image of a sane size.
            $check = image_sanity_check($file['tmp_name']);
            if ($check !== true) { $errors[] = "{$name}: {$check}"; continue; }
            $ok = move_uploaded_file($file['tmp_name'], $dest .= '.' . ATTACH_TYPES[$mime]);
            $finalMime = $mime;
        } else {
            $result = reencode_image($file['tmp_name'], $mime, $dest .= '.jpg');
            $finalMime = 'image/jpeg';
            if ($result !== true) { if (is_file($dest)) unlink($dest); $errors[] = "{$name}: {$result}"; continue; }
            $ok = true;
        }
        if (!$ok) { $errors[] = "{$name}: couldn't be saved."; continue; }

        db()->prepare('INSERT INTO acc_attachments (entity, entity_id, original_name, stored_name, mime_type, size_bytes, uploaded_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([$entity, $entityId, $name, basename($dest), $finalMime, filesize($dest), $userId]);
        $saved++;
    }
    return ['saved' => $saved, 'errors' => $errors];
}

const ATTACH_MAX_MEGAPIXELS = 120;   // ≈ 12000×10000 — beyond any phone camera; guards against decompression bombs

/**
 * Decodes, downsizes, fixes orientation and saves an image as JPEG.
 * Returns true, or a message explaining why the image can't be used.
 *
 * A decoded image needs about 4–5 bytes per pixel regardless of file size:
 * a 0.4 MB, 24-megapixel JPEG needs ~100 MB once opened, and today's 48–50 MP
 * phone photos ~250 MB. The size is checked first and memory raised just for
 * this request, so a large photo is handled — or refused with a clear
 * message — instead of crashing the page.
 */
function reencode_image(string $src, string $mime, string $dest): bool|string {
    $info = @getimagesize($src);
    if (!$info || $info[0] < 1 || $info[1] < 1) return "couldn't read this image.";
    [$w, $h] = $info;
    $megapixels = $w * $h / 1_000_000;
    if ($megapixels > ATTACH_MAX_MEGAPIXELS) return sprintf('image is %.0f megapixels — too large. Please resize it below %d MP.', $megapixels, ATTACH_MAX_MEGAPIXELS);

    $scale = min(1, ATTACH_MAX_EDGE / max($w, $h));
    [$ow, $oh] = [max(1, (int)round($w * $scale)), max(1, (int)round($h * $scale))];
    $needed = (int)($w * $h * 5.2 + $ow * $oh * 5.2 * 2) + 24 * 1048576;   // source + output + rotation copy + headroom
    if (!ensure_memory_for($needed)) {
        return sprintf("image is %.0f megapixels and the server doesn't have enough memory to process it. Please resize the photo (or let the browser shrink it) and try again.", $megapixels);
    }

    $img = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($src),
        'image/png'  => @imagecreatefrompng($src),
        'image/webp' => @imagecreatefromwebp($src),
        default      => false,
    };
    if (!$img) return "couldn't read this image.";

    // Shrink first, then work on the small copy only.
    // Flatten onto white so transparent PNG receipts don't turn black as JPEG.
    $out = imagecreatetruecolor($ow, $oh);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $img, 0, 0, 0, 0, $ow, $oh, $w, $h);
    imagedestroy($img);

    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $orientation = (int)(@exif_read_data($src)['Orientation'] ?? 1);
        $rotated = match ($orientation) { 3 => imagerotate($out, 180, 0), 6 => imagerotate($out, -90, 0), 8 => imagerotate($out, 90, 0), default => null };
        if ($rotated) { imagedestroy($out); $out = $rotated; }
    }
    $ok = imagejpeg($out, $dest, 85);
    imagedestroy($out);
    return $ok ?: "couldn't save the image.";
}

/** Raises this request's memory limit if $bytes more are needed (up to 1 GB). False if the host won't allow it. */
function ensure_memory_for(int $bytes): bool {
    $toBytes = function (string $v): int {
        $v = trim($v);
        if ($v === '-1') return PHP_INT_MAX;
        $n = (int)$v;
        return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
    };
    $limit = $toBytes((string)ini_get('memory_limit'));
    $want = memory_get_usage() + $bytes;
    if ($want <= $limit) return true;
    if ($want > 1 << 30) return false;
    @ini_set('memory_limit', (string)(int)ceil($want / 1048576) . 'M');
    return $toBytes((string)ini_get('memory_limit')) >= $want;
}
function list_attachments(string $entity, int $entityId): array {
    $stmt = db()->prepare('SELECT * FROM acc_attachments WHERE entity = ? AND entity_id = ? ORDER BY id');
    $stmt->execute([$entity, $entityId]);
    return $stmt->fetchAll();
}

function delete_attachment(array $att): void {
    db()->prepare('DELETE FROM acc_attachments WHERE id = ?')->execute([$att['id']]);
    $path = attachment_dir() . '/' . basename($att['stored_name']);
    if (is_file($path)) unlink($path);
}

function delete_entity_attachments(string $entity, int $entityId): void {
    foreach (list_attachments($entity, $entityId) as $att) delete_attachment($att);
}
