<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;

final class ReportBuilder
{
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
    public function renderConsole(array $items, DateTimeImmutable $today): string
    {
        $lines = [
            sprintf('Notion Daily Report (%s)', $today->setTimezone($this->timezone)->format('Y-m-d')),
            '',
        ];

        if ($items === []) {
            $lines[] = '該当する項目はありません。';
            return implode(PHP_EOL, $lines) . PHP_EOL;
        }

        $groups = [
            'overdue' => '期限超過',
            'today' => '今日やること',
            'upcoming' => '近日中に準備したほうがいいこと',
            'recent_past' => '今日確認したほうがいいこと',
        ];

        foreach ($groups as $classification => $heading) {
            $groupItems = array_values(array_filter(
                $items,
                static fn (array $item): bool => ($item['classification'] ?? null) === $classification
            ));

            if ($groupItems === []) {
                continue;
            }

            $lines[] = '## ' . $heading;
            foreach ($groupItems as $item) {
                $status = ($item['status'] ?? null) ? sprintf(' / %s', $item['status']) : '';
                $url = ($item['url'] ?? null) ? sprintf(' / %s', $item['url']) : '';
                $lines[] = sprintf(
                    '- [%s] %s%s%s',
                    $item['date'] ?? '日付なし',
                    $item['title'] ?? '無題',
                    $status,
                    $url
                );
            }
            $lines[] = '';
        }

        return rtrim(implode(PHP_EOL, $lines)) . PHP_EOL;
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
}
