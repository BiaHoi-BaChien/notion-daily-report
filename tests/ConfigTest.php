<?php

declare(strict_types=1);

namespace Tests;

use App\SourceConfigBuilder;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testEachDataSourceCanBeConfiguredIndependently(): void
    {
        $keys = [
            'NOTION_TODO_DATA_SOURCE_ID',
            'NOTION_PROJECT_TASK_DATA_SOURCE_ID',
            'NOTION_CALENDAR_DATA_SOURCE_ID',
            'NOTION_ID_DOCUMENT_DATA_SOURCE_ID',
            'NOTION_CHILD_LUNCH_DATA_SOURCE_ID',
            'BIRTHDAY_NOTION_DATA_SOURCE_ID',
        ];
        $original = array_intersect_key($_ENV, array_flip($keys));

        try {
            foreach ($keys as $key) {
                $_ENV[$key] = '';
            }
            $_ENV['NOTION_CALENDAR_DATA_SOURCE_ID'] = 'calendar-source';

            $config = require dirname(__DIR__) . '/app/config/app.php';

            self::assertSame(['カレンダー'], array_column($config['sources'], 'name'));
            self::assertSame(['calendar-source'], array_column($config['sources'], 'data_source_id'));
        } finally {
            foreach ($keys as $key) {
                unset($_ENV[$key]);
            }
            $_ENV += $original;
        }
    }

    public function testSplitsOpenAIModelCandidates(): void
    {
        self::assertSame(
            ['gpt-4o-mini', 'gpt-4.1-mini', 'gpt-4o'],
            SourceConfigBuilder::splitCsv(' gpt-4o-mini, gpt-4.1-mini,,gpt-4o ')
        );
    }
}
