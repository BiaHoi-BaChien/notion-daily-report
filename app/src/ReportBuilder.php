<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;

final class ReportBuilder
{
    private const SOURCE_TODO = 'ToDo';
    private const SOURCE_PROJECT_TASK = '各案件のタスク';
    private const SOURCE_CALENDAR = 'カレンダー';
    private const SOURCE_IDENTITY_DOCUMENT = '身分証明書';
    private const GROUP_OTHER = 'その他';
    private const GROUP_SCHOOL = '学校';
    private const GROUP_LIFE = '生活';
    private const GENRE_HOLIDAY = '祝日';

    private const PRIORITY = [
        'overdue' => 1,
        'today' => 2,
        'upcoming' => 3,
        'recent_past' => 4,
    ];

    public function __construct(private readonly DateTimeZone $timezone)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public function classifyAndSort(array $items, DateTimeImmutable $today): array
    {
        $today = $today->setTimezone($this->timezone);
        $classified = array_map(function (array $item) use ($today): array {
            $item['classification'] = $this->classify($item, $today);
            return $item;
        }, $items);

        usort($classified, function (array $left, array $right): int {
            $leftPriority = self::PRIORITY[$left['classification']] ?? 99;
            $rightPriority = self::PRIORITY[$right['classification']] ?? 99;

            return [$leftPriority, $left['date'] ?? '', $left['title'] ?? '']
                <=> [$rightPriority, $right['date'] ?? '', $right['title'] ?? ''];
        });

        return $classified;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function renderSchedule(array $items, DateTimeImmutable $today): string
    {
        $lines = [];
        $lines[] = $this->todayHeader($today);

        $lines[] = '1. 今日確認するべきToDo';
        $this->appendRows(
            $lines,
            $this->todayTodoItems($items),
            false
        );
        $lines[] = '';

        $lines[] = '2. 今日が期限の案件のタスク';
        $this->appendRows(
            $lines,
            $this->todayProjectTaskItems($items),
            false
        );
        $lines[] = '';

        $lines[] = '3. 近日中に確認が必要なこと';
        $this->appendGroupedRows(
            $lines,
            $this->upcomingItems($items),
            $today,
            true
        );

        $holidays = $this->holidayItems($items);
        $identityDocuments = $this->identityDocumentItems($items);
        if ($holidays !== [] || $identityDocuments !== []) {
            $lines[] = '';
            $lines[] = '4. その他トピックス';
            if ($holidays !== []) {
                $this->appendGroupedHolidayRows($lines, $holidays, $today);
            }

            if ($identityDocuments !== []) {
                if ($holidays !== []) {
                    $lines[] = '';
                }

                $lines[] = '  以下の身分証明書の有効期限が近づいています。';
                $this->appendGroupedRows($lines, $identityDocuments, $today, true);
            }
        }

        return rtrim(implode(PHP_EOL, $lines)) . PHP_EOL;
    }

    public function renderReport(?string $comment, string $schedule): string
    {
        $comment = trim((string) $comment);
        if ($comment === '') {
            return rtrim($schedule) . PHP_EOL;
        }

        return sprintf("%s%s%s%s", $comment, PHP_EOL . PHP_EOL, rtrim($schedule), PHP_EOL);
    }

    private function todayHeader(DateTimeImmutable $today): string
    {
        $today = $today->setTimezone($this->timezone);

        return sprintf('%s（%s）', $today->format('Y年m月d日'), $this->weekdayLabel($today));
    }

    /**
     * @param array<string, mixed> $item
     */
    private function classify(array $item, DateTimeImmutable $today): string
    {
        $itemDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $item['date'], $this->timezone);
        if (!$itemDate) {
            return 'recent_past';
        }

        if ($itemDate->format('Y-m-d') === $today->format('Y-m-d')) {
            return 'today';
        }

        if ($itemDate > $today) {
            return 'upcoming';
        }

        return ($item['status'] ?? null) === null ? 'recent_past' : 'overdue';
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function todayTodoItems(array $items): array
    {
        return array_values(array_filter(
            $items,
            fn (array $item): bool => ($item['classification'] ?? null) === 'today'
                && !$this->isHoliday($item)
                && !$this->isIdentityDocument($item)
                && ($this->isTodo($item) || $this->isCalendar($item))
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function todayProjectTaskItems(array $items): array
    {
        return array_values(array_filter(
            $items,
            fn (array $item): bool => ($item['classification'] ?? null) === 'today'
                && $this->isProjectTask($item)
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function upcomingItems(array $items): array
    {
        return array_values(array_filter(
            $items,
            fn (array $item): bool => ($item['classification'] ?? null) === 'upcoming'
                && !$this->isHoliday($item)
                && !$this->isIdentityDocument($item)
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function holidayItems(array $items): array
    {
        return array_values(array_filter(
            $items,
            fn (array $item): bool => $this->isCalendar($item) && $this->isHoliday($item)
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function identityDocumentItems(array $items): array
    {
        return array_values(array_filter(
            $items,
            fn (array $item): bool => $this->isIdentityDocument($item)
        ));
    }

    /**
     * @param array<int, string> $lines
     * @param array<int, array<string, mixed>> $items
     */
    private function appendRows(array &$lines, array $items, bool $includeDate): void
    {
        if ($items === []) {
            $lines[] = '  該当なし';
            return;
        }

        $items = $this->sortRows($items, $includeDate);
        foreach ($items as $item) {
            $lines[] = sprintf(
                '  【%s】%s | %s',
                $this->dateText($item, $includeDate),
                $item['title'] ?? '無題',
                $this->groupName($item)
            );
        }
    }

    /**
     * @param array<int, string> $lines
     * @param array<int, array<string, mixed>> $items
     */
    private function appendGroupedRows(array &$lines, array $items, DateTimeImmutable $today, bool $includeDate): void
    {
        if ($items === []) {
            $lines[] = '  該当なし';
            return;
        }

        $currentDate = null;
        foreach ($this->sortRows($items, $includeDate) as $item) {
            $start = $this->dateTimeFromItem($item, 'date_start');
            $dateKey = $this->dateGroupKey($start);
            if ($dateKey !== $currentDate) {
                $lines[] = '・' . $this->relativeDateLabel($start, $today);
                $currentDate = $dateKey;
            }

            $lines[] = sprintf(
                '  【%s】%s | %s',
                $this->dateText($item, $includeDate),
                $item['title'] ?? '無題',
                $this->groupName($item)
            );
        }
    }

    /**
     * @param array<int, string> $lines
     * @param array<int, array<string, mixed>> $items
     */
    private function appendGroupedHolidayRows(array &$lines, array $items, DateTimeImmutable $today): void
    {
        $currentDate = null;
        foreach ($this->sortRows($items, true) as $holiday) {
            $start = $this->dateTimeFromItem($holiday, 'date_start');
            $dateKey = $this->dateGroupKey($start);
            if ($dateKey !== $currentDate) {
                $lines[] = '・' . $this->relativeDateLabel($start, $today);
                $currentDate = $dateKey;
            }

            $date = $this->dateForDisplay($holiday, 'm/d');
            $lines[] = sprintf('    【%s】 %s | %s', $date, $holiday['title'] ?? '無題', self::GENRE_HOLIDAY);
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function groupName(array $item): string
    {
        if ($this->isIdentityDocument($item)) {
            return self::SOURCE_IDENTITY_DOCUMENT;
        }

        if ($this->isCalendar($item)) {
            $genre = trim((string) ($item['genre'] ?? ''));
            if ($genre === self::GROUP_SCHOOL || $genre === self::GROUP_LIFE) {
                return $genre;
            }
        }

        return $this->projectGroup($item);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function projectGroup(array $item): string
    {
        $project = trim((string) ($item['project'] ?? ''));
        return $project === '' ? self::GROUP_OTHER : $project;
    }

    private function groupPriority(string $group): int
    {
        return match ($group) {
            self::GROUP_OTHER => 90,
            self::GROUP_SCHOOL => 91,
            self::GROUP_LIFE => 92,
            default => 10,
        };
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function sortRows(array $items, bool $includeDate): array
    {
        usort($items, function (array $left, array $right) use ($includeDate): int {
            $leftDate = $this->sortDateValue($left);
            $rightDate = $this->sortDateValue($right);

            if ($leftDate !== $rightDate) {
                return $leftDate <=> $rightDate;
            }

            return [
                $this->groupPriority($this->groupName($left)),
                $this->groupName($left),
                $left['title'] ?? '',
            ] <=> [
                $this->groupPriority($this->groupName($right)),
                $this->groupName($right),
                $right['title'] ?? '',
            ];
        });

        return $items;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function dateText(array $item, bool $includeDate): string
    {
        $start = $this->dateTimeFromItem($item, 'date_start');
        if ($start === null) {
            return '';
        }

        $end = $this->dateTimeFromItem($item, 'date_end');
        $hasStartTime = ($item['date_has_time'] ?? false) === true;
        $hasEndTime = ($item['date_end_has_time'] ?? false) === true;

        if (!$includeDate && !$hasStartTime) {
            return '';
        }

        $startFormat = $includeDate ? ($hasStartTime ? 'm/d H:i' : 'm/d') : 'H:i';
        $startText = $start->format($startFormat);

        if ($end !== null) {
            if ($includeDate) {
                if ($end->format('Y-m-d') === $start->format('Y-m-d') && $hasEndTime) {
                    $endFormat = 'H:i';
                } else {
                    $endFormat = $hasEndTime ? 'm/d H:i' : 'm/d';
                }
            } else {
                $endFormat = $hasEndTime ? 'H:i' : '';
            }

            if ($endFormat !== '') {
                return sprintf('%s - %s', $startText, $end->format($endFormat));
            }
        }

        return $startText;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function sortDateValue(array $item): string
    {
        $start = $this->dateTimeFromItem($item, 'date_start');
        if ($start === null) {
            return '9999-12-31T23:59:59+00:00';
        }

        return $start->format(DATE_ATOM);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function dateForDisplay(array $item, string $format): string
    {
        $start = $this->dateTimeFromItem($item, 'date_start');
        if ($start === null) {
            return (string) ($item['date'] ?? '');
        }

        return $start->format($format);
    }

    private function dateGroupKey(?DateTimeImmutable $date): string
    {
        if ($date === null) {
            return '';
        }

        return $date->setTimezone($this->timezone)->format('Y-m-d');
    }

    private function relativeDateLabel(?DateTimeImmutable $date, DateTimeImmutable $today): string
    {
        if ($date === null) {
            return '日付不明';
        }

        $date = $date->setTimezone($this->timezone);
        $today = $today->setTimezone($this->timezone);
        $targetDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date->format('Y-m-d'), $this->timezone);
        $baseDate = DateTimeImmutable::createFromFormat('!Y-m-d', $today->format('Y-m-d'), $this->timezone);
        if (!$targetDate || !$baseDate) {
            return '日付不明';
        }

        $days = (int) $baseDate->diff($targetDate)->format('%r%a');
        $relative = match ($days) {
            -1 => '昨日',
            0 => '今日',
            1 => '明日',
            2 => '明後日',
            default => $days > 0 ? sprintf('%d日後', $days) : sprintf('%d日前', abs($days)),
        };

        return sprintf('%s（%s）', $relative, $this->weekdayLabel($targetDate));
    }

    private function weekdayLabel(DateTimeImmutable $date): string
    {
        $weekdays = ['日', '月', '火', '水', '木', '金', '土'];

        return $weekdays[(int) $date->setTimezone($this->timezone)->format('w')];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function dateTimeFromItem(array $item, string $key): ?DateTimeImmutable
    {
        if (!isset($item[$key]) || !is_string($item[$key]) || trim($item[$key]) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($item[$key]))->setTimezone($this->timezone);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isTodo(array $item): bool
    {
        return ($item['source_name'] ?? null) === self::SOURCE_TODO;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isProjectTask(array $item): bool
    {
        return ($item['source_name'] ?? null) === self::SOURCE_PROJECT_TASK;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isCalendar(array $item): bool
    {
        return ($item['source_name'] ?? null) === self::SOURCE_CALENDAR;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isIdentityDocument(array $item): bool
    {
        return ($item['source_name'] ?? null) === self::SOURCE_IDENTITY_DOCUMENT;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isHoliday(array $item): bool
    {
        return trim((string) ($item['genre'] ?? '')) === self::GENRE_HOLIDAY;
    }
}
