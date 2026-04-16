<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;

final class DateFilter
{
    public function __construct(private readonly DateTimeZone $timezone)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $source
     * @return array<int, array<string, mixed>>
     */
    public function filter(array $items, array $source, DateTimeImmutable $today): array
    {
        $lookbackDays = max(0, (int) ($source['lookback_days'] ?? 0));
        $lookaheadDays = max(0, (int) ($source['lookahead_days'] ?? 0));
        $excludeStatuses = array_map('strval', $source['exclude_statuses'] ?? []);

        $start = $today->setTimezone($this->timezone)->modify(sprintf('-%d days', $lookbackDays));
        $end = $today->setTimezone($this->timezone)->modify(sprintf('+%d days', $lookaheadDays));

        return array_values(array_filter($items, function (array $item) use ($start, $end, $excludeStatuses): bool {
            if (($item['date'] ?? null) === null || $item['date'] === '') {
                return false;
            }

            if ($this->isExcludedStatus($item['status'] ?? null, $excludeStatuses)) {
                return false;
            }

            $itemDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $item['date'], $this->timezone);
            if (!$itemDate) {
                return false;
            }

            return $itemDate >= $start && $itemDate <= $end;
        }));
    }

    /**
     * @param array<int, string> $excludeStatuses
     */
    private function isExcludedStatus(mixed $status, array $excludeStatuses): bool
    {
        if ($status === null || $status === '') {
            return false;
        }

        return in_array((string) $status, $excludeStatuses, true);
    }
}
