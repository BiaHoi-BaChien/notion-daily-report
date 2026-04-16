<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private array $originalEnv;

    protected function setUp(): void
    {
        $this->originalEnv = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->originalEnv;
        putenv('NOTION_DATA_SOURCE_ID');
        putenv('NOTION_DATA_SOURCE_IDS');
    }

    public function testMapsCommaSeparatedDataSourceIdsToBaseSourcesByIndex(): void
    {
        $_ENV['NOTION_DATA_SOURCE_IDS'] = 'source-a, source-b';
        $_ENV['NOTION_DATA_SOURCE_ID'] = '';

        $config = require dirname(__DIR__) . '/app/config/app.php';

        self::assertSame(['source-a', 'source-b'], array_column($config['sources'], 'data_source_id'));
        self::assertSame(['ToDo', '会議予定'], array_column($config['sources'], 'name'));
        self::assertSame('いつまでに', $config['sources'][0]['date_property']);
        self::assertSame('開始日', $config['sources'][1]['date_property']);
        self::assertNull($config['sources'][1]['status_property']);
    }

    public function testFallsBackToSingleDataSourceId(): void
    {
        $_ENV['NOTION_DATA_SOURCE_ID'] = 'single-source';
        $_ENV['NOTION_DATA_SOURCE_IDS'] = '';

        $config = require dirname(__DIR__) . '/app/config/app.php';

        self::assertCount(1, $config['sources']);
        self::assertSame('single-source', $config['sources'][0]['data_source_id']);
        self::assertSame('ToDo', $config['sources'][0]['name']);
    }

    public function testMultiSourceSettingTakesPrecedenceOverSingleSourceSetting(): void
    {
        $_ENV['NOTION_DATA_SOURCE_ID'] = 'single-source';
        $_ENV['NOTION_DATA_SOURCE_IDS'] = 'source-a,source-b';

        $config = require dirname(__DIR__) . '/app/config/app.php';

        self::assertSame(['source-a', 'source-b'], array_column($config['sources'], 'data_source_id'));
    }

    public function testTooManyDataSourceIdsRequiresMatchingBaseSource(): void
    {
        $_ENV['NOTION_DATA_SOURCE_ID'] = '';
        $_ENV['NOTION_DATA_SOURCE_IDS'] = 'source-a,source-b,source-c';

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('defines only 2 base sources');

        require dirname(__DIR__) . '/app/config/app.php';
    }
}
