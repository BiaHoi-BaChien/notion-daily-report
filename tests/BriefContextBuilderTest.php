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
            'reading' => ['name' => '読書', 'role' => '楽しみ', 'data_source_id' => 'books', 'title_property' => 'Name', 'date_property' => 'Purchased', 'status_property' => 'Status'],
        ];
    }

    private function page(string $id, string $title, ?string $date = '2026-09-08', string $status = '読書中', string $project = 'project-a'): array
    {
        return ['id' => $id, 'url' => 'https://www.notion.so/' . $id, 'properties' => [
            'Name' => ['type' => 'title', 'title' => [['plain_text' => $title]]],
            'Purchased' => ['type' => 'date', 'date' => $date === null ? null : ['start' => $date]],
            'Status' => ['type' => 'status', 'status' => ['name' => $status]],
            'Created' => ['type' => 'created_time', 'created_time' => '2026-09-10T00:00:00Z'],
            '関連Project' => ['type' => 'relation', 'relation' => $project === '' ? [] : [['id' => $project]]],
        ]];
    }

    private function todayItems(): array
    {
        return [['classification' => 'today', 'source_name' => 'ToDo', 'project_relation_ids' => ['project-a']]];
    }

    public function testReadingUsesStatusPurchaseDateAndStableIdOrder(): void
    {
        $a = $this->page('a', '現在の本');
        $b = $this->page('b', '同日の本');
        $old = $this->page('old', '古い本', '2026-08-01');
        $done = $this->page('done', '読了の本', '2026-09-10', '読了');
        $future = $this->page('future', '未来の本', '2026-09-12');
        foreach ([[[], null], [[$a], 'a'], [[$done, $b, $a, $old], 'a'], [[$done, $old], 'old'], [[$a, $future], 'future']] as [$pages, $expected]) {
            $notion = $this->createMock(NotionClientInterface::class);
            $notion->expects(self::once())->method('queryDataSource')->with('books', [], ['property' => 'Status', 'status' => ['equals' => '読書中']])->willReturn($pages);
            $brief = $this->builder($notion)->load(['reading' => $this->config()['reading']], [], $this->today(), 'test');
            self::assertSame($expected, $brief['book']['id'] ?? null);
        }
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

    public function testNoProjectMeansNoMeetingQueryAndSupplementFailureDoesNotBlockReading(): void
    {
        $notion = $this->createMock(NotionClientInterface::class);
        $notion->expects(self::never())->method('queryDataSource');
        self::assertSame([], $this->builder($notion)->load(['meeting_notes' => $this->config()['meeting_notes']], [], $this->today(), 'test')['meetings']);
        $notion = $this->createMock(NotionClientInterface::class);
        $notion->method('queryDataSource')->willReturnCallback(fn (string $id): array => $id === 'meetings' ? throw new RuntimeException('unavailable') : [$this->page('book', '本')]);
        $brief = $this->builder($notion)->load($this->config(), $this->todayItems(), $this->today(), 'test');
        self::assertSame('取得不可', $brief['meeting_error']);
        self::assertSame('book', $brief['book']['id']);
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

    public function testSharedFormatsKeepOverviewLimitsAndLinkAllNewSections(): void
    {
        $builder = new ReportBuilder(new DateTimeZone('Asia/Ho_Chi_Minh'));
        $step = ['title' => '歩数', 'source_name' => '歩数', 'health_metric' => 'steps', 'date' => '2026-09-10', 'date_start' => '2026-09-10T00:00:00+07:00', 'date_has_time' => true, 'numbers' => ['steps' => 0], 'detail_url' => 'https://www.notion.so/steps-db'];
        $brief = [
            'highlights' => ['要点A', '要点B'], 'source_status' => ['各案件のタスク' => false],
            'meeting_points' => [['text' => '完了確認が必要：レビュー提出', 'source' => ['date_label' => '2026-09-10', 'url' => 'https://www.notion.so/meeting']]],
            'book' => ['title' => '本 & <試験>', 'url' => 'https://www.notion.so/book'],
        ];
        $items = $builder->classifyAndSort([$step], $this->today());
        foreach ([ReportBuilder::FORMAT_TEXT, ReportBuilder::FORMAT_SLACK, ReportBuilder::FORMAT_HTML] as $format) {
            $report = $builder->renderSchedule($items, $this->today(), $format, $brief);
            self::assertStringContainsString('本日期限：取得不可', $report);
            self::assertStringNotContainsString('本日期限のタスク', $report);
            self::assertStringContainsString('歩数：9月10日｜0歩', $report);
            self::assertStringNotContainsString('00:00', $report);
            foreach (['steps-db', 'meeting', 'book'] as $id) {
                self::assertStringContainsString('https://www.notion.so/' . $id, $report);
            }
            self::assertSame(3, substr_count(explode('🔥 今日の予定', $report)[0], '・'));
            if ($format !== ReportBuilder::FORMAT_TEXT) {
                self::assertStringContainsString('本 &amp; &lt;試験&gt;', $report);
            }
        }
        $blocks = $builder->renderNotionBlocks(null, $items, $this->today(), $brief);
        $json = json_encode($blocks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach (['steps-db', 'meeting', 'book'] as $id) {
            self::assertStringContainsString('"link":{"url":"https://www.notion.so/' . $id . '"}', $json);
        }
        self::assertSame('本日期限：取得不可', $blocks[3]['bulleted_list_item']['rich_text'][0]['text']['content']);
        $step['detail_url'] = 'javascript:alert(1)';
        self::assertStringNotContainsString('javascript:', $builder->renderSchedule([$step], $this->today(), ReportBuilder::FORMAT_HTML));
    }
}
