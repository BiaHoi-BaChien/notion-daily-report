<?php

declare(strict_types=1);

namespace Tests;

use App\NotionClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class NotionClientTest extends TestCase
{
    public function testQueriesDataSourceWithPaginationAndFilterProperties(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'results' => [['id' => 'page-1']],
                'has_more' => true,
                'next_cursor' => 'cursor-2',
            ])),
            new Response(200, [], json_encode([
                'results' => [['id' => 'page-2']],
                'has_more' => false,
                'next_cursor' => null,
            ])),
        ]);

        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $httpClient = new Client([
            'base_uri' => 'https://api.notion.com',
            'handler' => $stack,
        ]);

        $client = new NotionClient('secret-token', '2026-03-11', 20, $httpClient);
        $results = $client->queryDataSource('source-id', ['title', 'due date']);

        self::assertSame([['id' => 'page-1'], ['id' => 'page-2']], $results);
        self::assertCount(2, $history);

        $firstRequest = $history[0]['request'];
        self::assertSame('POST', $firstRequest->getMethod());
        self::assertSame('/v1/data_sources/source-id/query', $firstRequest->getUri()->getPath());
        self::assertSame('2026-03-11', $firstRequest->getHeaderLine('Notion-Version'));
        self::assertStringContainsString('filter_properties=title', $firstRequest->getUri()->getQuery());
        self::assertStringContainsString('filter_properties=due%20date', $firstRequest->getUri()->getQuery());

        $secondBody = json_decode((string) $history[1]['request']->getBody(), true);
        self::assertSame('cursor-2', $secondBody['start_cursor']);
    }
}
