<?php

declare(strict_types=1);

namespace App;

use App\Exception\SlackNotificationException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Throwable;

final class SlackNotifier implements SlackNotifierInterface
{
    private ClientInterface $client;

    public function __construct(
        private readonly string $webhookUrl,
        int $timeout,
        ?ClientInterface $client = null,
        ?string $caBundlePath = null
    ) {
        $options = ['timeout' => $timeout];
        if ($caBundlePath !== null && trim($caBundlePath) !== '') {
            $options['verify'] = $caBundlePath;
        }

        $this->client = $client ?? new Client($options);
    }

    public function isConfigured(): bool
    {
        return trim($this->webhookUrl) !== '';
    }

    public function send(string $text): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        try {
            $this->client->request('POST', $this->webhookUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'text' => $text,
                ],
            ]);
        } catch (Throwable $exception) {
            $status = $exception instanceof RequestException ? $exception->getResponse()?->getStatusCode() : null;
            // HTTP and URI parsing exceptions can retain webhook credentials, including in previous exceptions.
            throw new SlackNotificationException($status === null
                ? 'Slack notification failed.'
                : sprintf('Slack notification failed (HTTP %d).', $status));
        }
    }
}
