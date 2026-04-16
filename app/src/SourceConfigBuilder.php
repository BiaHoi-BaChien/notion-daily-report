<?php

declare(strict_types=1);

namespace App;

use UnexpectedValueException;

final class SourceConfigBuilder
{
    /**
     * @return array<int, string>
     */
    public static function dataSourceIds(mixed $multiValue, mixed $singleValue): array
    {
        $dataSourceIds = self::splitCsv($multiValue);
        if ($dataSourceIds !== []) {
            return $dataSourceIds;
        }

        $singleDataSourceId = is_string($singleValue) ? trim($singleValue) : '';
        return $singleDataSourceId === '' ? [] : [$singleDataSourceId];
    }

    /**
     * @param array<int, string> $dataSourceIds
     * @param array<int, array<string, mixed>> $baseSources
     * @return array<int, array<string, mixed>>
     */
    public static function buildSources(array $dataSourceIds, array $baseSources): array
    {
        if (count($dataSourceIds) > count($baseSources)) {
            throw new UnexpectedValueException(sprintf(
                'NOTION_DATA_SOURCE_IDS has %d IDs, but app/config/app.php defines only %d base sources.',
                count($dataSourceIds),
                count($baseSources)
            ));
        }

        return array_map(
            static fn (string $dataSourceId, array $baseSource): array => array_merge($baseSource, [
                'data_source_id' => $dataSourceId,
            ]),
            $dataSourceIds,
            array_slice($baseSources, 0, count($dataSourceIds))
        );
    }

    /**
     * @return array<int, string>
     */
    public static function splitCsv(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $item): bool => $item !== ''
        ));
    }
}
