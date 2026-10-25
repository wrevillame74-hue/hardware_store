<?php

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../vendor/autoload.php';

function send_mail($to, $toName, $subject, $body, $attachments = [])
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'sandbox.smtp.mailtrap.io';
        $mail->SMTPAuth   = true;
        $mail->Username   = '6d50f90d35b843';
        $mail->Password   = '6e910dc0756cfb';
        $mail->Port       = 2525;

        $mail->setFrom('hello@demomailtrap.co', 'Sturdy Supplies');
        $mail->addAddress($to, $toName);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);

        // Attach PDF receipts when provided.
        foreach ($attachments as $attachment) {
            if (is_string($attachment) && is_file($attachment)) {
                $mail->addAttachment(
                    $attachment,
                    'Sturdy_Supplies_Receipt.pdf',
                    'base64',
                    'application/pdf'
                );
            }
        }

        $mail->send();

        return true;

    } catch (\Throwable $e) {
        error_log('Mailtrap error: ' . $e->getMessage());
        return false;
    }
}