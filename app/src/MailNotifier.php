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
            $mail->Body = self::renderHtmlBody($body);
            $mail->AltBody = $body;
            $mail->isHTML(true);
            $mail->send();
        } catch (PHPMailerException $exception) {
            throw new MailNotificationException('Mail notification failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    public static function renderHtmlBody(string $body): string
    {
        $escapedBody = htmlspecialchars(
            str_replace(["\r\n", "\r"], "\n", rtrim($body)),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        return <<<HTML
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:24px;background:#f4f6f8;color:#111827;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Yu Gothic',Meiryo,sans-serif;">
  <div style="max-width:760px;margin:0 auto;background:#ffffff;border:1px solid #d9dee7;border-radius:8px;padding:24px;">
    <pre style="margin:0;white-space:pre-wrap;word-break:break-word;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Yu Gothic',Meiryo,sans-serif;font-size:14px;line-height:1.75;color:#111827;">{$escapedBody}</pre>
  </div>
</body>
</html>
HTML;
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
