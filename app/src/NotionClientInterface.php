<?php

declare(strict_types=1);

namespace App;

interface NotionClientInterface
{
    /**
     * @param array<int, string> $filterPropertyIds
     * @param array<string, mixed> $filter
     * @return array<int, array<string, mixed>>
     */
    public function queryDataSource(string $dataSourceId, array $filterPropertyIds = [], array $filter = []): array;

    /**
     * @return array<string, mixed>
     */
    public function retrievePage(string $pageId): array;
}
