<?php

declare(strict_types=1);

namespace Tests;

use App\Exception\SlackNotificationException;
use App\SlackNotifier;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class SlackNotifierTest extends TestCase
{
    public function testUsesConfiguredCaBundleForDefaultHttpClient(): void
    {
        $notifier = new SlackNotifier('https://hooks.slack.test/services/test', 10, null, 'C:/certs/cacert.pem');

        $reflection = new \ReflectionProperty($notifier, 'client');
        $httpClient = $reflection->getValue($notifier);

        self::assertInstanceOf(Client::class, $httpClient);
        self::assertSame('C:/certs/cacert.pem', $httpClient->getConfig('verify'));
    }

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

    public function testFailuresDoNotExposeWebhookSecretsOrPreviousExceptions(): void
    {
        $webhookUrl = 'https://hooks.slack.test/services/test-workspace/test-app/test-secret';
        $request = new Request('POST', $webhookUrl);
        $cases = [
            'server error' => [$webhookUrl, new Response(500, [], $webhookUrl), 'Slack notification failed (HTTP 500).'],
            'client error' => [$webhookUrl, new Response(400, [], rawurlencode($webhookUrl)), 'Slack notification failed (HTTP 400).'],
            'connection error' => [$webhookUrl, new ConnectException('Connection failed: ' . $webhookUrl . ' ' . rawurlencode($webhookUrl), $request), 'Slack notification failed.'],
            'invalid URL' => ['https://hooks.slack.test:invalid/services/test-workspace/test-app/test-secret', new Response(200), 'Slack notification failed.'],
        ];

        foreach ($cases as $case => [$url, $result, $expectedMessage]) {
            $client = new Client(['handler' => HandlerStack::create(new MockHandler([$result]))]);
            $notifier = new SlackNotifier($url, 10, $client);

            try {
                $notifier->send('Notion Daily Report');
                self::fail('Expected Slack notification failure: ' . $case);
            } catch (SlackNotificationException $exception) {
                self::assertSame($expectedMessage, $exception->getMessage(), $case);
                self::assertNull($exception->getPrevious(), $case);
                self::assertStringNotContainsString('test-secret', (string) $exception, $case);
            }
        }
    }

    public function testSkipsWhenWebhookUrlIsEmpty(): void
    {
        $notifier = new SlackNotifier('', 10);

        self::assertFalse($notifier->isConfigured());
        $notifier->send('No-op');
        self::assertTrue(true);
    }
}
