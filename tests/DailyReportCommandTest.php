<?php

declare(strict_types=1);

namespace Tests;

use App\DailyReportCommand;
use App\DateFilter;
use App\Logger;
use App\NotionClientInterface;
use App\OpenAIClientInterface;
use App\MailNotifierInterface;
use App\PropertyExtractor;
use App\ReportBuilder;
use App\SlackNotifierInterface;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

    public function testProcessesMultipleSourcesAndSendsSlackReport(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';
        $slack = new StubSlackNotifier();

        $command = new DailyReportCommand(
            $this->multiSourceConfig(),
            new StubNotionClient([
                'source-1' => [
                    $this->page('Today task', '2026-04-16', '未着手'),
                ],
                'source-2' => [
                    $this->page('Meeting prep', '2026-04-18', null),
                ],
            ]),
            new PropertyExtractor($timezone),
            new DateFilter($timezone),
            new ReportBuilder($timezone),
            new Logger($logPath, $timezone),
            $timezone,
            false,
            $slack
        );

        ob_start();
        $exitCode = $command->run(['daily_report.php', '--date=2026-04-16']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('[個人ToDo] Today task', $output);
        self::assertStringContainsString('[会議予定] Meeting prep', $output);
        self::assertSame($output, $slack->sentText);
    }

    public function testContinuesWhenOneSourceFails(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';

        $command = new DailyReportCommand(
            $this->multiSourceConfig(),
            new StubNotionClient([
                'source-1' => new RuntimeException('Source failed'),
                'source-2' => [
                    $this->page('Meeting prep', '2026-04-18', null),
                ],
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
        self::assertStringContainsString('Meeting prep', $output);
        self::assertStringNotContainsString('Source failed', $output);
    }

    public function testUsesOpenAISummaryForSlackAndMailWhenConfigured(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';
        $slack = new StubSlackNotifier();
        $mail = new StubMailNotifier();
        $openai = new StubOpenAIClient('1. 今日やること' . PHP_EOL . '- 要約済みタスク');

        $command = new DailyReportCommand(
            $this->config(),
            new StubNotionClient([
                $this->page('Today task', '2026-04-16', '未着手'),
            ]),
            new PropertyExtractor($timezone),
            new DateFilter($timezone),
            new ReportBuilder($timezone),
            new Logger($logPath, $timezone),
            $timezone,
            false,
            $slack,
            $openai,
            $mail
        );

        ob_start();
        $exitCode = $command->run(['daily_report.php', '--date=2026-04-16']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('要約済みタスク', $output);
        self::assertStringNotContainsString('Today task', $output);
        self::assertSame($output, $slack->sentText);
        self::assertSame($output, $mail->sentBody);
        self::assertSame('Notion Daily Report 2026-04-16', $mail->sentSubject);
        self::assertSame('Today task', $openai->receivedItems[0]['title']);
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
    private function multiSourceConfig(): array
    {
        $config = $this->config();
        $config['sources'][0]['data_source_id'] = 'source-1';
        $config['sources'][] = [
            'enabled' => true,
            'name' => '会議予定',
            'role' => '直近の会議準備',
            'data_source_id' => 'source-2',
            'date_property' => '期限',
            'status_property' => null,
            'lookback_days' => 0,
            'lookahead_days' => 7,
            'exclude_statuses' => [],
            'filter_property_ids' => [],
        ];

        return $config;
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
        if (array_is_list($this->pages)) {
            return $this->pages;
        }

        $result = $this->pages[$dataSourceId] ?? [];
        if ($result instanceof RuntimeException) {
            throw $result;
        }

        return $result;
    }
}

final class StubSlackNotifier implements SlackNotifierInterface
{
    public ?string $sentText = null;

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $text): void
    {
        $this->sentText = $text;
    }
}

final class StubOpenAIClient implements OpenAIClientInterface
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $receivedItems = [];

    public function __construct(private readonly string $summary)
    {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function summarize(array $items): string
    {
        $this->receivedItems = $items;
        return $this->summary;
    }
}

final class StubMailNotifier implements MailNotifierInterface
{
    public ?string $sentSubject = null;
    public ?string $sentBody = null;

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $subject, string $body): void
    {
        $this->sentSubject = $subject;
        $this->sentBody = $body;
    }
}
