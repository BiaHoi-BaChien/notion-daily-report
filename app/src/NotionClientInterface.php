<?php

declare(strict_types=1);

namespace App;

interface NotionClientInterface
{
    /**
     * @param array<int, string> $filterPropertyIds
     * @param array<string, mixed> $filter
     * @param array<int, array<string, string>> $sorts
     * @return array<int, array<string, mixed>>
     */
    public function queryDataSource(
        string $dataSourceId,
        array $filterPropertyIds = [],
        array $filter = [],
        array $sorts = [],
        ?int $maxResults = null
    ): array;

    /**
     * @return array<string, mixed>
     */
    public function retrievePage(string $pageId): array;

    /**
     * @param array<string, mixed> $properties
     * @param array<int, array<string, mixed>> $children
     * @return array<string, mixed>
     */
    public function createPage(string $dataSourceId, array $properties, array $children = []): array;
}
