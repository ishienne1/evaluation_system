<?php
require __DIR__ . '/../PHPMailer/src/Exception.php';
require __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require __DIR__ . '/../PHPMailer/src/SMTP.php';
require __DIR__ . '/email_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$to = $argv[1] ?? '';
if (!$to) { exit("Usage: php test_brevo.php your-email@example.com\n"); }

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = SMTP_AUTH;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = SMTP_SECURE;
    $mail->Port       = SMTP_PORT;
    $mail->Timeout    = 15;
    $mail->SMTPDebug   = 2;
    $mail->Debugoutput = 'echo';

    $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
    $mail->addAddress($to);
    $mail->Subject = 'Brevo SMTP test';
    $mail->Body    = 'If you can read this, Brevo SMTP is working.';

    $mail->send();
    echo "\n\nSUCCESS: email sent to $to\n";
} catch (Exception $e) {
    echo "\n\nFAILED: " . $mail->ErrorInfo . "\n";
}