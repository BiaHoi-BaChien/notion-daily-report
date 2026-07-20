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
        $this->timezone = new DateTimeZone('Asia/Ho_Chi_Minh');
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

    public function testFiltersAnnualBirthdaysAndCalculatesNewAge(): void
    {
        $filter = new DateFilter($this->timezone);
        $source = [
            'lookback_days' => 0,
            'lookahead_days' => 2,
            'exclude_statuses' => [],
            'include_statuses' => ['在職中'],
            'annual' => true,
        ];
        $items = [
            $this->item('today', '1990-12-31', '在職中'),
            $this->item('tomorrow', '1995-01-01', '在職中'),
            $this->item('later', '1995-01-03', '在職中'),
            $this->item('left', '1990-12-31', '離脱'),
        ];

        $filtered = $filter->filter($items, $source, new DateTimeImmutable('2026-12-31', $this->timezone));

        self::assertSame(['today', 'tomorrow'], array_column($filtered, 'title'));
        self::assertSame(['2026-12-31', '2027-01-01'], array_column($filtered, 'date'));
        self::assertSame('36', $filtered[0]['extra']['age']);
        self::assertSame('32', $filtered[1]['extra']['age']);
    }

    public function testIncludesDateRangeThatOverlapsTheReportWindow(): void
    {
        $filter = new DateFilter($this->timezone);
        $today = new DateTimeImmutable('2026-07-20', $this->timezone);
        $source = [
            'lookback_days' => 0,
            'lookahead_days' => 7,
        ];
        $active = $this->item('夏休み', '2026-07-18', null);
        $active['date_end'] = '2026-08-17T00:00:00+07:00';
        $ended = $this->item('終了済み', '2026-07-01', null);
        $ended['date_end'] = '2026-07-19T00:00:00+07:00';

        $filtered = $filter->filter([$active, $ended], $source, $today);

        self::assertSame(['夏休み'], array_column($filtered, 'title'));
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
