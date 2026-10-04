<?php
defined('ACC_LOADED') or die('Direct access denied.');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require_once ACC_ROOT . '/../lib/PHPMailer/src/Exception.php';
require_once ACC_ROOT . '/../lib/PHPMailer/src/PHPMailer.php';
require_once ACC_ROOT . '/../lib/PHPMailer/src/SMTP.php';

/**
 * Sends one email through the site's SMTP account (config/mail.php).
 *
 * Setting `mail_transport` = "log" (acc_settings) writes each message to
 * data/mail-log/ instead of sending it — for local testing only.
 *
 * @return array{ok: bool, error: ?string}
 */
function acc_send_mail(string $toEmail, string $toName, string $subject, string $html, string $text): array {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Invalid email address.'];

    if (setting('mail_transport') === 'log') {
        $dir = ACC_ROOT . '/../data/mail-log';
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $file = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
        file_put_contents($file, "To: {$toName} <{$toEmail}>\nSubject: {$subject}\n\n{$text}\n\n--- HTML ---\n{$html}");
        return ['ok' => true, 'error' => null];
    }

    defined('JEIWS_CONFIG') or define('JEIWS_CONFIG', 1);
    $cfg = require ACC_ROOT . '/../config/mail.php';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $cfg['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)$cfg['port'];
        $mail->Timeout    = 20;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($cfg['from'], setting('company_short_name', 'JEIWS') . ' Accounts');
        $replyTo = setting('company_email');
        if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $mail->addReplyTo($replyTo, setting('company_name'));
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;
        $mail->send();
        return ['ok' => true, 'error' => null];
    } catch (MailException) {
        // ErrorInfo can contain server details — log it, show a short message.
        error_log('[accounts] mail to ' . $toEmail . ' failed: ' . $mail->ErrorInfo);
        return ['ok' => false, 'error' => 'Mail server rejected or could not be reached.'];
    }
}
