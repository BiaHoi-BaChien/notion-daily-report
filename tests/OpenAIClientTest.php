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
        $schedule = '1. 今日確認するべきToDo' . PHP_EOL
            . '  ＊決済システム' . PHP_EOL
            . '    請求書確認（09:00）';
        $summary = $client->summarize($schedule);

        self::assertStringContainsString('請求書確認', $summary);
        self::assertCount(1, $history);
        self::assertSame('POST', $history[0]['request']->getMethod());
        self::assertSame('/v1/responses', $history[0]['request']->getUri()->getPath());
        self::assertSame('Bearer sk-test', $history[0]['request']->getHeaderLine('Authorization'));

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertSame('gpt-5.2', $body['model']);
        self::assertStringContainsString('整形や再分類はしない', $body['instructions']);
        self::assertStringContainsString('先頭の日付・曜日行', $body['instructions']);
        self::assertStringContainsString('YYYY年MM月DD日（●）', $body['instructions']);
        self::assertStringContainsString('軽い挨拶', $body['instructions']);
        self::assertStringContainsString('祝日・記念日・イベント名', $body['instructions']);
        self::assertStringContainsString('入力に明示されていない限り出力しない', $body['instructions']);
        self::assertStringContainsString('前向きに今日一日を始められる', $body['instructions']);
        self::assertStringContainsString('予定の再掲は出力しない', $body['instructions']);
        self::assertStringContainsString('請求書確認', $body['input']);
        self::assertSame($schedule, $body['input']);
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

        self::assertSame('summary from second model', $client->summarize('1. 今日確認するべきToDo'));
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

        $client->summarize('1. 今日確認するべきToDo');
    }
}
