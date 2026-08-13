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
    public function testUsesConfiguredCaBundleForDefaultHttpClient(): void
    {
        $client = new OpenAIClient('sk-test', 'gpt-5.2', 30, null, [], 'C:/certs/cacert.pem');

        $reflection = new \ReflectionProperty($client, 'client');
        $httpClient = $reflection->getValue($client);

        self::assertInstanceOf(Client::class, $httpClient);
        self::assertSame('C:/certs/cacert.pem', $httpClient->getConfig('verify'));
    }

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
        self::assertStringContainsString('入力に健康セクションが含まれる場合があります', $body['instructions']);
        self::assertStringContainsString('診断、因果関係、服薬判断を行わない', $body['instructions']);
        self::assertStringContainsString('日本人男性（1976年生まれ）、身長163cm、目標体重60kg', $body['instructions']);
        self::assertStringContainsString('良い点や継続できている点を具体的に褒め', $body['instructions']);
        self::assertStringContainsString('注意を穏やかに促してください', $body['instructions']);
        self::assertStringContainsString('健康セクションがない場合は健康コメントを作らない', $body['instructions']);
        self::assertStringContainsString('ベトナム・ホーチミン在住の日本人ブリッジSE', $body['instructions']);
        self::assertStringContainsString('同じ気候ネタに偏らない', $body['instructions']);
        self::assertStringContainsString('冒頭の切り口は日替わりで変えてください', $body['instructions']);
        self::assertStringContainsString('スポーツ、時事一般、ベトナム・ホーチミンのローカル生活', $body['instructions']);
        self::assertStringContainsString('日本との違い、仕事の集中、学校・家族', $body['instructions']);
        self::assertStringContainsString('月初・月末、週の始まり・週末前', $body['instructions']);
        self::assertStringContainsString('ホーチミンは雨季で', $body['instructions']);
        self::assertStringContainsString('最新ニュース、試合結果、為替、政治・事故・災害', $body['instructions']);
        self::assertStringContainsString('入力にない現在情報は断定しない', $body['instructions']);
        self::assertStringContainsString('ユーザーの子供の学校予定', $body['instructions']);
        self::assertStringContainsString('仕事や生活の予定とは分けて', $body['instructions']);
        self::assertStringContainsString('文数や行数を2〜4に制限する必要はありません', $body['instructions']);
        self::assertStringContainsString('EC、AI、ベトナムローカル情報', $body['instructions']);
        self::assertStringContainsString('予定や日付に自然につながる場合だけ', $body['instructions']);
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
