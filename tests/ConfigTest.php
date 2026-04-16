<?php

declare(strict_types=1);

namespace Tests;

use App\SourceConfigBuilder;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testMapsCommaSeparatedDataSourceIdsToBaseSourcesByIndex(): void
    {
        $sources = SourceConfigBuilder::buildSources(
            ['source-a', 'source-b'],
            [
                [
                    'name' => 'Alpha',
                    'date_property' => 'Due',
                    'status_property' => 'Status',
                ],
                [
                    'name' => 'Beta',
                    'date_property' => 'Start',
                    'status_property' => null,
                    'exclude_statuses' => ['Done'],
                ],
            ]
        );

        self::assertSame(['source-a', 'source-b'], array_column($sources, 'data_source_id'));
        self::assertSame('Alpha', $sources[0]['name']);
        self::assertSame('Beta', $sources[1]['name']);
        self::assertSame('Due', $sources[0]['date_property']);
        self::assertSame('Start', $sources[1]['date_property']);
        self::assertNull($sources[1]['status_property']);
        self::assertSame(['Done'], $sources[1]['exclude_statuses']);
    }

    public function testFallsBackToSingleDataSourceId(): void
    {
        self::assertSame(
            ['single-source'],
            SourceConfigBuilder::dataSourceIds('', ' single-source ')
        );
    }

    public function testMultiSourceSettingTakesPrecedenceOverSingleSourceSetting(): void
    {
        self::assertSame(
            ['source-a', 'source-b'],
            SourceConfigBuilder::dataSourceIds(' source-a, source-b,, ', 'single-source')
        );
    }

    public function testTooManyDataSourceIdsRequiresMatchingBaseSource(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('defines only 2 base sources');

        SourceConfigBuilder::buildSources(
            ['source-a', 'source-b', 'source-c'],
            [
                ['name' => 'Alpha'],
                ['name' => 'Beta'],
            ]
        );
    }
}
