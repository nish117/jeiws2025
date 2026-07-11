<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require './lib/PHPMailer/src/Exception.php';
require './lib/PHPMailer/src/PHPMailer.php';
require './lib/PHPMailer/src/SMTP.php';

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

define('JEIWS_CONFIG', 1);
$cfg = require __DIR__ . '/config/mail.php';

$logDir = __DIR__ . '/data/log';
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }

$name    = htmlspecialchars(trim($_POST["name"]    ?? ''));
$email   = filter_var(trim($_POST["email"] ?? ''), FILTER_SANITIZE_EMAIL);
$phone   = htmlspecialchars(trim($_POST["phone"]   ?? ''));
$message = htmlspecialchars(trim($_POST["message"] ?? ''));

if (empty($name) || empty($email) || empty($message) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid fields']);
    exit;
}

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = $cfg['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $cfg['username'];
    $mail->Password   = $cfg['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $cfg['port'];

    $mail->setFrom($cfg['from'], $cfg['fromName']);
    $mail->addAddress($cfg['to']);
    $mail->addReplyTo($email, $name);

    $date = date('F j, Y  g:i A');

    $mail->isHTML(true);
    $mail->Subject = "New Inquiry from $name — JEIWS Website";
    $mail->Body    = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:'Segoe UI',Arial,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4f8;padding:40px 16px;">
  <tr><td align="center">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width:580px;">

      <!-- Header -->
      <tr>
        <td style="background:#0C1C2A;border-radius:12px 12px 0 0;padding:36px 40px;text-align:center;">
          <div style="font-size:22px;font-weight:800;color:#ffffff;letter-spacing:-0.5px;">
            JE Infrastructure &amp; Waterproofing Services
          </div>
          <div style="margin-top:6px;font-size:13px;color:rgba(255,255,255,0.45);letter-spacing:1.5px;text-transform:uppercase;">
            New Website Inquiry
          </div>
        </td>
      </tr>

      <!-- Blue accent bar -->
      <tr>
        <td style="background:linear-gradient(90deg,#1B6799,#2B82BC);height:4px;"></td>
      </tr>

      <!-- Body -->
      <tr>
        <td style="background:#ffffff;padding:40px 40px 32px;">

          <p style="margin:0 0 28px;font-size:15px;color:#4a5568;line-height:1.6;">
            You have received a new message through the contact form on <strong style="color:#1B6799;">jeiws.com</strong>.
          </p>

          <!-- Fields -->
          <table width="100%" cellpadding="0" cellspacing="0">

            <tr>
              <td style="padding:0 0 16px;">
                <div style="font-size:11px;font-weight:700;color:#1B6799;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:6px;">Full Name</div>
                <div style="background:#f7fafc;border-left:3px solid #1B6799;border-radius:0 6px 6px 0;padding:12px 16px;font-size:15px;color:#1a2b3c;font-weight:600;">$name</div>
              </td>
            </tr>

            <tr>
              <td style="padding:0 0 16px;">
                <div style="font-size:11px;font-weight:700;color:#1B6799;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:6px;">Email Address</div>
                <div style="background:#f7fafc;border-left:3px solid #1B6799;border-radius:0 6px 6px 0;padding:12px 16px;font-size:15px;color:#1a2b3c;">
                  <a href="mailto:$email" style="color:#1B6799;text-decoration:none;">$email</a>
                </div>
              </td>
            </tr>

            <tr>
              <td style="padding:0 0 16px;">
                <div style="font-size:11px;font-weight:700;color:#1B6799;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:6px;">Phone Number</div>
                <div style="background:#f7fafc;border-left:3px solid #1B6799;border-radius:0 6px 6px 0;padding:12px 16px;font-size:15px;color:#1a2b3c;">
                  <a href="tel:$phone" style="color:#1a2b3c;text-decoration:none;">$phone</a>
                </div>
              </td>
            </tr>

            <tr>
              <td style="padding:0 0 8px;">
                <div style="font-size:11px;font-weight:700;color:#1B6799;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:6px;">Message</div>
                <div style="background:#f7fafc;border-left:3px solid #1B6799;border-radius:0 6px 6px 0;padding:16px;font-size:15px;color:#1a2b3c;line-height:1.7;white-space:pre-wrap;">$message</div>
              </td>
            </tr>

          </table>

          <!-- Reply CTA -->
          <div style="margin-top:32px;text-align:center;">
            <a href="mailto:$email?subject=Re: Your inquiry to JEIWS"
               style="display:inline-block;background:#1B6799;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:14px 32px;border-radius:100px;letter-spacing:0.3px;">
              Reply to $name
            </a>
          </div>

        </td>
      </tr>

      <!-- Footer -->
      <tr>
        <td style="background:#f7fafc;border-radius:0 0 12px 12px;padding:24px 40px;text-align:center;border-top:1px solid #e2e8f0;">
          <p style="margin:0 0 4px;font-size:12px;color:#a0aec0;">Received on $date</p>
          <p style="margin:0;font-size:12px;color:#a0aec0;">
            J.E. Infrastructure Waterproofing &amp; Services &mdash; Sanepa, Lalitpur, Nepal
          </p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>

</body>
</html>
HTML;
    $mail->AltBody = "New inquiry from $name\n\nEmail: $email\nPhone: $phone\n\nMessage:\n$message\n\nReceived: $date";

    $mail->send();
    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    $logFile = $logDir . '/mail_errors.log';
    $entry   = '[' . date('Y-m-d H:i:s') . '] '
             . 'From: ' . $name . ' <' . $email . '> | '
             . 'Error: ' . $mail->ErrorInfo . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    echo json_encode(['ok' => false, 'error' => $mail->ErrorInfo]);
}
?>
