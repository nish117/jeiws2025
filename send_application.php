<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require './lib/PHPMailer/src/Exception.php';
require './lib/PHPMailer/src/PHPMailer.php';
require './lib/PHPMailer/src/SMTP.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit;
}

define('JEIWS_CONFIG', 1);
$cfg = require __DIR__ . '/config/mail.php';

$logDir = __DIR__ . '/data/log';
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }

function detectCvMimeType(string $path): string {
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($path);
        if ($mime) return $mime;
    }
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);
            if ($mime) return $mime;
        }
    }
    // Neither fileinfo function is available on this server — sniff magic bytes instead
    $handle = @fopen($path, 'rb');
    if (!$handle) return '';
    $bytes = fread($handle, 8);
    fclose($handle);
    if (substr($bytes, 0, 4) === '%PDF') return 'application/pdf';
    if (substr($bytes, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") return 'application/msword';
    if (substr($bytes, 0, 4) === "PK\x03\x04") return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    return '';
}

$mail = new PHPMailer(true);
try {
    $name       = htmlspecialchars(trim($_POST["name"]     ?? ''));
    $email      = filter_var(trim($_POST["email"]           ?? ''), FILTER_SANITIZE_EMAIL);
    $phone      = htmlspecialchars(trim($_POST["phone"]     ?? ''));
    $position   = htmlspecialchars(trim($_POST["position"]  ?? 'General Application'));
    $experience = htmlspecialchars(trim($_POST["experience"]?? 'Not specified'));
    $education  = htmlspecialchars(trim($_POST["education"] ?? 'Not specified'));
    $message    = htmlspecialchars(trim($_POST["message"]   ?? ''));

    if (empty($name) || empty($email) || empty($phone) || empty($message) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $logFile = $logDir . '/mail_errors.log';
        $entry   = '[' . date('Y-m-d H:i:s') . '] '
                 . 'Application validation failed | '
                 . 'name=' . ($name !== '' ? 'ok' : 'empty') . ' '
                 . 'email=' . ($email !== '' ? 'ok' : 'empty') . ' '
                 . 'phone=' . ($phone !== '' ? 'ok' : 'empty') . ' '
                 . 'message=' . ($message !== '' ? 'ok' : 'empty') . ' '
                 . '| Content-Length: ' . ($_SERVER['CONTENT_LENGTH'] ?? 'n/a')
                 . ' | POST count: ' . count($_POST)
                 . ' | FILES count: ' . count($_FILES) . PHP_EOL;
        file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
        echo "error";
        exit;
    }

    $mail->isSMTP();
    $mail->Host       = $cfg['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $cfg['username'];
    $mail->Password   = $cfg['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $cfg['port'];

    $cvAttached = false;
    if (isset($_FILES['cv']) && $_FILES['cv']['error'] === UPLOAD_ERR_OK) {
        $allowedMimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        $fileMime = detectCvMimeType($_FILES['cv']['tmp_name']);
        if (in_array($fileMime, $allowedMimes) && $_FILES['cv']['size'] <= 5 * 1024 * 1024) {
            // Sanitize filename: strip path components, keep only safe characters
            $origName   = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES['cv']['name']));
            $safeName   = substr($origName, 0, 100);
            $mail->addAttachment($_FILES['cv']['tmp_name'], $safeName);
            $cvAttached = true;
        }
    }

    $mail->setFrom($cfg['from'], $cfg['fromName']);
    $mail->addAddress($cfg['to']);
    $mail->addReplyTo($email, $name);

    $cvStatus = $cvAttached ? '&#9989; Attached' : '&#10060; Not provided';

    $mail->isHTML(true);
    $mail->Subject = "Job Application: $position — JEIWS Website";
    $mail->Body    = "
<div style='font-family:Arial,sans-serif;max-width:620px;margin:0 auto;'>
  <div style='background:#1B6799;padding:24px 32px;border-radius:12px 12px 0 0;'>
    <h2 style='color:#fff;margin:0;font-size:1.25rem;'>New Job Application</h2>
    <p style='color:rgba(255,255,255,0.65);margin:6px 0 0;font-size:13px;'>Submitted via JEIWS Website Careers Page</p>
  </div>
  <div style='background:#fff;padding:28px 32px;border:1px solid #e6eff6;border-top:none;border-radius:0 0 12px 12px;'>
    <table style='width:100%;border-collapse:collapse;'>
      <tr>
        <td style='padding:10px 0;border-bottom:1px solid #f0f4f8;color:#6b849a;font-size:13px;width:150px;font-weight:600;'>Position Applied</td>
        <td style='padding:10px 0;border-bottom:1px solid #f0f4f8;color:#0c1e2d;font-size:14px;font-weight:700;'>$position</td>
      </tr>
      <tr>
        <td style='padding:10px 0;border-bottom:1px solid #f0f4f8;color:#6b849a;font-size:13px;'>Full Name</td>
        <td style='padding:10px 0;border-bottom:1px solid #f0f4f8;color:#0c1e2d;font-size:14px;'>$name</td>
      </tr>
      <tr>
        <td style='padding:10px 0;border-bottom:1px solid #f0f4f8;color:#6b849a;font-size:13px;'>Email</td>
        <td style='padding:10px 0;border-bottom:1px solid #f0f4f8;font-size:14px;'><a href='mailto:$email' style='color:#1B6799;'>$email</a></td>
      </tr>
      <tr>
        <td style='padding:10px 0;border-bottom:1px solid #f0f4f8;color:#6b849a;font-size:13px;'>Phone</td>
        <td style='padding:10px 0;border-bottom:1px solid #f0f4f8;color:#0c1e2d;font-size:14px;'>$phone</td>
      </tr>
      <tr>
        <td style='padding:10px 0;border-bottom:1px solid #f0f4f8;color:#6b849a;font-size:13px;'>Experience</td>
        <td style='padding:10px 0;border-bottom:1px solid #f0f4f8;color:#0c1e2d;font-size:14px;'>$experience</td>
      </tr>
      <tr>
        <td style='padding:10px 0;border-bottom:1px solid #f0f4f8;color:#6b849a;font-size:13px;'>Education</td>
        <td style='padding:10px 0;border-bottom:1px solid #f0f4f8;color:#0c1e2d;font-size:14px;'>$education</td>
      </tr>
      <tr>
        <td style='padding:10px 0;color:#6b849a;font-size:13px;'>CV / Resume</td>
        <td style='padding:10px 0;color:#0c1e2d;font-size:14px;'>$cvStatus</td>
      </tr>
    </table>
    <div style='margin-top:22px;'>
      <p style='color:#6b849a;font-size:12px;margin-bottom:8px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;'>Cover Letter / Message</p>
      <div style='background:#f2f7fb;border-radius:8px;padding:16px;color:#2c4358;font-size:14px;line-height:1.75;white-space:pre-wrap;'>$message</div>
    </div>
  </div>
</div>";

    $mail->AltBody = "New Job Application — $position\n\nName: $name\nEmail: $email\nPhone: $phone\nExperience: $experience\nEducation: $education\nCV Attached: " . ($cvAttached ? 'Yes' : 'No') . "\n\nMessage:\n$message";

    echo $mail->send() ? "success" : "error";

} catch (Exception $e) {
    $logFile = $logDir . '/mail_errors.log';
    $entry   = '[' . date('Y-m-d H:i:s') . '] '
             . 'Application from: ' . ($name ?? '?') . ' <' . ($email ?? '?') . '> | '
             . 'Error: ' . $mail->ErrorInfo . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    echo "error1";
} catch (Throwable $e) {
    $logFile = $logDir . '/mail_errors.log';
    $entry   = '[' . date('Y-m-d H:i:s') . '] '
             . 'Application fatal error: ' . $e->getMessage()
             . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    echo "error2";
}
?>
