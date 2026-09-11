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
    public function testReadsNestedPaginatedBlocksWithoutFollowingLinkedPages(): void
    {
        $history = [];
        $responses = [
            ['results' => [
                ['id' => 'toggle', 'type' => 'toggle', 'has_children' => true],
                ['id' => 'separate-page', 'type' => 'child_page', 'has_children' => true],
                ['id' => 'linked-page', 'type' => 'link_to_page', 'has_children' => true],
            ], 'has_more' => true, 'next_cursor' => 'root-next'],
            ['results' => [['id' => 'nested-a', 'type' => 'to_do']], 'has_more' => true, 'next_cursor' => 'nested-next'],
            ['results' => [['id' => 'nested-b', 'type' => 'paragraph']], 'has_more' => false],
            ['results' => [['id' => 'last', 'type' => 'paragraph']], 'has_more' => false],
        ];
        $stack = HandlerStack::create(new MockHandler(array_map(static fn (array $body): Response => new Response(200, [], json_encode($body)), $responses)));
        $stack->push(Middleware::history($history));
        $client = new NotionClient('test-token', '2026-03-11', 20, new Client(['base_uri' => 'https://api.notion.com', 'handler' => $stack]));
        self::assertSame(['toggle', 'nested-a', 'nested-b', 'last'], array_column($client->retrieveBlockChildren('root'), 'id'));
        self::assertCount(4, $history);
        self::assertSame('/v1/blocks/toggle/children', $history[2]['request']->getUri()->getPath());
        self::assertStringContainsString('start_cursor=nested-next', $history[2]['request']->getUri()->getQuery());
        self::assertStringContainsString('start_cursor=root-next', $history[3]['request']->getUri()->getQuery());
        foreach ($history as $request) {
            self::assertSame('GET', $request['request']->getMethod());
        }
    }

    public function testRejectsRepeatedBlockPaginationCursor(): void
    {
        $response = ['results' => [], 'has_more' => true, 'next_cursor' => 'repeated'];
        $mock = new MockHandler([new Response(200, [], json_encode($response)), new Response(200, [], json_encode($response))]);
        $client = new NotionClient('test-token', '2026-03-11', 20, new Client(['handler' => HandlerStack::create($mock)]));
        $this->expectException(NotionApiException::class);
        $this->expectExceptionMessage('Invalid or repeated page content cursor');
        $client->retrieveBlockChildren('root');
    }

    public function testUsesConfiguredCaBundleForDefaultHttpClient(): void
    {
        $client = new NotionClient('secret-token', '2026-03-11', 20, null, 'C:/certs/cacert.pem');

        $reflection = new \ReflectionProperty($client, 'client');
        $httpClient = $reflection->getValue($client);

        self::assertInstanceOf(Client::class, $httpClient);
        self::assertSame('C:/certs/cacert.pem', $httpClient->getConfig('verify'));
    }

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
        $results = $client->queryDataSource('source-id', ['title', 'due date'], [
            'and' => [
                [
                    'property' => '期限',
                    'date' => ['on_or_after' => '2026-04-17'],
                ],
            ],
        ]);

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
        self::assertSame('期限', $secondBody['filter']['and'][0]['property']);
    }

    public function testQueriesOnlyRequestedLatestResultsWithSorts(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'results' => [
                    ['id' => 'page-1'],
                    ['id' => 'page-2'],
                    ['id' => 'page-3'],
                    ['id' => 'page-4'],
                ],
                'has_more' => true,
                'next_cursor' => 'cursor-2',
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $httpClient = new Client([
            'base_uri' => 'https://api.notion.com',
            'handler' => $stack,
        ]);
        $client = new NotionClient('secret-token', '2026-03-11', 20, $httpClient);
        $filter = [
            'and' => [
                ['property' => '日付', 'date' => ['on_or_before' => '2026-08-12']],
                ['property' => '体重', 'number' => ['is_not_empty' => true]],
            ],
        ];
        $sorts = [
            ['property' => '日付', 'direction' => 'descending'],
            ['timestamp' => 'created_time', 'direction' => 'descending'],
        ];

        $results = $client->queryDataSource('weight-source', [], $filter, $sorts, 3);

        self::assertSame([['id' => 'page-1'], ['id' => 'page-2'], ['id' => 'page-3']], $results);
        self::assertCount(1, $history);
        $body = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertSame(3, $body['page_size']);
        self::assertSame($filter, $body['filter']);
        self::assertSame($sorts, $body['sorts']);
    }

    public function testRejectsRepeatedPaginationCursor(): void
    {
        $history = [];
        $repeatedPage = json_encode([
            'results' => [],
            'has_more' => true,
            'next_cursor' => 'cursor-2',
        ]);
        $mock = new MockHandler([
            new Response(200, [], $repeatedPage),
            new Response(200, [], $repeatedPage),
        ]);

        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $httpClient = new Client([
            'base_uri' => 'https://api.notion.com',
            'handler' => $stack,
        ]);

        $client = new NotionClient('secret-token', '2026-03-11', 20, $httpClient);

        try {
            $client->queryDataSource('source-id');
            self::fail('Expected a repeated cursor to be rejected.');
        } catch (NotionApiException $exception) {
            self::assertStringContainsString('repeated cursor', $exception->getMessage());
        }

        self::assertCount(2, $history);
    }

    public function testRejectsInvalidPaginationCursor(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'results' => [],
                'has_more' => true,
                'next_cursor' => ['unexpected'],
            ])),
        ]);
        $httpClient = new Client([
            'base_uri' => 'https://api.notion.com',
            'handler' => HandlerStack::create($mock),
        ]);

        $client = new NotionClient('secret-token', '2026-03-11', 20, $httpClient);

        $this->expectException(NotionApiException::class);
        $this->expectExceptionMessage('invalid cursor');

        $client->queryDataSource('source-id');
    }

    public function testRejectsPaginationBeyondMaximumPageCount(): void
    {
        $history = [];
        $responses = [];
        for ($page = 1; $page <= 100; $page++) {
            $responses[] = new Response(200, [], json_encode([
                'results' => [],
                'has_more' => true,
                'next_cursor' => 'cursor-' . $page,
            ]));
        }

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $httpClient = new Client([
            'base_uri' => 'https://api.notion.com',
            'handler' => $stack,
        ]);

        $client = new NotionClient('secret-token', '2026-03-11', 20, $httpClient);

        try {
            $client->queryDataSource('source-id');
            self::fail('Expected the page limit to be enforced.');
        } catch (NotionApiException $exception) {
            self::assertStringContainsString('maximum of 100 pages', $exception->getMessage());
        }

        self::assertCount(100, $history);
    }

    public function testRejectsPaginationBeyondMaximumResultCount(): void
    {
        $results = array_fill(0, 10001, ['id' => 'page-id']);
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'results' => $results,
                'has_more' => false,
                'next_cursor' => null,
            ])),
        ]);
        $httpClient = new Client([
            'base_uri' => 'https://api.notion.com',
            'handler' => HandlerStack::create($mock),
        ]);

        $client = new NotionClient('secret-token', '2026-03-11', 20, $httpClient);

        $this->expectException(NotionApiException::class);
        $this->expectExceptionMessage('maximum of 10000 results');

        $client->queryDataSource('source-id');
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

    public function testRetrievesPage(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'id' => 'page-id',
                'properties' => [
                    'Name' => [
                        'type' => 'title',
                        'title' => [
                            ['plain_text' => '決済システム'],
                        ],
                    ],
                ],
            ])),
        ]);

        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $httpClient = new Client([
            'base_uri' => 'https://api.notion.com',
            'handler' => $stack,
        ]);

        $client = new NotionClient('secret-token', '2026-03-11', 20, $httpClient);

        self::assertSame('page-id', $client->retrievePage('page-id')['id']);
        self::assertSame('GET', $history[0]['request']->getMethod());
        self::assertSame('/v1/pages/page-id', $history[0]['request']->getUri()->getPath());
    }

    public function testCreatesPageInDataSourceWithPropertiesAndChildren(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'id' => 'created-page-id',
                'url' => 'https://notion.example/created-page-id',
            ])),
        ]);

        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $httpClient = new Client([
            'base_uri' => 'https://api.notion.com',
            'handler' => $stack,
        ]);

        $client = new NotionClient('secret-token', '2026-03-11', 20, $httpClient);
        $page = $client->createPage(
            'report-source-id',
            [
                'Name' => [
                    'title' => [
                        [
                            'type' => 'text',
                            'text' => ['content' => 'Notion Daily Report 2026-04-16'],
                        ],
                    ],
                ],
                'Date' => [
                    'date' => ['start' => '2026-04-16'],
                ],
            ],
            [
                [
                    'object' => 'block',
                    'type' => 'heading_1',
                    'heading_1' => [
                        'rich_text' => [
                            [
                                'type' => 'text',
                                'text' => ['content' => '🗓 Notion Daily Report 2026-04-16'],
                            ],
                        ],
                    ],
                ],
            ]
        );

        self::assertSame('created-page-id', $page['id']);
        self::assertCount(1, $history);
        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/v1/pages', $request->getUri()->getPath());
        self::assertSame('2026-03-11', $request->getHeaderLine('Notion-Version'));

        $body = json_decode((string) $request->getBody(), true);
        self::assertSame('data_source_id', $body['parent']['type']);
        self::assertSame('report-source-id', $body['parent']['data_source_id']);
        self::assertSame('emoji', $body['icon']['type']);
        self::assertSame('📁', $body['icon']['emoji']);
        self::assertSame('Notion Daily Report 2026-04-16', $body['properties']['Name']['title'][0]['text']['content']);
        self::assertSame('2026-04-16', $body['properties']['Date']['date']['start']);
        self::assertSame('heading_1', $body['children'][0]['type']);
    }

    public function testCreatePageThrowsDetailedNotionException(): void
    {
        $mock = new MockHandler([
            new Response(400, [], json_encode([
                'object' => 'error',
                'status' => 400,
                'code' => 'validation_error',
                'message' => 'Name is expected to be title.',
            ])),
        ]);

        $httpClient = new Client([
            'base_uri' => 'https://api.notion.com',
            'handler' => HandlerStack::create($mock),
        ]);

        $client = new NotionClient('secret-token', '2026-03-11', 20, $httpClient);

        $this->expectException(NotionApiException::class);
        $this->expectExceptionMessage('create page in data source');
        $this->expectExceptionMessage('validation_error');
        $this->expectExceptionMessage('Name is expected to be title.');

        $client->createPage('report-source-id', ['Name' => ['title' => []]]);
    }
}
