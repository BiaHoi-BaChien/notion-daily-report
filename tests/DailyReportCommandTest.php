<?php

declare(strict_types=1);

namespace Tests;

use App\DailyReportCommand;
use App\DateFilter;
use App\Exception\OpenAIException;
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
        self::assertStringContainsString('1. 今日確認するべきToDo', $output);
        self::assertStringContainsString('Today task', $output);
        self::assertStringNotContainsString('Old task', $output);
        self::assertStringContainsString('3. 近日中に確認が必要なこと', $output);
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
        self::assertStringContainsString('1. 今日確認するべきToDo', $output);
        self::assertStringContainsString('Today task', $output);
        self::assertStringContainsString('3. 近日中に確認が必要なこと', $output);
        self::assertStringContainsString('Meeting prep', $output);
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

        $log = (string) file_get_contents($logPath);
        self::assertStringContainsString('source_processing_failed', $log);
        self::assertStringContainsString('source_processing_complete', $log);
        self::assertStringContainsString('"successful_source_count":1', $log);
        self::assertStringContainsString('"failed_source_count":1', $log);
        self::assertStringContainsString('"classification_counts"', $log);
    }

    public function testRendersStructuredPhpReportWithProjectsCalendarAndHoliday(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';

        $command = new DailyReportCommand(
            $this->structuredConfig(),
            new StubNotionClient([
                'todo-source' => [
                    $this->pageWithProject('決済確認', '2026-04-17T09:30:00+07:00', '未着手', '決済システム'),
                ],
                'task-source' => [
                    $this->projectTaskPage('楽天ペイ表示確認', '2026-04-17', 'Testing', '楽天ペイV2'),
                    $this->projectTaskPage('決済追加確認', '2026-04-17', 'Testing', '決済システム'),
                    $this->projectTaskPage('来週の確認', '2026-04-20T10:00:00+07:00', 'Testing', '決済システム'),
                ],
                'calendar-source' => [
                    $this->calendarPageWithEnd('MTG K&G', '2026-04-20T10:00:00+07:00', '2026-04-20T11:00:00+07:00', '仕事'),
                    $this->calendarPage('委員会活動', '2026-04-20T16:00:00+07:00', '学校'),
                    $this->calendarPage('ベトナム暦的吉日', '2026-04-21', '祝日'),
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
        $exitCode = $command->run(['daily_report.php', '--date=2026-04-17']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('1. 今日確認するべきToDo', $output);
        self::assertStringContainsString('【09:30】決済確認 | 決済システム', $output);
        self::assertStringContainsString('2. 今日が期限の案件のタスク', $output);
        self::assertStringContainsString('【】決済追加確認 | 決済システム', $output);
        self::assertStringContainsString('楽天ペイV2', $output);
        self::assertStringContainsString('楽天ペイ表示確認 | 楽天ペイV2', $output);
        self::assertStringContainsString('3. 近日中に確認が必要なこと', $output);
        self::assertStringContainsString('【04/20 10:00】来週の確認 | 決済システム', $output);
        self::assertStringContainsString('】来週の確認 | 決済システム', $output);
        self::assertStringContainsString('【04/20 10:00 - 11:00】MTG K&G | その他', $output);
        self::assertStringContainsString('【04/20 16:00】委員会活動 | 学校', $output);
        self::assertStringContainsString('4. その他トピックス', $output);
        self::assertStringContainsString('  以下の通り祝日があります。', $output);
        self::assertStringContainsString('    4月21日 ベトナム暦的吉日', $output);
        self::assertStringNotContainsString('https://notion.example', $output);
    }

    public function testResolvesRelationProjectTitlesForGrouping(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';

        $command = new DailyReportCommand(
            $this->structuredConfig(),
            new StubNotionClient(
                [
                    'todo-source' => [],
                    'task-source' => [
                        $this->projectTaskPageWithRelation('楽天ペイ表示確認', '2026-04-17', 'Testing', 'project-page-id'),
                    ],
                    'calendar-source' => [],
                ],
                [
                    'project-page-id' => $this->relatedPage('楽天ペイV2'),
                ]
            ),
            new PropertyExtractor($timezone),
            new DateFilter($timezone),
            new ReportBuilder($timezone),
            new Logger($logPath, $timezone),
            $timezone,
            false
        );

        ob_start();
        $exitCode = $command->run(['daily_report.php', '--date=2026-04-17']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('2. 今日が期限の案件のタスク', $output);
        self::assertStringContainsString('楽天ペイ表示確認 | 楽天ペイV2', $output);
        self::assertStringNotContainsString('楽天ペイ表示確認 | その他', $output);
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
        self::assertStringContainsString('Today task', $output);
        self::assertSame($output, $slack->sentText);
        self::assertSame($output, $mail->sentBody);
        self::assertSame('Notion Daily Report 2026-04-16', $mail->sentSubject);
        self::assertStringContainsString('Today task', $openai->receivedSchedule);
    }

    public function testSwitchesDisableOpenAISlackAndMail(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';
        $slack = new StubSlackNotifier();
        $mail = new StubMailNotifier();
        $openai = new StubOpenAIClient('AI comment should not be used.');
        $config = $this->config();
        $config['openai'] = ['enabled' => false];
        $config['slack'] = ['enabled' => false];
        $config['mail'] = ['enabled' => false];

        $command = new DailyReportCommand(
            $config,
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
        self::assertStringContainsString('Today task', $output);
        self::assertStringNotContainsString('AI comment should not be used.', $output);
        self::assertSame('', $openai->receivedSchedule);
        self::assertNull($slack->sentText);
        self::assertNull($mail->sentBody);
        self::assertNull($mail->sentSubject);
    }

    public function testContinuesMailDeliveryWhenSlackNotificationFails(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';
        $mail = new StubMailNotifier();

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
            new FailingSlackNotifier(),
            null,
            $mail
        );

        ob_start();
        $exitCode = $command->run(['daily_report.php', '--date=2026-04-16']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Today task', $output);
        self::assertSame($output, $mail->sentBody);
        self::assertSame('Notion Daily Report 2026-04-16', $mail->sentSubject);

        $log = (string) file_get_contents($logPath);
        self::assertStringContainsString('slack_notification_failed', $log);
        self::assertStringContainsString('mail_notification_sent', $log);
        self::assertStringContainsString('"slack_status":"failed"', $log);
        self::assertStringContainsString('"mail_status":"sent"', $log);
    }

    public function testCompletesWhenMailNotificationFails(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';

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
            null,
            null,
            new FailingMailNotifier()
        );

        ob_start();
        $exitCode = $command->run(['daily_report.php', '--date=2026-04-16']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Today task', $output);

        $log = (string) file_get_contents($logPath);
        self::assertStringContainsString('mail_notification_failed', $log);
        self::assertStringContainsString('"mail_status":"failed"', $log);
        self::assertStringContainsString('daily_report_end', $log);
    }

    public function testLogsOperationalSummaryForSuccessfulRun(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';

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
            false
        );

        ob_start();
        $exitCode = $command->run(['daily_report.php', '--date=2026-04-16']);
        ob_end_clean();

        self::assertSame(0, $exitCode);

        $log = (string) file_get_contents($logPath);
        self::assertStringContainsString('source_processing_start', $log);
        self::assertStringContainsString('source_processing_complete', $log);
        self::assertStringContainsString('"date_filter":{"property":"期限","on_or_after":"2026-04-15","on_or_before":"2026-04-19"}', $log);
        self::assertStringContainsString('"classification_counts":{"overdue":0,"today":1,"upcoming":0,"recent_past":0}', $log);
        self::assertStringContainsString('"duration_ms"', $log);
        self::assertStringContainsString('"report_size_bytes"', $log);
    }

    public function testPassesProjectToOpenAISummary(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';
        $openai = new StubOpenAIClient('1. 今日の概要' . PHP_EOL . '- 決済システムの確認があります。');
        $config = $this->config();
        $config['sources'][0]['project_property'] = '関連プロジェクト';

        $command = new DailyReportCommand(
            $config,
            new StubNotionClient([
                $this->pageWithProject('Today task', '2026-04-16', '未着手', '決済システム'),
            ]),
            new PropertyExtractor($timezone),
            new DateFilter($timezone),
            new ReportBuilder($timezone),
            new Logger($logPath, $timezone),
            $timezone,
            false,
            null,
            $openai
        );

        ob_start();
        $exitCode = $command->run(['daily_report.php', '--date=2026-04-16']);
        ob_end_clean();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('決済システム', $openai->receivedSchedule);
    }

    public function testPassesCalendarGenreToOpenAISummary(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';
        $slack = new StubSlackNotifier();
        $mail = new StubMailNotifier();
        $openai = new StubOpenAIClient('1. 今日の概要' . PHP_EOL . '- お子様の学校の予定があります。');

        $command = new DailyReportCommand(
            $this->calendarConfig(),
            new StubNotionClient([
                $this->calendarPage('委員会活動', '2026-04-20', '学校'),
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
        $exitCode = $command->run(['daily_report.php', '--date=2026-04-17']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('お子様の学校の予定があります。', $output);
        self::assertSame($output, $slack->sentText);
        self::assertSame($output, $mail->sentBody);
        self::assertStringContainsString('委員会活動', $openai->receivedSchedule);
        self::assertStringContainsString('委員会活動 | 学校', $openai->receivedSchedule);
    }

    public function testFallsBackToLocalReportWhenOpenAISummaryFails(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';

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
            null,
            new FailingOpenAIClient()
        );

        ob_start();
        $exitCode = $command->run(['daily_report.php', '--date=2026-04-16']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Today task', $output);
        self::assertStringContainsString('1. 今日確認するべきToDo', $output);
        self::assertStringContainsString('openai_summary_failed', (string) file_get_contents($logPath));
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
    private function structuredConfig(): array
    {
        return [
            'sources' => [
                [
                    'enabled' => true,
                    'name' => 'ToDo',
                    'role' => '今日やるべき作業の確認',
                    'data_source_id' => 'todo-source',
                    'date_property' => '期限',
                    'status_property' => 'ステータス',
                    'project_property' => '関連プロジェクト',
                    'lookback_days' => 0,
                    'lookahead_days' => 3,
                    'exclude_statuses' => ['完了', '中止'],
                    'filter_property_ids' => [],
                ],
                [
                    'enabled' => true,
                    'name' => '各案件のタスク',
                    'role' => '各案件ごとのタスクの確認',
                    'data_source_id' => 'task-source',
                    'date_property' => 'By when',
                    'status_property' => 'Status',
                    'project_property' => 'Projects',
                    'lookback_days' => 0,
                    'lookahead_days' => 5,
                    'exclude_statuses' => ['Release', 'Archived'],
                    'filter_property_ids' => [],
                ],
                [
                    'enabled' => true,
                    'name' => 'カレンダー',
                    'role' => '今日以降1週間の予定の確認',
                    'data_source_id' => 'calendar-source',
                    'date_property' => 'Date',
                    'status_property' => null,
                    'genre_property' => 'ジャンル',
                    'lookback_days' => 0,
                    'lookahead_days' => 7,
                    'exclude_statuses' => [],
                    'filter_property_ids' => [],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function calendarConfig(): array
    {
        return [
            'sources' => [
                [
                    'enabled' => true,
                    'name' => 'カレンダー',
                    'role' => '今日以降1週間の予定の確認',
                    'data_source_id' => 'calendar-source',
                    'date_property' => 'Date',
                    'status_property' => null,
                    'genre_property' => 'ジャンル',
                    'lookback_days' => 0,
                    'lookahead_days' => 7,
                    'exclude_statuses' => [],
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

    /**
     * @return array<string, mixed>
     */
    private function pageWithProject(string $title, string $date, ?string $status, string $project): array
    {
        $page = $this->page($title, $date, $status);
        $page['properties']['関連プロジェクト'] = [
            'type' => 'rich_text',
            'rich_text' => [
                ['plain_text' => $project],
            ],
        ];

        return $page;
    }

    /**
     * @return array<string, mixed>
     */
    private function calendarPage(string $title, string $date, string $genre): array
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
                'Date' => [
                    'type' => 'date',
                    'date' => ['start' => $date],
                ],
                'ジャンル' => [
                    'type' => 'select',
                    'select' => ['name' => $genre],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function calendarPageWithEnd(string $title, string $start, string $end, string $genre): array
    {
        $page = $this->calendarPage($title, $start, $genre);
        $page['properties']['Date']['date']['end'] = $end;

        return $page;
    }

    /**
     * @return array<string, mixed>
     */
    private function projectTaskPage(string $title, string $date, ?string $status, string $project): array
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
                'By when' => [
                    'type' => 'date',
                    'date' => ['start' => $date],
                ],
                'Status' => [
                    'type' => 'status',
                    'status' => $status === null ? null : ['name' => $status],
                ],
                'Projects' => [
                    'type' => 'rich_text',
                    'rich_text' => [
                        ['plain_text' => $project],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectTaskPageWithRelation(string $title, string $date, ?string $status, string $projectPageId): array
    {
        $page = $this->projectTaskPage($title, $date, $status, '');
        $page['properties']['Projects'] = [
            'type' => 'relation',
            'relation' => [
                ['id' => $projectPageId],
            ],
        ];

        return $page;
    }

    /**
     * @return array<string, mixed>
     */
    private function relatedPage(string $title): array
    {
        return [
            'id' => strtolower(str_replace(' ', '-', $title)),
            'properties' => [
                'Name' => [
                    'type' => 'title',
                    'title' => [
                        ['plain_text' => $title],
                    ],
                ],
            ],
        ];
    }
}

final class StubNotionClient implements NotionClientInterface
{
    /**
     * @param array<int, array<string, mixed>> $pages
     * @param array<string, array<string, mixed>> $relatedPages
     */
    public function __construct(
        private readonly array $pages,
        private readonly array $relatedPages = []
    )
    {
    }

    public function queryDataSource(string $dataSourceId, array $filterPropertyIds = [], array $filter = []): array
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

    public function retrievePage(string $pageId): array
    {
        return $this->relatedPages[$pageId] ?? [];
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

final class FailingSlackNotifier implements SlackNotifierInterface
{
    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $text): void
    {
        throw new RuntimeException('Slack is unavailable.');
    }
}

final class StubOpenAIClient implements OpenAIClientInterface
{
    public string $receivedSchedule = '';

    public function __construct(private readonly string $summary)
    {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function summarize(string $schedule): string
    {
        $this->receivedSchedule = $schedule;
        return $this->summary;
    }
}

final class FailingOpenAIClient implements OpenAIClientInterface
{
    public function isConfigured(): bool
    {
        return true;
    }

    public function summarize(string $schedule): string
    {
        throw new OpenAIException('OpenAI model is not available.');
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

final class FailingMailNotifier implements MailNotifierInterface
{
    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $subject, string $body): void
    {
        throw new RuntimeException('SMTP is unavailable.');
    }
}
