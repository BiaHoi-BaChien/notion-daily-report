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

    public function testSharedFormatsUseApprovedSectionsAndPreserveSourceLinks(): void
    {
        $builder = new ReportBuilder(new DateTimeZone('Asia/Ho_Chi_Minh'));
        $step = ['title' => '歩数', 'source_name' => '歩数', 'health_metric' => 'steps', 'date' => '2026-09-10', 'date_start' => '2026-09-10T00:00:00+07:00', 'date_has_time' => true, 'numbers' => ['steps' => 0], 'detail_url' => 'https://www.notion.so/steps-db'];
        $brief = [
            'highlights' => ['要点A', '要点B'], 'source_status' => ['各案件のタスク' => false],
            'meeting_points' => [['text' => '完了確認が必要：レビュー提出 & <試験>', 'source' => ['date_label' => '2026-09-10', 'url' => 'https://www.notion.so/meeting']]],
            'book' => ['title' => '本 & <試験>', 'url' => 'https://www.notion.so/book'],
        ];
        $brief['project_trends'] = [['project' => '案件A', 'timing' => '9/11', 'text' => 'レビュー提出 & <試験>', 'source' => ['date_label' => '2026-09-10', 'title' => '朝会', 'url' => 'https://www.notion.so/meeting']]];
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
            self::assertStringNotContainsString('要点A', $report);
            self::assertStringContainsString('案件動向', $report);
            self::assertStringContainsString('以降の予定', $report);
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
        self::assertSame('本日期限：取得不可', $blocks[0]['callout']['children'][0]['bulleted_list_item']['rich_text'][0]['text']['content']);
        $step['detail_url'] = 'javascript:alert(1)';
        self::assertStringNotContainsString('javascript:', $builder->renderSchedule([$step], $this->today(), ReportBuilder::FORMAT_HTML));
    }

    public function testRecentMeetingsIncludeProjectsWithoutTodaysScheduleAndUseMeetingDates(): void
    {
        $notion = $this->createMock(NotionClientInterface::class);
        $notion->expects(self::once())->method('queryDataSource')->willReturnCallback(function ($id, $properties, $filter): array {
            self::assertSame('meetings', $id);
            self::assertSame('2026-08-29T00:00:00+07:00', $filter['and'][1]['created_time']['on_or_after']);
            self::assertStringNotContainsString('relation', json_encode($filter));
            return [$this->page('recent', '2026年9月10日 共有会', 'other-project'), $this->page('old', '2026年8月28日 朝会'), $this->page('future', '2026年9月12日 朝会')];
        });
        $notion->expects(self::once())->method('retrieveBlockChildren')->with('recent')->willReturn([
            ['type' => 'paragraph', 'paragraph' => ['rich_text' => [['plain_text' => '案件Bは9月11日にリリース予定。']]]],
        ]);
        $notion->method('retrievePage')->willReturn(['properties' => ['Name' => ['type' => 'title', 'title' => [['plain_text' => '案件B']]]]]);
        $brief = $this->builder($notion)->loadRecentMeetings($this->config(), $this->today(), 'test');
        self::assertSame(['recent'], array_keys($brief['meetings']));
        self::assertSame(['案件B'], $brief['meetings']['recent']['projects']);
    }

    public function testProjectTrendsValidateEvidenceProjectDatesAndCount(): void
    {
        $builder = $this->builder($this->createMock(NotionClientInterface::class));
        $brief = ['meetings' => ['note' => ['title' => '共有会', 'date_label' => '2026-09-10', 'content' => '案件Aは9月11日にリリース予定。', 'projects' => ['案件A'], 'url' => 'https://www.notion.so/note']]];
        $trend = ['project' => '案件A', 'timing' => '9/11', 'text' => 'リリース予定。', 'start_date' => '2026-09-11', 'end_date' => '2026-09-11', 'source_id' => 'note', 'evidence' => '案件Aは9月11日にリリース予定。'];
        $apply = fn ($trends): array => $builder->applyProjectTrends(json_encode(['project_trends' => $trends]), $brief, $this->today(), 'test');
        $result = $apply([$trend, $trend]);
        self::assertCount(1, $result['project_trends']);
        self::assertSame($brief['meetings']['note'], $result['project_trends'][0]['source']);
        $partial = $apply([$trend, array_replace($trend, ['source_id' => 'invented'])]);
        self::assertCount(1, $partial['project_trends']);
        self::assertArrayNotHasKey('project_trends_error', $partial);
        foreach ([['source_id' => 'invented'], ['project' => '存在しない案件'], ['evidence' => '架空の根拠'], ['start_date' => '2026-02-30'], ['start_date' => '2026-09-12'], ['timing' => '明日'], ['text' => '来週リリース'], ['text' => '<script>']] as $change) {
            $result = $apply([array_replace($trend, $change)]);
            self::assertSame([], $result['project_trends']);
            self::assertArrayHasKey('project_trends_error', $result);
        }
        self::assertArrayHasKey('project_trends_error', $apply(array_fill(0, 6, $trend)));
        self::assertSame([], $apply([array_replace($trend, ['start_date' => '2026-09-10', 'end_date' => '2026-09-10'])])['project_trends']);
        self::assertArrayHasKey('project_trends_error', $builder->applyProjectTrends(null, $brief, $this->today(), 'test'));
    }
}
