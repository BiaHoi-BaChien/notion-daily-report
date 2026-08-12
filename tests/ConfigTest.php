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
            'NOTION_WEIGHT_DATA_SOURCE_ID',
            'NOTION_STEPS_DATA_SOURCE_ID',
            'NOTION_VITAL_DATA_SOURCE_ID',
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
            self::assertTrue($config['sources'][0]['include_active_ranges']);
        } finally {
            foreach ($keys as $key) {
                unset($_ENV[$key]);
            }
            $_ENV += $original;
        }
    }

    public function testMapsHealthDataSourcesAndPropertyNamesFromEnvironment(): void
    {
        $values = [
            'NOTION_TODO_DATA_SOURCE_ID' => '',
            'NOTION_PROJECT_TASK_DATA_SOURCE_ID' => '',
            'NOTION_CALENDAR_DATA_SOURCE_ID' => '',
            'NOTION_ID_DOCUMENT_DATA_SOURCE_ID' => '',
            'NOTION_CHILD_LUNCH_DATA_SOURCE_ID' => '',
            'BIRTHDAY_NOTION_DATA_SOURCE_ID' => '',
            'NOTION_WEIGHT_DATA_SOURCE_ID' => 'weight-source',
            'NOTION_WEIGHT_DATE_PROPERTY' => '測定日',
            'NOTION_WEIGHT_VALUE_PROPERTY' => '重量',
            'NOTION_STEPS_DATA_SOURCE_ID' => 'steps-source',
            'NOTION_STEPS_DATE_PROPERTY' => '記録日',
            'NOTION_STEPS_VALUE_PROPERTY' => '歩数値',
            'NOTION_VITAL_DATA_SOURCE_ID' => 'vital-source',
            'NOTION_VITAL_DATE_PROPERTY' => '計測日',
            'NOTION_VITAL_SYSTOLIC_PROPERTY' => '上',
            'NOTION_VITAL_DIASTOLIC_PROPERTY' => '下',
            'NOTION_VITAL_PULSE_PROPERTY' => '心拍',
        ];
        $original = array_intersect_key($_ENV, $values);

        try {
            $_ENV = array_merge($_ENV, $values);
            $config = require dirname(__DIR__) . '/app/config/app.php';

            self::assertSame(['体重', '歩数', 'バイタル'], array_column($config['sources'], 'name'));
            self::assertSame(['weight', 'steps', 'vital'], array_column($config['sources'], 'health_metric'));
            self::assertSame('測定日', $config['sources'][0]['date_property']);
            self::assertSame(['weight' => '重量'], $config['sources'][0]['number_properties']);
            self::assertSame(['steps' => '歩数値'], $config['sources'][1]['number_properties']);
            self::assertSame(
                ['systolic' => '上', 'diastolic' => '下', 'pulse' => '心拍'],
                $config['sources'][2]['number_properties']
            );
            self::assertSame([3, 3, 3], array_column($config['sources'], 'latest_results'));
        } finally {
            foreach (array_keys($values) as $key) {
                unset($_ENV[$key]);
            }
            $_ENV += $original;
        }
    }

    public function testEnvExampleDoesNotContainHealthDataSourceIds(): void
    {
        $example = (string) file_get_contents(dirname(__DIR__) . '/.env.example');

        self::assertStringContainsString('NOTION_WEIGHT_DATA_SOURCE_ID=', $example);
        self::assertStringContainsString('NOTION_STEPS_DATA_SOURCE_ID=', $example);
        self::assertStringContainsString('NOTION_VITAL_DATA_SOURCE_ID=', $example);
        self::assertStringNotContainsString('37e84dd4-00e3-8060-95e9-000bd24c6f26', $example);
        self::assertStringNotContainsString('36784dd4-00e3-8092-9ff9-000b5a49edec', $example);
        self::assertStringNotContainsString('ff8e22e8-33ff-443d-b214-916b96725aad', $example);
    }

    public function testSplitsOpenAIModelCandidates(): void
    {
        self::assertSame(
            ['gpt-4o-mini', 'gpt-4.1-mini', 'gpt-4o'],
            SourceConfigBuilder::splitCsv(' gpt-4o-mini, gpt-4.1-mini,,gpt-4o ')
        );
    }
}
