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

    private ClientInterface $client;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $notionVersion,
        int $timeout,
        ?ClientInterface $client = null
    ) {
        $this->client = $client ?? new Client([
            'base_uri' => self::BASE_URI,
            'timeout' => $timeout,
        ]);
    }

    public function queryDataSource(string $dataSourceId, array $filterPropertyIds = []): array
    {
        $this->assertConfigured($dataSourceId);

        $dataSourceId = trim($dataSourceId);
        $queryId = $dataSourceId;
        $triedDatabaseLookup = false;
        $results = [];
        $nextCursor = null;

        do {
            $payload = ['page_size' => 100];
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

            foreach ($response['results'] ?? [] as $page) {
                if (is_array($page)) {
                    $results[] = $page;
                }
            }

            $nextCursor = ($response['has_more'] ?? false) ? ($response['next_cursor'] ?? null) : null;
        } while ($nextCursor !== null);

        return $results;
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
