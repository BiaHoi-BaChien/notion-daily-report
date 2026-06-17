<?php

declare(strict_types=1);

namespace Tests;

use App\DailyReportCommand;
use App\DateFilter;
use App\Exception\NotionApiException;
use App\Exception\OpenAIException;
use App\Logger;
use App\NotionClientInterface;
use App\OpenAIClientInterface;
use App\MailNotifierInterface;
use App\PropertyExtractor;
use App\ReportBuilder;
use App\SlackNotifierInterface;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DailyReportCommandTest extends TestCase
{
    public function testRunsCliReportWithStubbedNotionClient(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';
        $slack = new StubSlackNotifier();

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
            false,
            $slack
        );

        ob_start();
        $exitCode = $command->run(['daily_report.php', '--date=2026-04-16']);
        $output = (string) ob_get_clean();
        $report = (string) $slack->sentText;

        self::assertSame(0, $exitCode);
        self::assertSame('', $output);
        self::assertStringContainsString('🔥 今日の予定', $report);
        self::assertStringContainsString('Today task', $report);
        self::assertStringNotContainsString('Old task', $report);
        self::assertStringContainsString('📌 近日確認', $report);
        self::assertStringContainsString('Upcoming task', $report);
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
        $report = (string) $slack->sentText;

        self::assertSame(0, $exitCode);
        self::assertSame('', $output);
        self::assertStringContainsString('🔥 今日の予定', $report);
        self::assertStringContainsString('Today task', $report);
        self::assertStringContainsString('📌 近日確認', $report);
        self::assertStringContainsString('Meeting prep', $report);
    }

    public function testContinuesWhenOneSourceFails(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';
        $slack = new StubSlackNotifier();

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
            false,
            $slack
        );

        ob_start();
        $exitCode = $command->run(['daily_report.php', '--date=2026-04-16']);
        $output = (string) ob_get_clean();
        $report = (string) $slack->sentText;

        self::assertSame(0, $exitCode);
        self::assertSame('', $output);
        self::assertStringContainsString('Meeting prep', $report);
        self::assertStringNotContainsString('Source failed', $report);

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
        $slack = new StubSlackNotifier();

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
                'identity-source' => [
                    $this->identityDocumentPage('在留カード', '2026-05-01', '有効'),
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
        $exitCode = $command->run(['daily_report.php', '--date=2026-04-17']);
        $output = (string) ob_get_clean();
        $report = (string) $slack->sentText;

        self::assertSame(0, $exitCode);
        self::assertSame('', $output);
        self::assertStringContainsString('🔥 今日の予定', $report);
        self::assertStringContainsString('時間｜予定｜分類', $report);
        self::assertStringContainsString('09:30｜<https://notion.example/%E6%B1%BA%E6%B8%88%E7%A2%BA%E8%AA%8D|決済確認>｜決済システム', $report);
        self::assertStringContainsString('⚠ 本日期限', $report);
        self::assertStringContainsString('決済追加確認>｜決済システム', $report);
        self::assertStringContainsString('楽天ペイV2', $report);
        self::assertStringContainsString('楽天ペイ表示確認>｜楽天ペイV2', $report);
        self::assertStringContainsString('📌 近日確認', $report);
        self::assertStringContainsString('・10:00｜<https://notion.example/%E6%9D%A5%E9%80%B1%E3%81%AE%E7%A2%BA%E8%AA%8D|来週の確認>｜決済システム', $report);
        self::assertStringContainsString('・10:00 - 11:00｜<https://notion.example/MTG%20K%26G|MTG K&amp;G>', $report);
        self::assertStringNotContainsString('MTG K&G｜その他', $report);
        self::assertStringContainsString('・16:00｜<https://notion.example/%E5%A7%94%E5%93%A1%E4%BC%9A%E6%B4%BB%E5%8B%95|委員会活動>｜学校', $report);
        self::assertStringContainsString('💡 その他トピックス', $report);
        self::assertStringNotContainsString('  以下の通り祝日があります。', $report);
        self::assertStringContainsString('・<https://notion.example/%E3%83%99%E3%83%88%E3%83%8A%E3%83%A0%E6%9A%A6%E7%9A%84%E5%90%89%E6%97%A5|ベトナム暦的吉日>', $report);
        self::assertStringNotContainsString('ベトナム暦的吉日｜祝日', $report);
        self::assertStringContainsString('以下の身分証明書の有効期限が近づいています。', $report);
        self::assertStringContainsString('・<https://notion.example/%E5%9C%A8%E7%95%99%E3%82%AB%E3%83%BC%E3%83%89|在留カード>', $report);
        self::assertStringNotContainsString('在留カード｜身分証明書', $report);
        self::assertSame(1, substr_count($report, '在留カード'));
        self::assertStringContainsString('https://notion.example', $report);
    }

    public function testRendersTodayChildLunchInOtherTopics(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';
        $slack = new StubSlackNotifier();

        $command = new DailyReportCommand(
            $this->childLunchConfig(),
            new StubNotionClient([
                'child-lunch-source' => [
                    $this->childLunchPage('牛めし（A券：牛めし）', '2026-05-11', '月', 'S', 'つゆだく、ネギ抜き'),
                    $this->childLunchPage('', '2026-05-11', '月', '', ''),
                    $this->childLunchPage('カレー', '2026-05-12', '火', 'M', ''),
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
        $exitCode = $command->run(['daily_report.php', '--date=2026-05-11']);
        $output = (string) ob_get_clean();
        $report = (string) $slack->sentText;

        self::assertSame(0, $exitCode);
        self::assertSame('', $output);
        self::assertStringContainsString('💡 その他トピックス', $report);
        self::assertStringContainsString(
            '今日の子供のお弁当は「牛めし（A券：牛めし） [S] つゆだく、ネギ抜き」です。',
            $report
        );
        self::assertStringNotContainsString('05/11（月） 牛めし', $report);
        self::assertSame(1, substr_count($report, '今日の子供のお弁当は'));
        self::assertStringNotContainsString('カレー', $report);
    }

    public function testResolvesRelationProjectTitlesForGrouping(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';
        $slack = new StubSlackNotifier();

        $command = new DailyReportCommand(
            $this->structuredConfig(),
            new StubNotionClient(
                [
                    'todo-source' => [],
                    'task-source' => [
                        $this->projectTaskPageWithRelation('楽天ペイ表示確認', '2026-04-17', 'Testing', 'project-page-id'),
                    ],
                    'calendar-source' => [],
                    'identity-source' => [],
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
            false,
            $slack
        );

        ob_start();
        $exitCode = $command->run(['daily_report.php', '--date=2026-04-17']);
        $output = (string) ob_get_clean();
        $report = (string) $slack->sentText;

        self::assertSame(0, $exitCode);
        self::assertSame('', $output);
        self::assertStringContainsString('⚠ 本日期限', $report);
        self::assertStringContainsString('楽天ペイ表示確認>｜楽天ペイV2', $report);
        self::assertStringNotContainsString('楽天ペイ表示確認｜その他', $report);
    }

    public function testDeterminesSourceTypeOnlyFromSourceName(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $today = new DateTimeImmutable('2026-04-17', $timezone);
        $builder = new ReportBuilder($timezone);

        $items = $builder->classifyAndSort([
            $this->extractedItem('Exact todo', '2026-04-17', 'ToDo', 'unrelated role', '2026-04-17T09:00:00+07:00'),
            $this->extractedItem('Role-only todo', '2026-04-17', '別ソース', '今日やるべき作業の確認', '2026-04-17T10:00:00+07:00'),
            $this->extractedItem('Exact project task', '2026-04-17', '各案件のタスク', 'unrelated role', '2026-04-17'),
            $this->extractedItem('Role-only project task', '2026-04-17', '別ソース', '各案件ごとのタスクの確認', '2026-04-17'),
            $this->extractedItem('Role-only identity document', '2026-04-18', '別ソース', '期限切れが迫っている身分証明書の確認', '2026-04-18'),
        ], $today);

        $report = $builder->renderSchedule($items, $today);

        self::assertStringStartsWith('2026年04月17日（金）' . PHP_EOL, $report);
        self::assertStringContainsString('09:00｜Exact todo｜', $report);
        self::assertStringNotContainsString('Role-only todo', $report);
        self::assertStringContainsString('・Exact project task', $report);
        self::assertStringNotContainsString('Role-only project task', $report);
        self::assertStringContainsString('・Role-only identity document', $report);
        self::assertStringNotContainsString('Role-only identity document｜身分証明書', $report);
    }

    public function testRendersRelativeDateGroupLabelsForUpcomingAndOtherTopics(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $today = new DateTimeImmutable('2026-04-22', $timezone);
        $builder = new ReportBuilder($timezone);

        $holiday = $this->extractedItem('昭和の日', '2026-04-29', 'カレンダー', '今日以降1週間の予定の確認', '2026-04-29');
        $holiday['genre'] = '祝日';

        $items = $builder->classifyAndSort([
            $this->extractedItem('楽天ペイ未契約店舗の表示確認', '2026-04-23', 'ToDo', '今日やるべき作業の確認', '2026-04-23T18:00:00+07:00'),
            $this->extractedItem('Notion 新機能紹介ウェビナー', '2026-04-24', 'カレンダー', '今日以降1週間の予定の確認', '2026-04-24T10:00:00+07:00'),
            $holiday,
            $this->extractedItem('TECHCOMBANK(Debid Card)', '2026-06-01', '身分証明書', '期限切れが迫っている身分証明書の確認', '2026-06-01'),
        ], $today);

        $report = $builder->renderSchedule($items, $today);

        self::assertStringContainsString('⏰ 明日の時間付き予定', $report);
        self::assertStringContainsString('時間｜予定｜分類', $report);
        self::assertStringContainsString('18:00｜楽天ペイ未契約店舗の表示確認｜', $report);
        self::assertStringNotContainsString('楽天ペイ未契約店舗の表示確認｜その他', $report);
        self::assertStringContainsString('📌 近日確認', $report);
        self::assertStringNotContainsString('明日 04/23（木）', $report);
        self::assertStringContainsString('あさって 04/24（金）', $report);
        self::assertStringContainsString('・10:00｜Notion 新機能紹介ウェビナー', $report);
        self::assertStringContainsString('04/29（水）', $report);
        self::assertStringNotContainsString('7日後', $report);
        self::assertStringContainsString('・昭和の日', $report);
        self::assertStringNotContainsString('昭和の日｜祝日', $report);
        self::assertStringContainsString('💡 その他トピックス', $report);
        self::assertStringContainsString('以下の身分証明書の有効期限が近づいています。', $report);
        self::assertStringContainsString('06/01（月） あと40日', $report);
        self::assertStringContainsString('・TECHCOMBANK(Debid Card)', $report);
        self::assertStringNotContainsString('TECHCOMBANK(Debid Card)｜身分証明書', $report);
    }

    public function testRendersNotionPageLinksForSlackAndHtmlMail(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $today = new DateTimeImmutable('2026-04-22', $timezone);
        $builder = new ReportBuilder($timezone);

        $linked = $this->extractedItem('確認 & <連絡>', '2026-04-22', 'ToDo', '今日やるべき作業の確認', '2026-04-22T09:00:00+07:00');
        $linked['url'] = 'https://notion.example/page?a=1&b=2';
        $plain = $this->extractedItem('URLなし', '2026-04-22', 'ToDo', '今日やるべき作業の確認', '2026-04-22T10:00:00+07:00');
        $items = $builder->classifyAndSort([$linked, $plain], $today);

        $textReport = $builder->renderSchedule($items, $today);
        $slackReport = $builder->renderSchedule($items, $today, ReportBuilder::FORMAT_SLACK);
        $htmlReport = $builder->renderSchedule($items, $today, ReportBuilder::FORMAT_HTML);

        self::assertStringContainsString('09:00｜確認 & <連絡>｜', $textReport);
        self::assertStringContainsString('09:00｜<https://notion.example/page?a=1&b=2|確認 &amp; &lt;連絡&gt;>｜', $slackReport);
        self::assertStringContainsString(
            '<td><a href="https://notion.example/page?a=1&amp;b=2">確認 &amp; &lt;連絡&gt;</a></td>',
            $htmlReport
        );
        self::assertStringContainsString('10:00｜URLなし｜', $slackReport);
        self::assertStringContainsString('<td>URLなし</td>', $htmlReport);
    }

    public function testRendersReadableNotionBlocksWithoutDividers(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $today = new DateTimeImmutable('2026-04-22', $timezone);
        $builder = new ReportBuilder($timezone);

        $linked = $this->extractedItem('確認 & <連絡>', '2026-04-22', 'ToDo', '今日やるべき作業の確認', '2026-04-22T09:00:00+07:00');
        $linked['url'] = 'https://notion.example/page?a=1&b=2';
        $untimedToday = $this->extractedItem('今日の時間なし予定', '2026-04-22', 'ToDo', '今日やるべき作業の確認', '2026-04-22');
        $tomorrow = $this->extractedItem('明日の予定', '2026-04-23', 'ToDo', '今日やるべき作業の確認', '2026-04-23T18:00:00+07:00');
        $tomorrow['project'] = '翌日案件';
        $deadline = $this->extractedItem('期限タスク', '2026-04-22', '各案件のタスク', '各案件ごとのタスクの確認', '2026-04-22T18:00:00+07:00');
        $deadline['project'] = '決済システム';
        $upcoming = $this->extractedItem('来週の確認', '2026-04-24', 'カレンダー', '今日以降1週間の予定の確認', '2026-04-24T10:00:00+07:00');
        $upcoming['project'] = '案件A';
        $upcoming['url'] = 'https://notion.example/upcoming';
        $untimedUpcoming = $this->extractedItem('時間なし予定', '2026-04-24', 'カレンダー', '今日以降1週間の予定の確認', '2026-04-24');
        $identity = $this->extractedItem('TECHCOMBANK(Debid Card)', '2026-06-01', '身分証明書', '期限切れが迫っている身分証明書の確認', '2026-06-01');
        $items = $builder->classifyAndSort([$linked, $untimedToday, $tomorrow, $deadline, $upcoming, $untimedUpcoming, $identity], $today);

        $blocks = $builder->renderNotionBlocks('今日のコメント', $items, $today);
        $types = array_column($blocks, 'type');

        self::assertNotContains('divider', $types);
        self::assertNotContains('heading_1', $types);
        self::assertSame('callout', $blocks[0]['type']);
        self::assertSame('🤖', $blocks[0]['callout']['icon']['emoji']);
        self::assertContains('heading_2', $types);
        self::assertContains('heading_3', $types);
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'heading_2') {
                self::assertSame('yellow_background', $block['heading_2']['color']);
            }
        }

        $deadlineCallouts = array_values(array_filter(
            $blocks,
            static fn (array $block): bool => ($block['type'] ?? null) === 'callout'
                && (($block['callout']['rich_text'][0]['text']['content'] ?? null) === '期限タスク')
        ));
        self::assertSame([], $deadlineCallouts);

        $tables = array_values(array_filter(
            $blocks,
            static fn (array $block): bool => ($block['type'] ?? null) === 'table'
        ));
        self::assertNotEmpty($tables);
        self::assertSame(3, $tables[0]['table']['table_width']);
        self::assertFalse($tables[0]['table']['has_column_header']);

        $tableRows = [];
        foreach ($tables as $table) {
            foreach ($table['table']['children'] as $child) {
                $tableRows[] = $child['table_row']['cells'];
            }
        }

        $findTableRow = static function (array $rows, string $title): ?array {
            foreach ($rows as $row) {
                if (($row[1][0]['text']['content'] ?? null) === $title) {
                    return $row;
                }
            }

            return null;
        };

        $linkedRow = $findTableRow($tableRows, '確認 & <連絡>');
        self::assertNotNull($linkedRow);
        self::assertSame('09:00', $linkedRow[0][0]['text']['content']);
        self::assertSame('https://notion.example/page?a=1&b=2', $linkedRow[1][0]['text']['link']['url']);
        self::assertNull($findTableRow($tableRows, '今日の時間なし予定'));

        $tomorrowRow = $findTableRow($tableRows, '明日の予定');
        self::assertNotNull($tomorrowRow);
        self::assertSame('18:00', $tomorrowRow[0][0]['text']['content']);
        self::assertSame('翌日案件', $tomorrowRow[2][0]['text']['content']);

        $deadlineRow = $findTableRow($tableRows, '期限タスク');
        self::assertNotNull($deadlineRow);
        self::assertSame('18:00', $deadlineRow[0][0]['text']['content']);
        self::assertSame('決済システム', $deadlineRow[2][0]['text']['content']);

        $upcomingRow = $findTableRow($tableRows, '来週の確認');
        self::assertNotNull($upcomingRow);
        self::assertSame('10:00', $upcomingRow[0][0]['text']['content']);
        self::assertSame('https://notion.example/upcoming', $upcomingRow[1][0]['text']['link']['url']);
        self::assertSame('案件A', $upcomingRow[2][0]['text']['content']);
        self::assertNull($findTableRow($tableRows, '時間なし予定'));

        $untimedBulletIndex = null;
        $untimedTodayBulletIndex = null;
        $todayTableIndex = null;
        $upcomingTableIndex = null;
        foreach ($blocks as $index => $block) {
            if (($block['type'] ?? null) === 'bulleted_list_item'
                && (($block['bulleted_list_item']['rich_text'][0]['text']['content'] ?? null) === '時間なし予定')
            ) {
                $untimedBulletIndex = $index;
            }

            if (($block['type'] ?? null) === 'bulleted_list_item'
                && (($block['bulleted_list_item']['rich_text'][0]['text']['content'] ?? null) === '今日の時間なし予定')
            ) {
                $untimedTodayBulletIndex = $index;
            }

            if (($block['type'] ?? null) !== 'table') {
                continue;
            }

            foreach ($block['table']['children'] as $child) {
                if (($child['table_row']['cells'][1][0]['text']['content'] ?? null) === '確認 & <連絡>') {
                    $todayTableIndex = $index;
                }

                if (($child['table_row']['cells'][1][0]['text']['content'] ?? null) === '来週の確認') {
                    $upcomingTableIndex = $index;
                    break 2;
                }
            }
        }
        self::assertNotNull($untimedBulletIndex);
        self::assertNotNull($untimedTodayBulletIndex);
        self::assertNotNull($todayTableIndex);
        self::assertNotNull($upcomingTableIndex);
        self::assertLessThan($todayTableIndex, $untimedTodayBulletIndex);
        self::assertLessThan($upcomingTableIndex, $untimedBulletIndex);

        $identityCallouts = array_values(array_filter(
            $blocks,
            static fn (array $block): bool => ($block['type'] ?? null) === 'callout'
                && (($block['callout']['icon']['emoji'] ?? null) === '🪪')
        ));
        self::assertNotEmpty($identityCallouts);
        self::assertSame('heading_3', $identityCallouts[0]['callout']['children'][0]['type']);
        self::assertSame('bulleted_list_item', $identityCallouts[0]['callout']['children'][1]['type']);
        self::assertSame(
            'TECHCOMBANK(Debid Card)',
            $identityCallouts[0]['callout']['children'][1]['bulleted_list_item']['rich_text'][0]['text']['content']
        );
    }

    public function testRendersNotionEmptySectionsAsBulletedNoMatchMessage(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $today = new DateTimeImmutable('2026-04-22', $timezone);
        $builder = new ReportBuilder($timezone);
        $items = $builder->classifyAndSort([
            $this->extractedItem('今日の確認', '2026-04-22', 'ToDo', '今日やるべき作業の確認', '2026-04-22T09:00:00+07:00'),
        ], $today);

        $blocks = $builder->renderNotionBlocks(null, $items, $today);
        $nextBlockAfterHeading = static function (array $blocks, string $heading): ?array {
            foreach ($blocks as $index => $block) {
                if (($block['type'] ?? null) !== 'heading_2') {
                    continue;
                }

                if (($block['heading_2']['rich_text'][0]['text']['content'] ?? null) === $heading) {
                    return $blocks[$index + 1] ?? null;
                }
            }

            return null;
        };

        foreach (['⏰ 明日の時間付き予定', '⚠️ 本日期限', '📌 近日確認'] as $heading) {
            $block = $nextBlockAfterHeading($blocks, $heading);
            self::assertSame('bulleted_list_item', $block['type'] ?? null);
            self::assertSame('該当なし', $block['bulleted_list_item']['rich_text'][0]['text']['content'] ?? null);
        }
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
        $report = (string) $slack->sentText;

        self::assertSame(0, $exitCode);
        self::assertSame('', $output);
        self::assertStringContainsString('要約済みタスク', $report);
        self::assertStringContainsString('<https://notion.example/Today%20task|Today task>', $report);
        self::assertStringContainsString('<a href="https://notion.example/Today%20task">Today task</a>', (string) $mail->sentBody);
        self::assertStringContainsString('Today task', (string) $mail->sentPlainBody);
        self::assertStringNotContainsString('https://notion.example/Today%20task', (string) $mail->sentPlainBody);
        self::assertSame('Notion Daily Report 2026-04-16', $mail->sentSubject);
        self::assertStringContainsString('2026年04月16日（木）', $openai->receivedSchedule);
        self::assertStringContainsString('Today task', $openai->receivedSchedule);
        self::assertStringNotContainsString('https://notion.example/Today%20task', $openai->receivedSchedule);
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
        self::assertSame('', $output);
        self::assertSame('', $openai->receivedSchedule);
        self::assertNull($slack->sentText);
        self::assertNull($mail->sentBody);
        self::assertNull($mail->sentSubject);
    }

    public function testCreatesNotionReportWhenConfigured(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';
        $notion = new StubNotionClient([
            $this->page('Today task', '2026-04-16', '未着手'),
        ]);
        $config = $this->config();
        $config['notion_report'] = [
            'enabled' => true,
            'data_source_id' => 'report-source-id',
            'title_property' => 'レポート名',
            'date_property' => '対象日',
            'run_id_property' => 'Run ID',
        ];

        $command = new DailyReportCommand(
            $config,
            $notion,
            new PropertyExtractor($timezone),
            new DateFilter($timezone),
            new ReportBuilder($timezone),
            new Logger($logPath, $timezone),
            $timezone,
            false,
            null,
            new StubOpenAIClient('OpenAIコメント')
        );

        ob_start();
        $exitCode = $command->run(['daily_report.php', '--date=2026-04-16']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exitCode);
        self::assertSame('', $output);
        self::assertCount(1, $notion->createdPages);
        self::assertSame('report-source-id', $notion->createdPages[0]['data_source_id']);
        self::assertSame('Notion Daily Report 2026-04-16', $notion->createdPages[0]['properties']['レポート名']['title'][0]['text']['content']);
        self::assertSame('2026-04-16', $notion->createdPages[0]['properties']['対象日']['date']['start']);
        self::assertArrayHasKey('Run ID', $notion->createdPages[0]['properties']);
        self::assertSame('callout', $notion->createdPages[0]['children'][0]['type']);
        self::assertSame('🤖', $notion->createdPages[0]['children'][0]['callout']['icon']['emoji']);

        $log = (string) file_get_contents($logPath);
        self::assertStringContainsString('notion_report_created', $log);
        self::assertStringContainsString('"notion_report_status":"sent"', $log);
        self::assertStringContainsString('"notion_report_page_url":"https://notion.example/created-report"', $log);
    }

    public function testContinuesWhenNotionReportCreationFails(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';
        $mail = new StubMailNotifier();
        $config = $this->config();
        $config['notion_report'] = [
            'enabled' => true,
            'data_source_id' => 'report-source-id',
            'title_property' => 'Name',
            'date_property' => 'Date',
        ];

        $command = new DailyReportCommand(
            $config,
            new StubNotionClient(
                [$this->page('Today task', '2026-04-16', '未着手')],
                [],
                new NotionApiException('Notion API request failed: json_encode error: Malformed UTF-8 characters, possibly incorrectly encoded')
            ),
            new PropertyExtractor($timezone),
            new DateFilter($timezone),
            new ReportBuilder($timezone),
            new Logger($logPath, $timezone),
            $timezone,
            false,
            null,
            null,
            $mail
        );

        ob_start();
        $exitCode = $command->run(['daily_report.php', '--date=2026-04-16']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exitCode);
        self::assertSame('', $output);
        self::assertCount(2, $mail->sentMessages);
        self::assertSame('Notion Daily Report failed 2026-04-16', $mail->sentMessages[0]['subject']);
        self::assertStringContainsString('Notionページ作成に失敗しました。', $mail->sentMessages[0]['body']);
        self::assertStringContainsString('exception_class: App\Exception\NotionApiException', $mail->sentMessages[0]['body']);
        self::assertStringContainsString('Malformed UTF-8 characters', $mail->sentMessages[0]['body']);
        self::assertStringContainsString('REPORT_NOTION_DATA_SOURCE_ID: report-source-id', $mail->sentMessages[0]['body']);
        self::assertStringContainsString('notion_block_count:', $mail->sentMessages[0]['body']);
        self::assertStringContainsString('通常のmailレポート送信は継続します。', $mail->sentMessages[0]['body']);
        self::assertSame('Notion Daily Report 2026-04-16', $mail->sentMessages[1]['subject']);
        self::assertStringContainsString('Today task', (string) $mail->sentBody);

        $log = (string) file_get_contents($logPath);
        self::assertStringContainsString('notion_report_failed', $log);
        self::assertStringContainsString('"block_count":', $log);
        self::assertStringContainsString('notion_report_failure_mail_sent', $log);
        self::assertStringContainsString('"notion_report_status":"failed"', $log);
        self::assertStringContainsString('"mail_status":"sent"', $log);
    }

    public function testCompletesWhenNotionReportFailureMailFails(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';
        $config = $this->config();
        $config['notion_report'] = [
            'enabled' => true,
            'data_source_id' => 'report-source-id',
            'title_property' => 'Name',
            'date_property' => 'Date',
        ];

        $command = new DailyReportCommand(
            $config,
            new StubNotionClient(
                [$this->page('Today task', '2026-04-16', '未着手')],
                [],
                new RuntimeException('Notion write failed.')
            ),
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
        self::assertSame('', $output);

        $log = (string) file_get_contents($logPath);
        self::assertStringContainsString('notion_report_failed', $log);
        self::assertStringContainsString('notion_report_failure_mail_failed', $log);
        self::assertStringContainsString('mail_notification_failed', $log);
        self::assertStringContainsString('"notion_report_status":"failed"', $log);
        self::assertStringContainsString('"mail_status":"failed"', $log);
    }

    public function testNotionReportChunksLongUtf8TextWithoutBreakingJsonEncoding(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';
        $notion = new StubNotionClient([
            $this->page('A' . str_repeat('あ', 700), '2026-04-16', '未着手'),
        ]);
        $config = $this->config();
        $config['notion_report'] = [
            'enabled' => true,
            'data_source_id' => 'report-source-id',
            'title_property' => 'Name',
            'date_property' => 'Date',
        ];

        $command = new DailyReportCommand(
            $config,
            $notion,
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
        self::assertCount(1, $notion->createdPages);

        $encoded = json_encode($notion->createdPages[0]['children'], JSON_UNESCAPED_UNICODE);
        self::assertIsString($encoded);
        self::assertNotFalse($encoded);
        self::assertSame(JSON_ERROR_NONE, json_last_error());
        self::assertStringContainsString(str_repeat('あ', 10), $encoded);
    }

    public function testSkipsNotionReportWhenDisabled(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';
        $notion = new StubNotionClient([
            $this->page('Today task', '2026-04-16', '未着手'),
        ]);
        $config = $this->config();
        $config['notion_report'] = [
            'enabled' => false,
            'data_source_id' => 'report-source-id',
        ];

        $command = new DailyReportCommand(
            $config,
            $notion,
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
        self::assertSame([], $notion->createdPages);

        $log = (string) file_get_contents($logPath);
        self::assertStringContainsString('notion_report_skipped', $log);
        self::assertStringContainsString('"notion_report_status":"disabled"', $log);
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
        self::assertSame('', $output);
        self::assertStringContainsString('Today task', (string) $mail->sentBody);
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
        self::assertSame('', $output);

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
        self::assertStringContainsString('終日｜Today task｜決済システム', $openai->receivedSchedule);
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
        $report = (string) $slack->sentText;

        self::assertSame(0, $exitCode);
        self::assertSame('', $output);
        self::assertStringContainsString('お子様の学校の予定があります。', $report);
        self::assertStringContainsString('<https://notion.example/%E5%A7%94%E5%93%A1%E4%BC%9A%E6%B4%BB%E5%8B%95|委員会活動>', $report);
        self::assertStringContainsString(
            '<a href="https://notion.example/%E5%A7%94%E5%93%A1%E4%BC%9A%E6%B4%BB%E5%8B%95">委員会活動</a>',
            (string) $mail->sentBody
        );
        self::assertStringContainsString('委員会活動', (string) $mail->sentPlainBody);
        self::assertStringContainsString('委員会活動', $openai->receivedSchedule);
        self::assertStringContainsString('委員会活動｜学校', $openai->receivedSchedule);
    }

    public function testFallsBackToLocalReportWhenOpenAISummaryFails(): void
    {
        $timezone = new DateTimeZone('Asia/Saigon');
        $logPath = sys_get_temp_dir() . '/notion-daily-report-test-' . uniqid('', true) . '.log';
        $slack = new StubSlackNotifier();

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
            new FailingOpenAIClient()
        );

        ob_start();
        $exitCode = $command->run(['daily_report.php', '--date=2026-04-16']);
        $output = (string) ob_get_clean();
        $report = (string) $slack->sentText;

        self::assertSame(0, $exitCode);
        self::assertSame('', $output);
        self::assertStringContainsString('Today task', $report);
        self::assertStringContainsString('🔥 今日の予定', $report);
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
                    'name' => 'ToDo',
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
                [
                    'enabled' => true,
                    'name' => '身分証明書',
                    'role' => '期限切れが迫っている身分証明書の確認',
                    'data_source_id' => 'identity-source',
                    'date_property' => '有効期限',
                    'status_property' => '状態',
                    'genre_property' => '',
                    'lookback_days' => 0,
                    'lookahead_days' => 60,
                    'exclude_statuses' => ['無効'],
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
    private function childLunchConfig(): array
    {
        return [
            'sources' => [
                [
                    'enabled' => true,
                    'name' => '子供のお弁当',
                    'role' => '今日の子供のお弁当の献立確認',
                    'data_source_id' => 'child-lunch-source',
                    'date_property' => '日付',
                    'status_property' => '',
                    'title_property' => '品名',
                    'lookback_days' => 0,
                    'lookahead_days' => 0,
                    'exclude_statuses' => [],
                    'filter_property_ids' => [],
                    'extra_properties' => [
                        'weekday' => '曜日',
                        'size' => 'サイズ',
                        'note' => '備考',
                    ],
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
    private function identityDocumentPage(string $title, string $date, ?string $status): array
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
                '有効期限' => [
                    'type' => 'date',
                    'date' => ['start' => $date],
                ],
                '状態' => [
                    'type' => 'status',
                    'status' => $status === null ? null : ['name' => $status],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function childLunchPage(string $name, string $date, string $weekday, string $size, string $note): array
    {
        return [
            'id' => strtolower(rawurlencode($name)),
            'url' => 'https://notion.example/' . rawurlencode($name),
            'last_edited_time' => '2026-05-11T00:00:00.000Z',
            'properties' => [
                '品名' => [
                    'type' => 'title',
                    'title' => [
                        ['plain_text' => $name],
                    ],
                ],
                '日付' => [
                    'type' => 'date',
                    'date' => ['start' => $date],
                ],
                '曜日' => [
                    'type' => 'rich_text',
                    'rich_text' => [
                        ['plain_text' => $weekday],
                    ],
                ],
                'サイズ' => [
                    'type' => 'select',
                    'select' => ['name' => $size],
                ],
                '備考' => [
                    'type' => 'rich_text',
                    'rich_text' => $note === '' ? [] : [
                        ['plain_text' => $note],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractedItem(string $title, string $date, string $sourceName, string $sourceRole, string $dateStart): array
    {
        return [
            'title' => $title,
            'date' => $date,
            'date_start' => $dateStart,
            'date_has_time' => str_contains($dateStart, 'T'),
            'date_end' => null,
            'date_end_has_time' => false,
            'status' => null,
            'project' => '',
            'genre' => '',
            'source_name' => $sourceName,
            'source_role' => $sourceRole,
        ];
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
     * @var array<int, array<string, mixed>>
     */
    public array $createdPages = [];

    /**
     * @param array<int, array<string, mixed>> $pages
     * @param array<string, array<string, mixed>> $relatedPages
     */
    public function __construct(
        private readonly array $pages,
        private readonly array $relatedPages = [],
        private readonly ?RuntimeException $createException = null
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

    public function createPage(string $dataSourceId, array $properties, array $children = []): array
    {
        if ($this->createException !== null) {
            throw $this->createException;
        }

        $this->createdPages[] = [
            'data_source_id' => $dataSourceId,
            'properties' => $properties,
            'children' => $children,
        ];

        return [
            'id' => 'created-report',
            'url' => 'https://notion.example/created-report',
        ];
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
    public ?string $sentPlainBody = null;
    public bool $sentBodyIsHtml = false;
    /** @var array<int, array{subject: string, body: string, plain_body: ?string, body_is_html: bool}> */
    public array $sentMessages = [];

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $subject, string $body, ?string $plainBody = null, bool $bodyIsHtml = false): void
    {
        $this->sentSubject = $subject;
        $this->sentBody = $body;
        $this->sentPlainBody = $plainBody;
        $this->sentBodyIsHtml = $bodyIsHtml;
        $this->sentMessages[] = [
            'subject' => $subject,
            'body' => $body,
            'plain_body' => $plainBody,
            'body_is_html' => $bodyIsHtml,
        ];
    }
}

final class FailingMailNotifier implements MailNotifierInterface
{
    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $subject, string $body, ?string $plainBody = null, bool $bodyIsHtml = false): void
    {
        throw new RuntimeException('SMTP is unavailable.');
    }
}
