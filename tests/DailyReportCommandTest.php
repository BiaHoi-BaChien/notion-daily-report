<?php

declare(strict_types=1);

namespace Tests;

use App\DailyReportCommand;
use App\DateFilter;
use App\Logger;
use App\NotionClientInterface;
use App\PropertyExtractor;
use App\ReportBuilder;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class DailyReportCommandTest extends TestCase
{
    public function testRunsCliReportWithStubbedNotionClient(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';

        $command = new DailyReportCommand(
            $this->config(),
            new StubNotionClient([
                $this->page('Old task', '2026-04-15', '未着手'),
                $this->page('Today task', '2026-04-16', '未着手'),
                $this->page('Upcoming task', '2026-04-18', null),
            ]),
            new PropertyExtractor($timezone),
            new DateFilter($timezone),
            new ReportBuilder($timezone),
            new Logger($logPath, $timezone),
            $timezone,
            false
        );

        ob_start();
        $exitCode = $command->run(['daily_report.php', '--date=2026-04-16']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Notion Daily Report (2026-04-16)', $output);
        self::assertStringContainsString('## 期限超過', $output);
        self::assertStringContainsString('Old task', $output);
        self::assertStringContainsString('## 今日やること', $output);
        self::assertStringContainsString('Today task', $output);
        self::assertStringContainsString('## 近日中に準備したほうがいいこと', $output);
        self::assertStringContainsString('Upcoming task', $output);
        self::assertFileExists($logPath);
    }

    public function testInvalidDateReturnsNonZeroExitCode(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';

        $command = new DailyReportCommand(
            $this->config(),
            new StubNotionClient([]),
            new PropertyExtractor($timezone),
            new DateFilter($timezone),
            new ReportBuilder($timezone),
            new Logger($logPath, $timezone),
            $timezone,
            false
        );

        ob_start();
        $exitCode = $command->run(['daily_report.php', '--date=bad-date']);
        ob_end_clean();

        self::assertSame(1, $exitCode);
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'sources' => [
                [
                    'enabled' => true,
                    'name' => '個人ToDo',
                    'role' => '今日やるべき作業の確認',
                    'data_source_id' => 'source-id',
                    'date_property' => '期限',
                    'status_property' => 'ステータス',
                    'lookback_days' => 1,
                    'lookahead_days' => 3,
                    'exclude_statuses' => ['完了', '中止'],
                    'filter_property_ids' => [],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function page(string $title, string $date, ?string $status): array
    {
        return [
            'id' => strtolower(str_replace(' ', '-', $title)),
            'url' => 'https://notion.example/' . rawurlencode($title),
            'last_edited_time' => '2026-04-16T00:00:00.000Z',
            'properties' => [
                'Name' => [
                    'type' => 'title',
                    'title' => [
                        ['plain_text' => $title],
                    ],
                ],
                '期限' => [
                    'type' => 'date',
                    'date' => ['start' => $date],
                ],
                'ステータス' => [
                    'type' => 'status',
                    'status' => $status === null ? null : ['name' => $status],
                ],
            ],
        ];
    }
}

final class StubNotionClient implements NotionClientInterface
{
    /**
     * @param array<int, array<string, mixed>> $pages
     */
    public function __construct(private readonly array $pages)
    {
    }

    public function queryDataSource(string $dataSourceId, array $filterPropertyIds = []): array
    {
        return $this->pages;
    }
}
