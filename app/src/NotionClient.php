<?php

declare(strict_types=1);

namespace App;

use App\Exception\NotionApiException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

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

        $results = [];
        $nextCursor = null;

        do {
            $payload = ['page_size' => 100];
            if ($nextCursor !== null) {
                $payload['start_cursor'] = $nextCursor;
            }

            $response = $this->requestQuery($dataSourceId, $payload, $filterPropertyIds);
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
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Notion-Version' => $this->notionVersion,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ];

        if ($query !== []) {
            $options['query'] = implode('&', $query);
        }

        try {
            $response = $this->client->request(
                'POST',
                sprintf('/v1/data_sources/%s/query', rawurlencode($dataSourceId)),
                $options
            );
        } catch (GuzzleException $exception) {
            throw new NotionApiException('Notion API request failed: ' . $exception->getMessage(), 0, $exception);
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new NotionApiException('Notion API response was not valid JSON.');
        }

        return $decoded;
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
}
