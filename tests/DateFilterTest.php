<?php

declare(strict_types=1);

namespace Tests;

use App\DateFilter;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class DateFilterTest extends TestCase
{
    private DateTimeZone $timezone;

    protected function setUp(): void
    {
        $this->timezone = new DateTimeZone('Asia/Saigon');
    }

    public function testFiltersByDateWindowAndExcludedStatuses(): void
    {
        $filter = new DateFilter($this->timezone);
        $today = new DateTimeImmutable('2026-04-16', $this->timezone);
        $source = [
            'lookback_days' => 1,
            'lookahead_days' => 3,
            'exclude_statuses' => ['完了', '中止'],
        ];

        $items = [
            $this->item('overdue', '2026-04-15', '未着手'),
            $this->item('today', '2026-04-16', '未着手'),
            $this->item('upcoming', '2026-04-19', '未着手'),
            $this->item('too_old', '2026-04-14', '未着手'),
            $this->item('too_far', '2026-04-20', '未着手'),
            $this->item('done', '2026-04-16', '完了'),
            $this->item('null_date', null, '未着手'),
            $this->item('recent_past', '2026-04-15', null),
        ];

        $filtered = $filter->filter($items, $source, $today);

        self::assertSame(['overdue', 'today', 'upcoming', 'recent_past'], array_column($filtered, 'title'));
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $title, ?string $date, ?string $status): array
    {
        return [
            'title' => $title,
            'date' => $date,
            'status' => $status,
        ];
    }
}
