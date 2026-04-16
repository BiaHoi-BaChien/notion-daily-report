<?php

declare(strict_types=1);

namespace App;

use App\Exception\SlackNotificationException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

final class SlackNotifier implements SlackNotifierInterface
{
    private ClientInterface $client;

    public function __construct(
        private readonly string $webhookUrl,
        int $timeout,
        ?ClientInterface $client = null
    ) {
        $this->client = $client ?? new Client(['timeout' => $timeout]);
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
        } catch (GuzzleException $exception) {
            throw new SlackNotificationException('Slack notification failed: ' . $exception->getMessage(), 0, $exception);
        }
    }
}
