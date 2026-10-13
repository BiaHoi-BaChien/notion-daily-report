<?php

declare(strict_types=1);

namespace Tests;

use App\BriefContextBuilder;
use App\Logger;
use App\NotionClientInterface;
use App\PropertyExtractor;
use App\ReportBuilder;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BriefContextBuilderTest extends TestCase
{
    private function builder(NotionClientInterface $notion): BriefContextBuilder
    {
        $tz = new DateTimeZone('Asia/Ho_Chi_Minh');
        return new BriefContextBuilder($notion, new PropertyExtractor($tz), $tz, new Logger(sys_get_temp_dir() . '/brief-test.log', $tz));
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-11', new DateTimeZone('Asia/Ho_Chi_Minh'));
    }

    private function config(): array
    {
        return [
            'meeting_notes' => ['name' => '議事録', 'role' => '案件確認', 'data_source_id' => 'meetings', 'title_property' => 'Name', 'date_property' => 'Created', 'project_property' => '関連Project'],
        ];
    }

    private function page(string $id, string $title, string $project = 'project-a'): array
    {
        return ['id' => $id, 'url' => 'https://www.notion.so/' . $id, 'properties' => [
            'Name' => ['type' => 'title', 'title' => [['plain_text' => $title]]],
            'Created' => ['type' => 'created_time', 'created_time' => '2026-09-10T00:00:00Z'],
            '関連Project' => ['type' => 'relation', 'relation' => $project === '' ? [] : [['id' => $project]]],
        ]];
    }

    private function todayItems(): array
    {
        return [['classification' => 'today', 'source_name' => 'ToDo', 'project_relation_ids' => ['project-a']]];
    }

    public function testIgnoresLegacyReadingConfig(): void
    {
        $notion = $this->createMock(NotionClientInterface::class);
        $notion->expects(self::never())->method('queryDataSource');
        $brief = $this->builder($notion)->load([
            'reading' => ['data_source_id' => 'books', 'status_property' => 'Status'],
        ], [], $this->today(), 'test');
        self::assertSame(['meetings' => [], 'meeting_points' => [], 'highlights' => []], $brief);
    }

    public function testMeetingsMatchProjectsAndFourteenLocalDaysAndPreserveCompletionEvidence(): void
    {
        $notion = $this->createMock(NotionClientInterface::class);
        $notion->expects(self::once())->method('queryDataSource')->with('meetings', [], self::callback(function (array $filter): bool {
            self::assertSame('2026-09-12T00:00:00+07:00', $filter['and'][0]['created_time']['before']);
            self::assertSame('project-a', $filter['and'][1]['relation']['contains']);
            return true;
        }), [['timestamp' => 'created_time', 'direction' => 'descending']])->willReturn([
            $this->page('boundary', '朝会（2026年8月29日）'),
            $this->page('old', '2026年8月28日 朝会'),
            $this->page('future', '2026/09/12 朝会'),
            $this->page('wrong', '2026-09-10 別案件', project: 'other'),
            $this->page('unset', '2026-09-10 未設定', project: ''),
            $this->page('latest', '2026/09/11 朝会'),
            $this->page('fallback', '日付なしの会議'),
        ]);
        $read = [];
        $notion->method('retrieveBlockChildren')->willReturnCallback(static function (string $id) use (&$read): array {
            $read[] = $id;
            return $id === 'fallback'
                ? [['type' => 'table_row', 'table_row' => ['cells' => [[['plain_text' => '検証環境']], [['plain_text' => '変更あり']]]]]]
                : [['type' => 'to_do', 'to_do' => ['checked' => $id === 'latest', 'rich_text' => [['plain_text' => 'レビューする']]]]];
        });
        $brief = $this->builder($notion)->load(['meeting_notes' => $this->config()['meeting_notes']], $this->todayItems(), $this->today(), 'test');
        self::assertSame(['latest', 'fallback', 'boundary'], array_keys($brief['meetings']));
        self::assertSame(['boundary', 'latest', 'fallback'], $read);
        self::assertSame('[x] レビューする', $brief['meetings']['latest']['content']);
        self::assertSame('[ ] レビューする', $brief['meetings']['boundary']['content']);
        self::assertSame('登録日 2026-09-10', $brief['meetings']['fallback']['date_label']);
        self::assertSame('検証環境 | 変更あり', $brief['meetings']['fallback']['content']);
    }

    public function testNoProjectMeansNoMeetingQueryAndMeetingFailureIsReported(): void
    {
        $notion = $this->createMock(NotionClientInterface::class);
        $notion->expects(self::never())->method('queryDataSource');
        self::assertSame([], $this->builder($notion)->load(['meeting_notes' => $this->config()['meeting_notes']], [], $this->today(), 'test')['meetings']);
        $notion = $this->createMock(NotionClientInterface::class);
        $notion->expects(self::once())->method('queryDataSource')->with('meetings')->willThrowException(new RuntimeException('unavailable'));
        $brief = $this->builder($notion)->load($this->config(), $this->todayItems(), $this->today(), 'test');
        self::assertSame('取得不可', $brief['meeting_error']);
        self::assertSame([], $brief['meetings']);
    }

    public function testValidatesSummaryLimitsTopicsDatesAndSourceIds(): void
    {
        $builder = $this->builder($this->createMock(NotionClientInterface::class));
        $brief = ['highlights' => [], 'meeting_points' => [], 'meetings' => [
            'latest' => ['date_label' => '2026-09-10', 'title' => '朝会', 'url' => 'https://www.notion.so/latest'],
        ]];
        $valid = ['highlights' => ['11時の進捗確認'], 'meeting_points' => [['source_id' => 'latest', 'kind' => 'followup', 'text' => '9月10日のレビュー提出']]];
        $result = $builder->applySummary(json_encode($valid), $brief, 'test');
        self::assertSame('完了確認が必要：9月10日のレビュー提出', $result['meeting_points'][0]['text']);
        self::assertSame($brief['meetings']['latest'], $result['meeting_points'][0]['source']);
        foreach ([
            'not json',
            '{"highlights":{},"meeting_points":{}}',
            json_encode(['highlights' => ['a', 'b', 'c'], 'meeting_points' => []]),
            json_encode(['highlights' => ['体重が減りました'], 'meeting_points' => []]),
            json_encode(['highlights' => ["行1\n行2"], 'meeting_points' => []]),
            json_encode(['highlights' => [], 'meeting_points' => [['source_id' => 'invented', 'kind' => 'decision', 'text' => '変更']]]),
            json_encode(['highlights' => [], 'meeting_points' => [['source_id' => 'latest', 'kind' => 'followup', 'text' => '本日中に対応']]]),
            json_encode(['highlights' => [], 'meeting_points' => array_fill(0, 3, $valid['meeting_points'][0])]),
            null,
        ] as $invalid) {
            $result = $builder->applySummary($invalid, $brief, 'test');
            self::assertSame([], $result['highlights']);
            self::assertSame([], $result['meeting_points']);
            self::assertArrayHasKey('meeting_error', $result);
        }
    }

    public function testSharedFormatsKeepOverviewLimitsAndLinksWithoutReadingSection(): void
    {
        $builder = new ReportBuilder(new DateTimeZone('Asia/Ho_Chi_Minh'));
        $step = ['title' => '歩数', 'source_name' => '歩数', 'health_metric' => 'steps', 'date' => '2026-09-10', 'date_start' => '2026-09-10T00:00:00+07:00', 'date_has_time' => true, 'numbers' => ['steps' => 0], 'detail_url' => 'https://www.notion.so/steps-db'];
        $brief = [
            'highlights' => ['要点A', '要点B'], 'source_status' => ['各案件のタスク' => false],
            'meeting_points' => [['text' => '完了確認が必要：レビュー提出 & <試験>', 'source' => ['date_label' => '2026-09-10', 'url' => 'https://www.notion.so/meeting']]],
            'book' => ['title' => '本 & <試験>', 'url' => 'https://www.notion.so/book'],
        ];
        $items = $builder->classifyAndSort([$step], $this->today());
        foreach ([ReportBuilder::FORMAT_TEXT, ReportBuilder::FORMAT_SLACK, ReportBuilder::FORMAT_HTML] as $format) {
            $report = $builder->renderSchedule($items, $this->today(), $format, $brief);
            self::assertStringContainsString('本日期限：取得不可', $report);
            self::assertStringNotContainsString('本日期限のタスク', $report);
            self::assertStringContainsString('歩数：9月10日｜0歩', $report);
            self::assertStringNotContainsString('00:00', $report);
            foreach (['steps-db', 'meeting'] as $id) {
                self::assertStringContainsString('https://www.notion.so/' . $id, $report);
            }
            self::assertStringNotContainsString('今日の小さな楽しみ', $report);
            self::assertStringNotContainsString('https://www.notion.so/book', $report);
            self::assertSame(3, substr_count(explode('🔥 今日の予定', $report)[0], '・'));
            if ($format !== ReportBuilder::FORMAT_TEXT) {
                self::assertStringContainsString('レビュー提出 &amp; &lt;試験&gt;', $report);
            }
        }
        $blocks = $builder->renderNotionBlocks(null, $items, $this->today(), $brief);
        $json = json_encode($blocks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach (['steps-db', 'meeting'] as $id) {
            self::assertStringContainsString('"link":{"url":"https://www.notion.so/' . $id . '"}', $json);
        }
        self::assertStringNotContainsString('今日の小さな楽しみ', $json);
        self::assertStringNotContainsString('https://www.notion.so/book', $json);
        self::assertSame('本日期限：取得不可', $blocks[3]['bulleted_list_item']['rich_text'][0]['text']['content']);
        $step['detail_url'] = 'javascript:alert(1)';
        self::assertStringNotContainsString('javascript:', $builder->renderSchedule([$step], $this->today(), ReportBuilder::FORMAT_HTML));
    }
}
