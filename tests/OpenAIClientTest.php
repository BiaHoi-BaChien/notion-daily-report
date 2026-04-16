<?php

declare(strict_types=1);

namespace Tests;

use App\Exception\OpenAIException;
use App\OpenAIClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class OpenAIClientTest extends TestCase
{
    public function testSendsResponsesRequestAndExtractsOutputText(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'output_text' => '1. 今日やること' . PHP_EOL . '- 請求書確認',
            ])),
        ]);

        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $httpClient = new Client([
            'base_uri' => 'https://api.openai.com',
            'handler' => $stack,
        ]);

        $client = new OpenAIClient('sk-test', 'gpt-5.2', 30, $httpClient);
        $summary = $client->summarize([
            [
                'source_name' => 'ToDo',
                'source_role' => '今日やるべき作業の確認',
                'title' => '請求書確認',
                'date' => '2026-04-16',
                'status' => '未着手',
                'classification' => 'today',
                'url' => 'https://notion.example/page',
                'last_edited_time' => '2026-04-16T00:00:00Z',
            ],
        ]);

        self::assertStringContainsString('請求書確認', $summary);
        self::assertCount(1, $history);
        self::assertSame('POST', $history[0]['request']->getMethod());
        self::assertSame('/v1/responses', $history[0]['request']->getUri()->getPath());
        self::assertSame('Bearer sk-test', $history[0]['request']->getHeaderLine('Authorization'));

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertSame('gpt-5.2', $body['model']);
        self::assertStringContainsString('推測や補完をしない', $body['instructions']);
        self::assertStringContainsString('請求書確認', $body['input']);
        self::assertStringNotContainsString('last_edited_time', $body['input']);
    }

    public function testPayloadIsLimitedToOneHundredItems(): void
    {
        $client = new OpenAIClient('sk-test', 'gpt-5.2', 30);
        $items = array_map(
            static fn (int $index): array => ['title' => 'Task ' . $index],
            range(1, 101)
        );

        self::assertCount(100, $client->buildPayload($items));
    }

    public function testAutoModelTriesCandidatesUntilOneSucceeds(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(403, [], json_encode([
                'error' => [
                    'message' => 'Project does not have access to model first-model',
                ],
            ])),
            new Response(200, [], json_encode([
                'output_text' => 'summary from second model',
            ])),
        ]);

        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $httpClient = new Client([
            'base_uri' => 'https://api.openai.com',
            'handler' => $stack,
        ]);

        $client = new OpenAIClient('sk-test', 'auto', 30, $httpClient, ['first-model', 'second-model']);

        self::assertSame('summary from second model', $client->summarize([['title' => 'Task']]));
        self::assertCount(2, $history);

        $firstBody = json_decode((string) $history[0]['request']->getBody(), true);
        $secondBody = json_decode((string) $history[1]['request']->getBody(), true);
        self::assertSame('first-model', $firstBody['model']);
        self::assertSame('second-model', $secondBody['model']);
    }

    public function testBlankModelUsesDefaultAutoCandidates(): void
    {
        $client = new OpenAIClient('sk-test', '', 30);

        self::assertSame(['gpt-4o-mini', 'gpt-4.1-mini', 'gpt-4o'], $client->modelsToTry());
    }

    public function testRequestErrorIncludesResponseMessage(): void
    {
        $mock = new MockHandler([
            new Response(403, [], json_encode([
                'error' => [
                    'message' => 'Project does not have access to model gpt-5.2',
                ],
            ])),
        ]);
        $httpClient = new Client([
            'base_uri' => 'https://api.openai.com',
            'handler' => HandlerStack::create($mock),
        ]);

        $client = new OpenAIClient('sk-test', 'gpt-5.2', 30, $httpClient);

        $this->expectException(OpenAIException::class);
        $this->expectExceptionMessage('HTTP 403');
        $this->expectExceptionMessage('does not have access to model');

        $client->summarize([['title' => 'Task']]);
    }
}
