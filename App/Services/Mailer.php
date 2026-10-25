<?php
declare(strict_types=1);
namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

require_once BASE_PATH . 'App/Libraries/PHPMailer/Exception.php';
require_once BASE_PATH . 'App/Libraries/PHPMailer/PHPMailer.php';
require_once BASE_PATH . 'App/Libraries/PHPMailer/SMTP.php';

class Mailer {
    public function send(string $to, string $subject, string $body): bool {
        $host = (string) getenv('MAIL_HOST');
        $username = (string) getenv('MAIL_USERNAME');

        if ($host === '' || $username === '') {
            error_log('Mailer: missing MAIL_HOST or MAIL_USERNAME');
            return false;
        }

        $port = (int) (getenv('MAIL_PORT') ?: 587);
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = $port;
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = (string) getenv('MAIL_PASSWORD');
            $mail->SMTPSecure = $port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Timeout = 15;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            $mail->setFrom((string) (getenv('MAIL_FROM') ?: $username), (string) (getenv('MAIL_FROM_NAME') ?: 'Wellness Journal'));
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $body;

            return $mail->send();
        } catch (MailerException $e) {
            error_log('Mailer: ' . $mail->ErrorInfo);
            return false;
        }
    }
}
