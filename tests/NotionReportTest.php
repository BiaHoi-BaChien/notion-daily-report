<?php

declare(strict_types=1);

namespace Tests;

use App\DailyReportCommand;
use App\DateFilter;
use App\Logger;
use App\MailNotifierInterface;
use App\NotionClientInterface;
use App\OpenAIClientInterface;
use App\PropertyExtractor;
use App\ReportBuilder;
use App\SlackNotifierInterface;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NotionReportTest extends TestCase
{
    public function testMarkdownPreservesLinksEscapesSourceTextAndUsesIconlessCallouts(): void
    {
        $tz = new DateTimeZone('Asia/Saigon');
        $today = new DateTimeImmutable('2026-10-29', $tz);
        $builder = new ReportBuilder($tz);
        $item = ['title' => '[試験] **太字ではない** <タグ> & 確認', 'source_name' => 'ToDo', 'date' => '2026-10-29', 'date_start' => '2026-10-29T09:00:00+07:00', 'date_has_time' => true, 'project' => '案件A', 'url' => 'https://www.notion.so/task(foo)'];
        $markdown = $builder->renderNotionMarkdown($builder->renderNotionBlocks(null, $builder->classifyAndSort([$item], $today), $today));
        self::assertSame(3, substr_count($markdown, '<callout icon="">'));
        self::assertStringContainsString('<b>09:00  </b>', $markdown);
        self::assertStringContainsString('\\[試験\\] \\*\\*太字ではない\\*\\* &lt;タグ&gt; &amp; 確認', $markdown);
        self::assertStringContainsString('](https://www.notion.so/task%28foo%29)', $markdown);
        self::assertStringContainsString('<span color="gray"><br />案件A</span>', $markdown);
        self::assertStringNotContainsString('<タグ>', $markdown);
        self::assertStringNotContainsString('💡', $markdown);
    }

    public function testNumericProjectNamesRenderAndGroupTrendsInEveryFormat(): void
    {
        $tz = new DateTimeZone('Asia/Saigon');
        $today = new DateTimeImmutable('2026-10-29', $tz);
        $builder = new ReportBuilder($tz);
        foreach (['12345', '0', '00123'] as $project) {
            $source = ['title' => '共有会', 'date_label' => '2026-10-29', 'url' => 'https://www.notion.so/meeting'];
            $brief = ['project_trends' => [
                ['project' => $project, 'timing' => '10/30', 'text' => 'リリース予定。', 'source' => $source],
                ['project' => $project, 'timing' => '11/2', 'text' => 'レビュー予定。', 'source' => $source],
            ]];
            $blocks = $builder->renderNotionBlocks(null, [], $today, $brief);
            $labels = array_values(array_filter($blocks[1]['callout']['children'], static fn (array $block): bool =>
                ($block['paragraph']['rich_text'][0]['annotations']['bold'] ?? false) === true
            ));
            self::assertCount(1, $labels);
            self::assertSame($project, $labels[0]['paragraph']['rich_text'][0]['text']['content']);
            $markdown = $builder->renderNotionMarkdown($blocks);
            self::assertSame(1, substr_count($markdown, '<b>' . $project . '</b>'));
            self::assertStringContainsString('10/30｜リリース予定。', $markdown);
            self::assertStringContainsString('11/2｜レビュー予定。', $markdown);
            foreach ([ReportBuilder::FORMAT_TEXT, ReportBuilder::FORMAT_SLACK, ReportBuilder::FORMAT_HTML] as $format) {
                $report = $builder->renderSchedule([], $today, $format, $brief);
                self::assertStringContainsString($project, $report);
                self::assertStringContainsString('10/30｜リリース予定。', $report);
                self::assertStringContainsString('11/2｜レビュー予定。', $report);
            }
        }
    }

    public function testLiteralDollarsAreEscapedOnlyInNotionMarkdown(): void
    {
        $tz = new DateTimeZone('Asia/Saigon');
        $today = new DateTimeImmutable('2026-10-29', $tz);
        $builder = new ReportBuilder($tz);
        $title = '「$NAME$」と$$の置換を確認';
        $item = ['title' => $title, 'source_name' => 'ToDo', 'date' => '2026-10-29', 'date_start' => '2026-10-29T09:00:00+07:00', 'date_has_time' => true, 'project' => '案件$ID$', 'url' => 'https://www.notion.so/task?template=$NAME$'];
        $items = $builder->classifyAndSort([$item], $today);
        $markdown = $builder->renderNotionMarkdown($builder->renderNotionBlocks(null, $items, $today));
        self::assertStringContainsString('[「\\$NAME\\$」と\\$\\$の置換を確認](https://www.notion.so/task?template=$NAME$)', $markdown);
        self::assertStringContainsString('<span color="gray"><br />案件\\$ID\\$</span>', $markdown);
        foreach ([ReportBuilder::FORMAT_TEXT, ReportBuilder::FORMAT_SLACK, ReportBuilder::FORMAT_HTML] as $format) {
            $report = $builder->renderSchedule($items, $today, $format);
            self::assertStringContainsString($title, $report);
            self::assertStringContainsString('案件$ID$', $report);
            self::assertStringNotContainsString('\\$', $report);
        }
    }

    public function testHealthArrowsHandleZeroEqualMissingAndNonConsecutiveRecordsInAllFormats(): void
    {
        $tz = new DateTimeZone('Asia/Saigon');
        $today = new DateTimeImmutable('2026-10-29', $tz);
        $builder = new ReportBuilder($tz);
        foreach ([
            ['weight', 73.4, 74.6, '2026-10-26', '↘（前回比 −1.2kg）'],
            ['weight', 73.4, 73.4, '2026-10-27', '→（前回比 0kg）'],
            ['steps', 4029, 0, '2026-10-27', '↗（前日比 ＋4,029歩）'],
            ['steps', 4029, 4112, '2026-10-26', '↘（前回比 −83歩）'],
            ['steps', 0, 4112, '2026-10-27', '↘（前日比 −4,112歩）'],
            ['steps', 4029, null, '2026-10-27', ''],
        ] as [$metric, $value, $previousValue, $previousDate, $expected]) {
            $item = $this->healthItem($metric, $value);
            $before = [];
            foreach ([ReportBuilder::FORMAT_TEXT, ReportBuilder::FORMAT_SLACK, ReportBuilder::FORMAT_HTML] as $format) {
                $before[$format] = $builder->renderSchedule([$item], $today, $format);
            }
            if ($previousValue !== null) {
                $item['previous_measurement'] = ['date' => $previousDate, 'numbers' => [$metric => $previousValue]];
            }
            $blocks = $builder->renderNotionBlocks(null, [$item], $today);
            $health = $blocks[3]['callout']['children'][0]['bulleted_list_item']['rich_text'][0]['text']['content'];
            if ($expected === '') {
                self::assertStringNotContainsString('比', $health);
                self::assertStringNotContainsString('比較なし', $health);
            } else {
                self::assertStringEndsWith($expected, $health);
            }
            foreach ($before as $format => $report) {
                $rendered = $builder->renderSchedule([$item], $today, $format);
                if ($expected === '') {
                    self::assertSame($report, $rendered);
                } else {
                    self::assertStringContainsString($expected, $rendered);
                }
            }
        }
    }

    private function healthItem(string $metric, int|float $value): array
    {
        return ['title' => $metric, 'source_name' => $metric, 'health_metric' => $metric, 'date' => '2026-10-28', 'date_start' => '2026-10-28T07:00:00+07:00', 'date_has_time' => true, 'numbers' => [$metric => $value]];
    }

    public function testPreviewCreatesRealRendererOutputAndNeverCallsMailOrSlackEvenOnFailure(): void
    {
        foreach ([false, true] as $failCreate) {
            $tz = new DateTimeZone('Asia/Saigon');
            $notion = $this->createMock(NotionClientInterface::class);
            $mail = $this->createMock(MailNotifierInterface::class);
            $mail->expects(self::never())->method('send');
            $slack = $this->createMock(SlackNotifierInterface::class);
            $slack->expects(self::never())->method('send');
            $openai = $this->createMock(OpenAIClientInterface::class);
            $openai->method('isConfigured')->willReturn(true);
            $openai->expects(self::never())->method('summarize');
            $openai->expects(self::once())->method('summarizeProjectTrends')->willReturnCallback(function (string $input): string {
                self::assertStringNotContainsString('73.4', $input);
                self::assertStringNotContainsString('4029', $input);
                return json_encode(['project_trends' => [['project' => '案件A', 'timing' => '10/29', 'text' => 'リリース予定。', 'start_date' => '2026-10-29', 'end_date' => '2026-10-29', 'source_id' => 'meeting', 'evidence' => '案件Aは10月29日にリリース予定。']]]);
            });
            $queries = [];
            $notion->method('queryDataSource')->willReturnCallback(function (string $id, array $properties, array $filter) use (&$queries): array {
                $queries[] = [$id, $filter];
                if ($id === 'meetings') {
                    return [['id' => 'meeting', 'url' => 'https://www.notion.so/meeting', 'properties' => ['Name' => ['type' => 'title', 'title' => [['plain_text' => '2026年10月28日 共有会']]], 'Created' => ['type' => 'created_time', 'created_time' => '2026-10-28T00:00:00Z']]]];
                }
                $previous = isset($filter['and'][0]['date']['before']);
                return [$this->healthPage($id === 'weight' ? ($previous ? 74.6 : 73.4) : ($previous ? 0 : 4029), $previous ? '2026-10-27T07:00:00+07:00' : '2026-10-28T07:00:00+07:00')];
            });
            $notion->method('retrieveBlockChildren')->with('meeting')->willReturn([['type' => 'paragraph', 'paragraph' => ['rich_text' => [['plain_text' => '案件Aは10月29日にリリース予定。']]]]]);
            $notion->expects(self::once())->method('createPage')->willReturnCallback(function (string $id, array $properties, array $blocks, ?string $markdown) use ($failCreate): array {
                self::assertSame('report-destination', $id);
                self::assertSame('Notion Daily Report 2026-10-29（確認用）', $properties['Name']['title'][0]['text']['content']);
                self::assertSame(['今日の予定', '案件動向', '以降の予定', '健康'], array_map(static fn ($b) => $b['callout']['rich_text'][0]['text']['content'], $blocks));
                $json = json_encode($blocks, JSON_UNESCAPED_UNICODE);
                self::assertStringContainsString('↘（前回比 −1.2kg）', $json);
                self::assertStringContainsString('↗（前日比 ＋4,029歩）', $json);
                self::assertStringContainsString('リリース予定。', $json);
                self::assertStringContainsString('https:\/\/www.notion.so\/meeting', $json);
                self::assertStringNotContainsString('今日の要点', $json);
                self::assertSame(4, substr_count($markdown, '<callout icon="">'));
                self::assertStringContainsString('出典：', $markdown);
                if ($failCreate) {
                    throw new RuntimeException('create failed');
                }
                return ['id' => 'preview', 'url' => 'https://www.notion.so/preview'];
            });
            $config = ['timezone' => 'Asia/Saigon', 'notion_report' => ['enabled' => false, 'data_source_id' => 'report-destination'], 'openai' => ['enabled' => true], 'mail' => ['enabled' => true], 'slack' => ['enabled' => true], 'meeting_notes' => ['name' => '議事録', 'role' => '案件動向', 'data_source_id' => 'meetings', 'date_property' => 'Created', 'title_property' => 'Name', 'project_property' => 'Project'], 'sources' => []];
            foreach (['weight', 'steps'] as $metric) {
                $config['sources'][] = ['name' => $metric, 'role' => '健康', 'data_source_id' => $metric, 'date_property' => '日付', 'health_metric' => $metric, 'number_properties' => [$metric => '値'], 'latest_results' => 1, 'lookback_days' => 0, 'lookahead_days' => 0, 'exclude_statuses' => []];
            }
            $command = new DailyReportCommand($config, $notion, new PropertyExtractor($tz), new DateFilter($tz), new ReportBuilder($tz), new Logger(sys_get_temp_dir() . '/notion-preview-test.log', $tz), $tz, false, $slack, $openai, $mail);
            ob_start();
            $exit = $command->run(['daily_report.php', '--date=2026-10-29', '--preview-notion']);
            $output = ob_get_clean();
            self::assertSame($failCreate ? 1 : 0, $exit);
            self::assertSame($failCreate ? '' : 'https://www.notion.so/preview' . PHP_EOL, $output);
            self::assertContains(['steps', ['and' => [['property' => '日付', 'date' => ['before' => '2026-10-28T00:00:00+07:00']], ['property' => '値', 'number' => ['is_not_empty' => true]]]]], $queries);
        }
    }

    private function healthPage(int|float $value, string $date): array
    {
        return ['id' => 'health', 'properties' => ['Name' => ['type' => 'title', 'title' => [['plain_text' => '健康']]], '日付' => ['type' => 'date', 'date' => ['start' => $date]], '値' => ['type' => 'number', 'number' => $value]]];
    }
}
