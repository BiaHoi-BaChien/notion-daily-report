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
        $includeStatuses = array_map('strval', $source['include_statuses'] ?? []);

        $start = $today->setTimezone($this->timezone)->modify(sprintf('-%d days', $lookbackDays));
        $end = $today->setTimezone($this->timezone)->modify(sprintf('+%d days', $lookaheadDays));

        if (($source['annual'] ?? false) === true) {
            return $this->filterAnnual($items, $start, $end, $excludeStatuses, $includeStatuses);
        }

        $matches = [];
        foreach ($items as $item) {
            if (($item['date'] ?? null) === null || $item['date'] === '') {
                continue;
            }

            if ($this->isExcludedStatus($item['status'] ?? null, $excludeStatuses)) {
                continue;
            }

            if ($includeStatuses !== [] && !in_array((string) ($item['status'] ?? ''), $includeStatuses, true)) {
                continue;
            }

            $itemStart = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $item['date'], $this->timezone);
            if (!$itemStart) {
                continue;
            }

            $itemEnd = $this->dateFromValue($item['date_end'] ?? null) ?? $itemStart;
            if ($itemStart > $end || $itemEnd < $start) {
                continue;
            }

            $item['report_window_end'] = $end->format('Y-m-d');
            $matches[] = $item;
        }

        return $matches;
    }

    private function dateFromValue(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone($this->timezone)->setTime(0, 0);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, string> $excludeStatuses
     * @param array<int, string> $includeStatuses
     * @return array<int, array<string, mixed>>
     */
    private function filterAnnual(
        array $items,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        array $excludeStatuses,
        array $includeStatuses
    ): array {
        $matches = [];
        foreach ($items as $item) {
            if ($this->isExcludedStatus($item['status'] ?? null, $excludeStatuses)
                || ($includeStatuses !== [] && !in_array((string) ($item['status'] ?? ''), $includeStatuses, true))
            ) {
                continue;
            }

            $birthdate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($item['date'] ?? ''), $this->timezone);
            if (!$birthdate) {
                continue;
            }

            for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
                if ($date->format('m-d') !== $birthdate->format('m-d')) {
                    continue;
                }

                $item['date'] = $date->format('Y-m-d');
                $item['date_start'] = $item['date'];
                $item['extra']['birthdate'] = $birthdate->format('Y-m-d');
                $item['extra']['age'] = (string) ((int) $date->format('Y') - (int) $birthdate->format('Y'));
                $matches[] = $item;
                break;
            }
        }

        return $matches;
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
