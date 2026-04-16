<?php

declare(strict_types=1);

namespace App;

interface NotionClientInterface
{
    /**
     * @param array<int, string> $filterPropertyIds
     * @return array<int, array<string, mixed>>
     */
    public function queryDataSource(string $dataSourceId, array $filterPropertyIds = []): array;
}
