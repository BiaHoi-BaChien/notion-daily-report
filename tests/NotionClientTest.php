<?php

declare(strict_types=1);

namespace Tests;

use App\NotionClient;
use App\Exception\NotionApiException;
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

    public function testResolvesSingleDataSourceWhenDatabaseIdIsConfigured(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(404, [], json_encode([
                'object' => 'error',
                'status' => 404,
                'code' => 'object_not_found',
                'message' => 'Could not find database with ID: database-id',
            ])),
            new Response(200, [], json_encode([
                'object' => 'database',
                'id' => 'database-id',
                'data_sources' => [
                    ['id' => 'resolved-source-id', 'name' => 'Tasks'],
                ],
            ])),
            new Response(200, [], json_encode([
                'results' => [['id' => 'page-1']],
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

        $client = new NotionClient('secret-token', '2025-09-03', 20, $httpClient);

        self::assertSame([['id' => 'page-1']], $client->queryDataSource('database-id'));
        self::assertCount(3, $history);
        self::assertSame('/v1/data_sources/database-id/query', $history[0]['request']->getUri()->getPath());
        self::assertSame('/v1/databases/database-id', $history[1]['request']->getUri()->getPath());
        self::assertSame('/v1/data_sources/resolved-source-id/query', $history[2]['request']->getUri()->getPath());
    }

    public function testDatabaseIdWithMultipleDataSourcesRequiresExplicitDataSourceId(): void
    {
        $mock = new MockHandler([
            new Response(404, [], json_encode([
                'object' => 'error',
                'status' => 404,
                'code' => 'object_not_found',
                'message' => 'Could not find database with ID: database-id',
            ])),
            new Response(200, [], json_encode([
                'object' => 'database',
                'id' => 'database-id',
                'data_sources' => [
                    ['id' => 'source-a', 'name' => 'Tasks'],
                    ['id' => 'source-b', 'name' => 'Archive'],
                ],
            ])),
        ]);

        $httpClient = new Client([
            'base_uri' => 'https://api.notion.com',
            'handler' => HandlerStack::create($mock),
        ]);

        $client = new NotionClient('secret-token', '2025-09-03', 20, $httpClient);

        $this->expectException(NotionApiException::class);
        $this->expectExceptionMessage('has multiple data sources');
        $this->expectExceptionMessage('Tasks (source-a)');
        $this->expectExceptionMessage('Archive (source-b)');

        $client->queryDataSource('database-id');
    }
}
