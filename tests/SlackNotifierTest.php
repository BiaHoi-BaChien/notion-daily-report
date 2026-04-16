<?php

declare(strict_types=1);

namespace Tests;

use App\SlackNotifier;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class SlackNotifierTest extends TestCase
{
    public function testSendsTextPayloadToWebhook(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], 'ok'),
        ]);

        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $client = new Client(['handler' => $stack]);

        $notifier = new SlackNotifier('https://hooks.slack.test/services/test', 10, $client);
        $notifier->send("Notion Daily Report\n- task");

        self::assertCount(1, $history);
        self::assertSame('POST', $history[0]['request']->getMethod());
        self::assertSame('/services/test', $history[0]['request']->getUri()->getPath());

        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertSame("Notion Daily Report\n- task", $payload['text']);
    }

    public function testSkipsWhenWebhookUrlIsEmpty(): void
    {
        $notifier = new SlackNotifier('', 10);

        self::assertFalse($notifier->isConfigured());
        $notifier->send('No-op');
        self::assertTrue(true);
    }
}
