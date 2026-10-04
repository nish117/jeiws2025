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
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    return $dir;
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

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(ATTACH_TYPES[$mime])) { $errors[] = "{$name}: only JPG, PNG, WEBP photos or PDF files can be attached."; continue; }

        $stored = bin2hex(random_bytes(16));
        $dest = attachment_dir() . '/' . $stored;
        if ($mime === 'application/pdf') {
            $ok = move_uploaded_file($file['tmp_name'], $dest .= '.pdf');
            $finalMime = $mime;
        } else {
            $ok = reencode_image($file['tmp_name'], $mime, $dest .= '.jpg');
            $finalMime = 'image/jpeg';
            if (!$ok) { $errors[] = "{$name}: couldn't read this image."; continue; }
        }
        if (!$ok) { $errors[] = "{$name}: couldn't be saved."; continue; }

        db()->prepare('INSERT INTO acc_attachments (entity, entity_id, original_name, stored_name, mime_type, size_bytes, uploaded_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([$entity, $entityId, $name, basename($dest), $finalMime, filesize($dest), $userId]);
        $saved++;
    }
    return ['saved' => $saved, 'errors' => $errors];
}

/** Decodes, fixes orientation, downsizes and saves an image as JPEG. */
function reencode_image(string $src, string $mime, string $dest): bool {
    $img = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($src),
        'image/png'  => @imagecreatefrompng($src),
        'image/webp' => @imagecreatefromwebp($src),
        default      => false,
    };
    if (!$img) return false;

    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $orientation = (int)(@exif_read_data($src)['Orientation'] ?? 1);
        $rotated = match ($orientation) { 3 => imagerotate($img, 180, 0), 6 => imagerotate($img, -90, 0), 8 => imagerotate($img, 90, 0), default => null };
        if ($rotated) { imagedestroy($img); $img = $rotated; }
    }

    [$w, $h] = [imagesx($img), imagesy($img)];
    $scale = min(1, ATTACH_MAX_EDGE / max($w, $h));
    // Flatten onto white so transparent PNG receipts don't turn black as JPEG.
    $out = imagecreatetruecolor(max(1, (int)round($w * $scale)), max(1, (int)round($h * $scale)));
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
    $ok = imagejpeg($out, $dest, 85);
    imagedestroy($img);
    imagedestroy($out);
    return $ok;
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
