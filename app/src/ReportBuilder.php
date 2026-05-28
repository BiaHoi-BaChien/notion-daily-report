<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;

final class ReportBuilder
{
    public const FORMAT_TEXT = 'text';
    public const FORMAT_SLACK = 'slack';
    public const FORMAT_HTML = 'html';

    private const SOURCE_TODO = 'ToDo';
    private const SOURCE_PROJECT_TASK = '各案件のタスク';
    private const SOURCE_CALENDAR = 'カレンダー';
    private const SOURCE_IDENTITY_DOCUMENT = '身分証明書';
    private const SOURCE_CHILD_LUNCH = '子供のお弁当';
    private const GROUP_OTHER = 'その他';
    private const GROUP_SCHOOL = '学校';
    private const GROUP_LIFE = '生活';
    private const GENRE_HOLIDAY = '祝日';
    private const SECTION_SEPARATOR = '━━━━━━━━━━';

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
    public function renderSchedule(array $items, DateTimeImmutable $today, string $format = self::FORMAT_TEXT): string
    {
        $lines = [];
        $lines[] = $this->todayHeader($today);

        $this->appendSectionHeader($lines, '🔥 今日の予定');
        $this->appendRows(
            $lines,
            $this->todayTodoItems($items),
            false,
            false,
            $format
        );
        $lines[] = '';

        $this->appendSectionHeader($lines, '⏰ 明日の時間付き予定');
        $this->appendRows(
            $lines,
            $this->tomorrowTimedTodoAndCalendarItems($items, $today),
            false,
            true
        );
        $lines[] = '';

        $this->appendSectionHeader($lines, '⚠ 本日期限');
        $this->appendRows(
            $lines,
            $this->todayProjectTaskItems($items),
            false,
            true,
            $format
        );
        $lines[] = '';

        $this->appendSectionHeader($lines, '📌 近日確認');
        $this->appendGroupedRows(
            $lines,
            $this->upcomingItems($items, $today),
            $today,
            true,
            true,
            $format
        );

        $childLunches = $this->childLunchItems($items);
        $identityDocuments = $this->identityDocumentItems($items);
        if ($childLunches !== [] || $identityDocuments !== []) {
            $lines[] = '';
            $this->appendSectionHeader($lines, '💡 その他トピックス');

            if ($childLunches !== []) {
                foreach ($this->sortRows($childLunches, true) as $childLunch) {
                    $text = $this->childLunchText($childLunch);
                    if ($text !== null) {
                        $lines[] = sprintf('今日の子供のお弁当は「%s」です。', $this->formatText($text, $format));
                    }
                }
            }

            if ($identityDocuments !== []) {
                if ($childLunches !== []) {
                    $lines[] = '';
                }

                $lines[] = '以下の身分証明書の有効期限が近づいています。';
                $this->appendIdentityDocumentRows($lines, $identityDocuments, $today, $format);
            }
        }

        return rtrim(implode(PHP_EOL, $lines)) . PHP_EOL;
    }

    public function renderReport(?string $comment, string $schedule, string $format = self::FORMAT_TEXT): string
    {
        $comment = trim((string) $comment);
        if ($comment === '') {
            return rtrim($schedule) . PHP_EOL;
        }

        return sprintf("%s%s%s%s", $this->formatText($comment, $format), PHP_EOL . PHP_EOL, rtrim($schedule), PHP_EOL);
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
                && !$this->isChildLunch($item)
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
    private function upcomingItems(array $items, DateTimeImmutable $today): array
    {
        $tomorrow = $today->setTimezone($this->timezone)->modify('+1 day')->format('Y-m-d');

        return array_values(array_filter(
            $items,
            fn (array $item): bool => ($item['classification'] ?? null) === 'upcoming'
                && !$this->isIdentityDocument($item)
                && !$this->isChildLunch($item)
                && !$this->isTomorrowTimedTodoOrCalendar($item, $tomorrow)
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function tomorrowTimedTodoAndCalendarItems(array $items, DateTimeImmutable $today): array
    {
        $tomorrow = $today->setTimezone($this->timezone)->modify('+1 day')->format('Y-m-d');

        return array_values(array_filter(
            $items,
            fn (array $item): bool => $this->isTomorrowTimedTodoOrCalendar($item, $tomorrow)
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function childLunchItems(array $items): array
    {
        return array_values(array_filter(
            $items,
            fn (array $item): bool => $this->isChildLunch($item)
                && ($item['classification'] ?? null) === 'today'
        ));
    }

    /**
     * @param array<int, string> $lines
     */
    private function appendSectionHeader(array &$lines, string $title): void
    {
        $lines[] = self::SECTION_SEPARATOR;
        $lines[] = $title;
        $lines[] = self::SECTION_SEPARATOR;
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
    private function appendRows(array &$lines, array $items, bool $includeDate, bool $includeGroup, string $format): void
    {
        if ($items === []) {
            $lines[] = '・該当なし';
            return;
        }

        $items = $this->sortRows($items, $includeDate);
        foreach ($items as $item) {
            $lines[] = $this->rowText($item, $includeDate, $includeGroup, $format);
        }
    }

    /**
     * @param array<int, string> $lines
     * @param array<int, array<string, mixed>> $items
     */
    private function appendGroupedRows(
        array &$lines,
        array $items,
        DateTimeImmutable $today,
        bool $includeDate,
        bool $includeGroup,
        string $format
    ): void
    {
        if ($items === []) {
            $lines[] = '・該当なし';
            return;
        }

        $currentDate = null;
        foreach ($this->sortRows($items, $includeDate) as $item) {
            $start = $this->dateTimeFromItem($item, 'date_start');
            $dateKey = $this->dateGroupKey($start);
            if ($dateKey !== $currentDate) {
                if ($currentDate !== null) {
                    $lines[] = '';
                }

                $lines[] = $this->upcomingDateLabel($start, $today);
                $currentDate = $dateKey;
            }

            $lines[] = $this->rowText($item, false, $includeGroup, $format);
        }
    }

    /**
     * @param array<int, string> $lines
     * @param array<int, array<string, mixed>> $items
     */
    private function appendIdentityDocumentRows(array &$lines, array $items, DateTimeImmutable $today, string $format): void
    {
        $currentDate = null;
        foreach ($this->sortRows($items, true) as $item) {
            $start = $this->dateTimeFromItem($item, 'date_start');
            $dateKey = $this->dateGroupKey($start);
            if ($dateKey !== $currentDate) {
                if ($currentDate !== null) {
                    $lines[] = '';
                }

                $lines[] = $this->identityDocumentDateLabel($start, $today);
                $currentDate = $dateKey;
            }

            $lines[] = sprintf('・%s', $this->formatTitle($item, $format));
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function rowText(array $item, bool $includeDate, bool $includeGroup, string $format): string
    {
        $parts = [];
        $dateText = $this->dateText($item, $includeDate);
        if ($dateText !== '' && $dateText !== '終日') {
            $parts[] = $dateText;
        }

        $parts[] = $this->formatTitle($item, $format);

        if ($includeGroup) {
            $group = $this->displayGroupName($item);
            if ($group !== null) {
                $parts[] = $this->formatText($group, $format);
            }
        }

        return '・' . implode('｜', $parts);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function formatTitle(array $item, string $format): string
    {
        $title = (string) ($item['title'] ?? '無題');
        $url = $this->notionUrl($item);
        if ($url === null) {
            return $this->formatText($title, $format);
        }

        return match ($format) {
            self::FORMAT_SLACK => sprintf('<%s|%s>', $this->escapeSlackUrl($url), $this->escapeSlackText($title)),
            self::FORMAT_HTML => sprintf(
                '<a href="%s">%s</a>',
                htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            ),
            default => $title,
        };
    }

    private function formatText(string $text, string $format): string
    {
        return match ($format) {
            self::FORMAT_SLACK => $this->escapeSlackText($text),
            self::FORMAT_HTML => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            default => $text,
        };
    }

    /**
     * @param array<string, mixed> $item
     */
    private function notionUrl(array $item): ?string
    {
        $url = $item['url'] ?? null;
        if (!is_string($url) || trim($url) === '') {
            return null;
        }

        return trim($url);
    }

    private function escapeSlackText(string $text): string
    {
        return strtr($text, [
            '&' => '&amp;',
            '<' => '&lt;',
            '>' => '&gt;',
        ]);
    }

    private function escapeSlackUrl(string $url): string
    {
        return strtr($url, [
            '>' => '%3E',
            '|' => '%7C',
        ]);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function displayGroupName(array $item): ?string
    {
        $group = $this->groupName($item);
        if (in_array($group, [self::GROUP_OTHER, self::GROUP_LIFE, self::SOURCE_IDENTITY_DOCUMENT, self::GENRE_HOLIDAY], true)) {
            return null;
        }

        return $group;
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
            return '終日';
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

    private function dateGroupKey(?DateTimeImmutable $date): string
    {
        if ($date === null) {
            return '';
        }

        return $date->setTimezone($this->timezone)->format('Y-m-d');
    }

    private function upcomingDateLabel(?DateTimeImmutable $date, DateTimeImmutable $today): string
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
            1 => '明日 ',
            2 => 'あさって ',
            default => '',
        };

        return sprintf('%s%s（%s）', $relative, $targetDate->format('m/d'), $this->weekdayLabel($targetDate));
    }

    private function identityDocumentDateLabel(?DateTimeImmutable $date, DateTimeImmutable $today): string
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

        return sprintf('%s（%s） あと%d日', $targetDate->format('m/d'), $this->weekdayLabel($targetDate), $days);
    }

    private function weekdayLabel(DateTimeImmutable $date): string
    {
        $weekdays = ['日', '月', '火', '水', '木', '金', '土'];

        return $weekdays[(int) $date->setTimezone($this->timezone)->format('w')];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function childLunchText(array $item): ?string
    {
        $extra = is_array($item['extra'] ?? null) ? $item['extra'] : [];
        $title = trim((string) ($item['title'] ?? ''));
        if ($title === '' || $title === '無題') {
            return null;
        }

        $parts = [$title];

        $size = trim((string) ($extra['size'] ?? ''));
        if ($size !== '') {
            $parts[] = sprintf('[%s]', $size);
        }

        $note = trim((string) ($extra['note'] ?? ''));
        if ($note !== '') {
            $parts[] = $note;
        }

        return implode(' ', array_values(array_filter(
            $parts,
            static fn (string $part): bool => trim($part) !== ''
        )));
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
    private function isChildLunch(array $item): bool
    {
        return ($item['source_name'] ?? null) === self::SOURCE_CHILD_LUNCH;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isHoliday(array $item): bool
    {
        return trim((string) ($item['genre'] ?? '')) === self::GENRE_HOLIDAY;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isTomorrowTimedTodoOrCalendar(array $item, string $tomorrow): bool
    {
        if (($item['classification'] ?? null) !== 'upcoming'
            || ($item['date_has_time'] ?? false) !== true
            || (!$this->isTodo($item) && !$this->isCalendar($item))
        ) {
            return false;
        }

        $start = $this->dateTimeFromItem($item, 'date_start');

        return $start !== null && $start->format('Y-m-d') === $tomorrow;
    }
}
