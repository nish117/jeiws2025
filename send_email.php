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

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = $cfg['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $cfg['username'];
    $mail->Password   = $cfg['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $cfg['port'];

    $name    = htmlspecialchars($_POST["name"]    ?? '');
    $email   = filter_var($_POST["email"] ?? '', FILTER_SANITIZE_EMAIL);
    $phone   = htmlspecialchars($_POST["phone"]   ?? '');
    $message = htmlspecialchars($_POST["message"] ?? '');

    if (empty($name) || empty($email) || empty($message) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "error";
        exit;
    }

    $mail->setFrom($cfg['from'], $cfg['fromName']);
    $mail->addAddress($cfg['to']);
    $mail->addReplyTo($email, $name);

    $mail->isHTML(true);
    $mail->Subject = "Inquiry From the JEIWS website";
    $mail->Body    = "<h3>JEIWS Website Message Request</h3>
        <p><strong>Name:</strong> $name</p>
        <p><strong>Email:</strong> $email</p>
        <p><strong>Phone:</strong> $phone</p>
        <p><strong>Message:</strong> $message</p>";

    echo $mail->send() ? "success" : "error";

} catch (Exception $e) {
    echo "error";
}
?>
