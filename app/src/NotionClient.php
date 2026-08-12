<?php

declare(strict_types=1);

namespace App;

use App\Exception\NotionApiException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

final class NotionClient implements NotionClientInterface
{
    private const BASE_URI = 'https://api.notion.com';
    private const MAX_CHILDREN_PER_REQUEST = 100;
    private const MAX_QUERY_PAGES = 100;
    private const MAX_QUERY_RESULTS = 10000;

    private ClientInterface $client;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $notionVersion,
        int $timeout,
        ?ClientInterface $client = null,
        ?string $caBundlePath = null
    ) {
        $options = [
            'base_uri' => self::BASE_URI,
            'timeout' => $timeout,
        ];
        if ($caBundlePath !== null && trim($caBundlePath) !== '') {
            $options['verify'] = $caBundlePath;
        }

        $this->client = $client ?? new Client($options);
    }

    public function queryDataSource(
        string $dataSourceId,
        array $filterPropertyIds = [],
        array $filter = [],
        array $sorts = [],
        ?int $maxResults = null
    ): array {
        $this->assertConfigured($dataSourceId);

        if ($maxResults !== null && $maxResults <= 0) {
            return [];
        }

        $resultLimit = $maxResults === null
            ? self::MAX_QUERY_RESULTS
            : min($maxResults, self::MAX_QUERY_RESULTS);

        $dataSourceId = trim($dataSourceId);
        $queryId = $dataSourceId;
        $triedDatabaseLookup = false;
        $results = [];
        $nextCursor = null;
        $seenCursors = [];
        $pageCount = 0;

        do {
            if ($pageCount >= self::MAX_QUERY_PAGES) {
                throw new NotionApiException(sprintf(
                    'Notion API pagination exceeded the maximum of %d pages for data source "%s".',
                    self::MAX_QUERY_PAGES,
                    $queryId
                ));
            }

            $payload = [
                'page_size' => $maxResults === null
                    ? 100
                    : min(100, $resultLimit - count($results)),
            ];
            if ($filter !== []) {
                $payload['filter'] = $filter;
            }
            if ($sorts !== []) {
                $payload['sorts'] = $sorts;
            }

            if ($nextCursor !== null) {
                $payload['start_cursor'] = $nextCursor;
            }

            while (true) {
                try {
                    $response = $this->requestQuery($queryId, $payload, $filterPropertyIds);
                    break;
                } catch (RequestException $exception) {
                    if (!$triedDatabaseLookup && $nextCursor === null && $this->isObjectNotFound($exception)) {
                        $queryId = $this->resolveSingleDataSourceIdFromDatabase($dataSourceId);
                        $triedDatabaseLookup = true;
                        continue;
                    }

                    throw $this->createRequestException('query data source', $queryId, $exception);
                } catch (GuzzleException $exception) {
                    throw new NotionApiException('Notion API request failed: ' . $exception->getMessage(), 0, $exception);
                }
            }

            $pageCount++;
            foreach ($response['results'] ?? [] as $page) {
                if (is_array($page)) {
                    if ($maxResults !== null && count($results) >= $resultLimit) {
                        break;
                    }

                    if (count($results) >= self::MAX_QUERY_RESULTS) {
                        throw new NotionApiException(sprintf(
                            'Notion API pagination exceeded the maximum of %d results for data source "%s".',
                            self::MAX_QUERY_RESULTS,
                            $queryId
                        ));
                    }

                    $results[] = $page;
                }
            }

            if ($maxResults !== null && count($results) >= $resultLimit) {
                $nextCursor = null;
                continue;
            }

            $nextCursor = ($response['has_more'] ?? false) ? ($response['next_cursor'] ?? null) : null;
            if ($nextCursor !== null) {
                if (!is_string($nextCursor) || trim($nextCursor) === '') {
                    throw new NotionApiException(sprintf(
                        'Notion API pagination returned an invalid cursor for data source "%s".',
                        $queryId
                    ));
                }

                if (isset($seenCursors[$nextCursor])) {
                    throw new NotionApiException(sprintf(
                        'Notion API pagination returned a repeated cursor for data source "%s".',
                        $queryId
                    ));
                }

                $seenCursors[$nextCursor] = true;
            }
        } while ($nextCursor !== null);

        return $results;
    }

    public function retrievePage(string $pageId): array
    {
        $this->assertConfigured($pageId);
        $pageId = trim($pageId);

        try {
            $response = $this->client->request(
                'GET',
                sprintf('/v1/pages/%s', rawurlencode($pageId)),
                ['headers' => $this->requestHeaders()]
            );
        } catch (RequestException $exception) {
            throw $this->createRequestException('retrieve page', $pageId, $exception);
        } catch (GuzzleException $exception) {
            throw new NotionApiException('Notion API request failed: ' . $exception->getMessage(), 0, $exception);
        }

        return $this->decodeResponse($response);
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<int, array<string, mixed>> $children
     * @return array<string, mixed>
     */
    public function createPage(string $dataSourceId, array $properties, array $children = []): array
    {
        $this->assertConfigured($dataSourceId);
        $dataSourceId = trim($dataSourceId);

        $payload = [
            'parent' => [
                'type' => 'data_source_id',
                'data_source_id' => $dataSourceId,
            ],
            'icon' => [
                'type' => 'emoji',
                'emoji' => '📁',
            ],
            'properties' => $properties,
        ];

        $initialChildren = array_slice($children, 0, self::MAX_CHILDREN_PER_REQUEST);
        if ($initialChildren !== []) {
            $payload['children'] = $initialChildren;
        }

        try {
            $response = $this->client->request(
                'POST',
                '/v1/pages',
                [
                    'headers' => $this->requestHeaders(),
                    'json' => $payload,
                ]
            );
        } catch (RequestException $exception) {
            throw $this->createRequestException('create page in data source', $dataSourceId, $exception);
        } catch (GuzzleException $exception) {
            throw new NotionApiException('Notion API request failed: ' . $exception->getMessage(), 0, $exception);
        }

        $page = $this->decodeResponse($response);
        $pageId = isset($page['id']) && is_string($page['id']) ? $page['id'] : '';
        $remainingChildren = array_slice($children, self::MAX_CHILDREN_PER_REQUEST);
        if ($pageId !== '' && $remainingChildren !== []) {
            $this->appendBlockChildren($pageId, $remainingChildren);
        }

        return $page;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $filterPropertyIds
     * @return array<string, mixed>
     */
    private function requestQuery(string $dataSourceId, array $payload, array $filterPropertyIds): array
    {
        $query = [];
        foreach ($filterPropertyIds as $propertyId) {
            if ($propertyId !== '') {
                $query[] = 'filter_properties=' . rawurlencode($propertyId);
            }
        }

        $options = [
            'headers' => $this->requestHeaders(),
            'json' => $payload,
        ];

        if ($query !== []) {
            $options['query'] = implode('&', $query);
        }

        return $this->decodeResponse($this->client->request(
            'POST',
            sprintf('/v1/data_sources/%s/query', rawurlencode($dataSourceId)),
            $options
        ));
    }

    private function resolveSingleDataSourceIdFromDatabase(string $databaseId): string
    {
        try {
            $response = $this->client->request(
                'GET',
                sprintf('/v1/databases/%s', rawurlencode($databaseId)),
                ['headers' => $this->requestHeaders()]
            );
        } catch (RequestException $exception) {
            throw $this->createRequestException('retrieve database while resolving NOTION_DATA_SOURCE_ID', $databaseId, $exception);
        } catch (GuzzleException $exception) {
            throw new NotionApiException('Notion API request failed: ' . $exception->getMessage(), 0, $exception);
        }

        $database = $this->decodeResponse($response);
        $dataSources = [];
        foreach (($database['data_sources'] ?? []) as $dataSource) {
            if (is_array($dataSource) && isset($dataSource['id']) && is_string($dataSource['id']) && trim($dataSource['id']) !== '') {
                $dataSources[] = [
                    'id' => trim($dataSource['id']),
                    'name' => isset($dataSource['name']) && is_string($dataSource['name']) ? $dataSource['name'] : '',
                ];
            }
        }

        if (count($dataSources) === 1) {
            return $dataSources[0]['id'];
        }

        if ($dataSources === []) {
            throw new NotionApiException(sprintf(
                'Notion database "%s" did not return any data sources. Set NOTION_DATA_SOURCE_ID to a data source ID, or confirm the parent database is shared with the integration.',
                $databaseId
            ));
        }

        $choices = array_map(
            static fn (array $dataSource): string => $dataSource['name'] === ''
                ? $dataSource['id']
                : sprintf('%s (%s)', $dataSource['name'], $dataSource['id']),
            $dataSources
        );

        throw new NotionApiException(sprintf(
            'Notion database "%s" has multiple data sources: %s. Set NOTION_DATA_SOURCE_ID to the specific data source ID.',
            $databaseId,
            implode(', ', $choices)
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $children
     */
    private function appendBlockChildren(string $blockId, array $children): void
    {
        foreach (array_chunk($children, self::MAX_CHILDREN_PER_REQUEST) as $chunk) {
            try {
                $this->client->request(
                    'PATCH',
                    sprintf('/v1/blocks/%s/children', rawurlencode($blockId)),
                    [
                        'headers' => $this->requestHeaders(),
                        'json' => [
                            'children' => $chunk,
                        ],
                    ]
                );
            } catch (RequestException $exception) {
                throw $this->createRequestException('append block children', $blockId, $exception);
            } catch (GuzzleException $exception) {
                throw new NotionApiException('Notion API request failed: ' . $exception->getMessage(), 0, $exception);
            }
        }
    }

    private function assertConfigured(string $dataSourceId): void
    {
        if (trim($this->apiKey) === '') {
            throw new NotionApiException('NOTION_API_KEY is required.');
        }

        if (trim($dataSourceId) === '') {
            throw new NotionApiException('Notion data_source_id is required.');
        }

        if (trim($this->notionVersion) === '') {
            throw new NotionApiException('NOTION_VERSION is required.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function requestHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Notion-Version' => $this->notionVersion,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new NotionApiException('Notion API response was not valid JSON.');
        }

        return $decoded;
    }

    private function isObjectNotFound(RequestException $exception): bool
    {
        $response = $exception->getResponse();
        if ($response === null || $response->getStatusCode() !== 404) {
            return false;
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            return false;
        }

        return ($decoded['code'] ?? null) === 'object_not_found';
    }

    private function createRequestException(string $action, string $id, RequestException $exception): NotionApiException
    {
        $response = $exception->getResponse();
        if ($response === null) {
            return new NotionApiException('Notion API request failed: ' . $exception->getMessage(), 0, $exception);
        }

        $decoded = json_decode((string) $response->getBody(), true);
        $message = is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])
            ? $decoded['message']
            : trim((string) $response->getBody());
        $code = is_array($decoded) && isset($decoded['code']) && is_string($decoded['code'])
            ? $decoded['code']
            : 'unknown';

        $details = sprintf(
            'Notion API request failed while trying to %s for ID "%s": HTTP %d %s: %s',
            $action,
            $id,
            $response->getStatusCode(),
            $code,
            $message
        );

        if ($response->getStatusCode() === 404) {
            $details .= ' Confirm NOTION_DATA_SOURCE_ID is a data source ID, or a database ID with exactly one data source, and that the parent database is shared with the integration.';
        }

        return new NotionApiException($details, 0, $exception);
    }
}
