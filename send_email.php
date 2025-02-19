<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require './lib/PHPMailer/src/Exception.php';
require './lib/PHPMailer/src/PHPMailer.php';
require './lib/PHPMailer/src/SMTP.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") { 
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();                                            //Send using SMTP
        $mail->Host       = 'smtp.gmail.com';                     //Set the SMTP server to send through
        $mail->SMTPAuth   = true;                                   //Enable SMTP authentication
        $mail->Username   = 'bajra.nish@gmail.com';                     //SMTP username
        $mail->Password   = 'mpiwwacvawbjtipi';                               //SMTP password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;            //Enable implicit TLS encryption
        $mail->Port       = 587;  

        $name = htmlspecialchars($_POST["name"]);
        $email = htmlspecialchars($_POST["email"]);
        $phone = htmlspecialchars($_POST["phone"]);
        $message = htmlspecialchars($_POST["message"]);

        $adminEmail = "bajra.nish@gmail.com"; // Change to your email
        $headers = "From: " . $email . "\r\n";
        $headers .= "Reply-To: " . $email . "\r\n";

        // Email Setup
        $mail->setFrom($email, $name);
        $mail->addAddress('bajra.nish@gmail.com'); // Admin email

        // Content
        $mail->isHTML(true);
        $mail->Subject = "Inquiry From the JEIWS website";
        $mail->Body    = "<h3>JEIWS Website Message  Request</h3>
        <p><strong>Name:</strong> $name</p>
        <p><strong>Email:</strong> $email</p>
        <p><strong>Phone:</strong> $phone</p>
        <p><strong>Message:</strong> $message</p>";

        // Send Email
        if ($mail->send()) {
            echo "success";
        } else {
            echo "error";
        }
    } catch (Exception $e) {
        echo "Mailer Error: {$mail->ErrorInfo}";
    }
}
?>
