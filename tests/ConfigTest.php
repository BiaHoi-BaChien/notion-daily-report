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

    public function testBuildsSourcesFromCommaSeparatedDataSourceIds(): void
    {
        $_ENV['NOTION_DATA_SOURCE_IDS'] = 'source-a, source-b,, source-c ';
        $_ENV['NOTION_DATA_SOURCE_ID'] = '';

        $config = require dirname(__DIR__) . '/app/config/app.php';

        self::assertSame(['source-a', 'source-b', 'source-c'], array_column($config['sources'], 'data_source_id'));
        self::assertSame(['ToDo 1', 'ToDo 2', 'ToDo 3'], array_column($config['sources'], 'name'));
        self::assertSame('いつまでに', $config['sources'][0]['date_property']);
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
}
