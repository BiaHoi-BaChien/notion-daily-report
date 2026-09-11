<?php

declare(strict_types=1);

namespace App;

use App\Exception\OpenAIException;
use App\Exception\PropertyExtractionException;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class BriefContextBuilder
{
    public function __construct(
        private readonly NotionClientInterface $notion,
        private readonly PropertyExtractor $extractor,
        private readonly DateTimeZone $timezone,
        private readonly Logger $logger
    ) {
    }

    public function load(array $config, array $items, DateTimeImmutable $today, string $runId): array
    {
        $today = $today->setTimezone($this->timezone)->setTime(0, 0);
        $brief = ['meetings' => [], 'meeting_points' => [], 'highlights' => [], 'book' => null];
        foreach (['meeting_notes', 'reading'] as $key) {
            $source = $config[$key] ?? [];
            if (trim((string) ($source['data_source_id'] ?? '')) === '') {
                continue;
            }
            try {
                if ($key === 'reading') {
                    $brief['book'] = $this->reading($source);
                } else {
                    $brief['meetings'] = $this->meetings($source, $items, $today);
                }
            } catch (Throwable $exception) {
                $brief[$key === 'reading' ? 'book_error' : 'meeting_error'] = '取得不可';
                $this->logger->error('brief_source_failed', [
                    'run_id' => $runId,
                    'source' => $key,
                    'exception_class' => $exception::class,
                ]);
            }
        }
        return $brief;
    }

    private function reading(array $source): ?array
    {
        $pages = $this->notion->queryDataSource($source['data_source_id'], [], [
            'property' => $source['status_property'],
            'status' => ['equals' => '読書中'],
        ]);
        $books = [];
        foreach ($pages as $page) {
            $book = $this->extractor->extract($page, $source);
            if ($book['status'] === '読書中') {
                $books[] = $book;
            }
        }
        usort($books, static fn (array $a, array $b): int =>
            (($b['date'] ?? '') <=> ($a['date'] ?? '')) ?: strcmp((string) $a['id'], (string) $b['id'])
        );
        return $books[0] ?? null;
    }

    private function meetings(array $source, array $items, DateTimeImmutable $today): array
    {
        $projectIds = [];
        foreach ($items as $item) {
            if (($item['classification'] ?? '') === 'today'
                && in_array($item['source_name'] ?? '', ['ToDo', 'カレンダー', '各案件のタスク'], true)
            ) {
                array_push($projectIds, ...($item['project_relation_ids'] ?? []));
            }
        }
        $projectIds = array_values(array_unique($projectIds));
        if ($projectIds === []) {
            return [];
        }
        // The current date plus the preceding 13 local calendar days.
        $start = $today->setTime(0, 0)->modify('-13 days');
        $end = $today->setTime(0, 0)->modify('+1 day');
        $projectFilters = array_map(static fn (string $id): array => [
            'property' => $source['project_property'], 'relation' => ['contains' => $id],
        ], $projectIds);
        $pages = $this->notion->queryDataSource($source['data_source_id'], [], ['and' => [
            ['timestamp' => 'created_time', 'created_time' => ['before' => $end->format(DATE_ATOM)]],
            count($projectFilters) === 1 ? $projectFilters[0] : ['or' => $projectFilters],
        ]], [['timestamp' => 'created_time', 'direction' => 'descending']]);
        $notes = [];
        foreach ($pages as $page) {
            $note = $this->extractor->extract($page, $source);
            if (array_intersect($projectIds, $note['project_relation_ids']) === []) {
                continue;
            }
            $sourceDate = $this->sourceDate($note);
            if ($sourceDate['date'] < $start->format('Y-m-d') || $sourceDate['date'] >= $end->format('Y-m-d')) {
                continue;
            }
            $id = (string) ($note['id'] ?? '');
            if ($id === '') {
                throw new PropertyExtractionException('Meeting page ID is missing.');
            }
            $lines = [];
            foreach ($this->notion->retrieveBlockChildren($id) as $block) {
                $type = $block['type'] ?? '';
                if ($type === 'unsupported') {
                    throw new PropertyExtractionException('Unsupported meeting content.');
                }
                $content = $block[$type] ?? [];
                $cells = $type === 'table_row' ? ($content['cells'] ?? []) : [$content['rich_text'] ?? []];
                $text = implode(' | ', array_map(static fn (array $chunks): string =>
                    implode('', array_map(static fn (array $chunk): string =>
                        (string) ($chunk['plain_text'] ?? $chunk['text']['content'] ?? ''), $chunks
                    )), $cells
                ));
                if ($text !== '') {
                    $prefix = $type === 'to_do' ? (($content['checked'] ?? false) ? '[x] ' : '[ ] ') : '';
                    $lines[] = $prefix . $text;
                }
            }
            if ($lines !== []) {
                $notes[$id] = [
                    'source_id' => $id,
                    'title' => $note['title'],
                    'date' => $sourceDate['date'],
                    'date_label' => $sourceDate['label'],
                    'url' => $note['url'],
                    'content' => implode("\n", $lines),
                ];
            }
        }
        uasort($notes, static fn (array $a, array $b): int => [$b['date'], $b['source_id']] <=> [$a['date'], $a['source_id']]);
        return $notes;
    }

    private function sourceDate(array $note): array
    {
        if (preg_match('/(?<!\d)(\d{4})(?:年|[-\/])(\d{1,2})(?:月|[-\/])(\d{1,2})(?:日|(?!\d))/u', $note['title'], $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            $date = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
            return ['date' => $date, 'label' => $date];
        }
        if ($note['date'] === null) {
            throw new PropertyExtractionException('Meeting source date is missing.');
        }
        return ['date' => $note['date'], 'label' => '登録日 ' . $note['date']];
    }

    public function applySummary(?string $json, array $brief, string $runId): array
    {
        if ($json === null) {
            if ($brief['meetings'] !== []) {
                $brief['meeting_error'] = '要約不可（AIが無効、未設定、または取得失敗）';
            }
            return $brief;
        }
        try {
            $summary = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
            if (!$summary instanceof \stdClass || !is_array($summary->highlights ?? null)
                || count($summary->highlights) > 2
                || !is_array($summary->meeting_points ?? null) || count($summary->meeting_points) > 2
            ) {
                throw new OpenAIException('Invalid brief summary structure.');
            }
            $highlights = [];
            foreach ($summary->highlights as $text) {
                $text = $this->summaryText($text);
                if (preg_match('/体重|歩数|血圧|脈拍|mmHg|本日期限/u', $text)) {
                    throw new OpenAIException('Summary included an excluded topic.');
                }
                $highlights[] = $text;
            }
            $points = [];
            foreach ($summary->meeting_points as $point) {
                $id = $point->source_id ?? null;
                $kind = $point->kind ?? null;
                if (!$point instanceof \stdClass || !is_string($id) || !isset($brief['meetings'][$id]) || !in_array($kind, ['decision', 'followup'], true)) {
                    throw new OpenAIException('Invalid meeting summary source.');
                }
                $text = $this->summaryText($point->text ?? null);
                if (preg_match('/本日|今日|明日|昨日|未完了/u', $text)) {
                    throw new OpenAIException('Meeting summary has an ambiguous date or completion claim.');
                }
                $points[] = [
                    'text' => ($kind === 'followup' ? '完了確認が必要：' : '決定事項：') . $text,
                    'source' => $brief['meetings'][$id],
                ];
            }
            $brief['highlights'] = array_values(array_unique($highlights));
            $brief['meeting_points'] = $points;
        } catch (Throwable $exception) {
            if ($brief['meetings'] !== []) {
                $brief['meeting_error'] = '要約不可';
            }
            $this->logger->error('brief_summary_invalid', ['run_id' => $runId, 'exception_class' => $exception::class]);
        }
        return $brief;
    }

    private function summaryText(mixed $text): string
    {
        if (!is_string($text) || trim($text) === '' || preg_match('/[\r\n<>]|https?:\/\//u', $text)
            || preg_match_all('/./us', $text) > 180
        ) {
            throw new OpenAIException('Invalid brief summary text.');
        }
        return trim($text);
    }
}
