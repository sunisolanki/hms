<?php
// communication/send_mail.php

function sendSMTPMail(string $to, string $subject, string $body): bool
{
    if (trim($to) === '') {
        return false;
    }

    require_once $_SERVER['DOCUMENT_ROOT'] . '/communication/phpmailer/src/Exception.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/communication/phpmailer/src/PHPMailer.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/communication/phpmailer/src/SMTP.php';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        require $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';

        $stmt = $conn->prepare("
            SELECT
                mail_driver,
                smtp_host,
                smtp_port,
                smtp_username,
                smtp_password,
                smtp_encryption,
                from_email,
                from_name
            FROM email_settings
            WHERE id = 1
            LIMIT 1
        ");

        $stmt->execute();
        $result = $stmt->get_result();
        $settings = $result->fetch_assoc();
        $stmt->close();

        if (!$settings) {
            error_log('SMTP ERROR: Email settings not found.');
            return false;
        }

        if (
            strtolower(trim($settings['mail_driver'])) !== 'smtp' ||
            trim($settings['smtp_host']) === '' ||
            trim($settings['smtp_username']) === '' ||
            trim($settings['smtp_password']) === ''
        ) {
            error_log('SMTP ERROR: Invalid SMTP configuration.');
            return false;
        }

        $mail->isSMTP();
        $mail->Host = trim($settings['smtp_host']);
        $mail->SMTPAuth = true;
        $mail->Username = trim($settings['smtp_username']);
        $mail->Password = $settings['smtp_password'];
        $mail->Port = (int)$settings['smtp_port'];

        if (strtolower(trim($settings['smtp_encryption'])) === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif (strtolower(trim($settings['smtp_encryption'])) === 'tls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }

        $fromEmail = trim($settings['from_email']);
        $fromName = trim($settings['from_name']);

        $mail->setFrom(
            $fromEmail,
            $fromName !== '' ? $fromName : 'WebSplend'
        );

        $mail->addAddress(trim($to));

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags($body);

        return $mail->send();

    } catch (\Throwable $e) {
    echo '<pre style="white-space:pre-wrap;background:#fff3cd;padding:20px;border:1px solid #ffeeba;">';
    echo htmlspecialchars($e->getMessage());
    echo '</pre>';

    error_log(
        'SMTP MAIL ERROR | To: ' . $to .
        ' | Error: ' . $e->getMessage()
    );

    return false;
}
}