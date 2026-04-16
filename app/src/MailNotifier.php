<?php

declare(strict_types=1);

namespace App;

use App\Exception\MailNotificationException;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

final class MailNotifier implements MailNotifierInterface
{
    /**
     * @param array<int, string> $recipients
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $from,
        private readonly array $recipients,
        private readonly string $secure = 'tls'
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->host) !== ''
            && trim($this->from) !== ''
            && $this->recipients !== [];
    }

    public function send(string $subject, string $body): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $mail = new PHPMailer(true);
        try {
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->Port = $this->port;
            $mail->SMTPAuth = trim($this->username) !== '' || trim($this->password) !== '';
            $mail->Username = $this->username;
            $mail->Password = $this->password;
            $mail->SMTPSecure = $this->smtpSecure();
            $mail->setFrom($this->from);

            foreach ($this->recipients as $recipient) {
                $mail->addAddress($recipient);
            }

            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = $body;
            $mail->isHTML(false);
            $mail->send();
        } catch (PHPMailerException $exception) {
            throw new MailNotificationException('Mail notification failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @return array<int, string>
     */
    public static function parseRecipients(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $recipient): bool => $recipient !== ''
        ));
    }

    private function smtpSecure(): string
    {
        return match (strtolower(trim($this->secure))) {
            '', 'none' => '',
            'ssl', 'smtps' => PHPMailer::ENCRYPTION_SMTPS,
            default => PHPMailer::ENCRYPTION_STARTTLS,
        };
    }
}
