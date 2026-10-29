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
    private const SOURCE_BIRTHDAY = '誕生日';
    private const GROUP_OTHER = 'その他';
    private const GROUP_SCHOOL = '学校';
    private const GROUP_LIFE = '生活';
    private const GENRE_HOLIDAY = '祝日';
    private const NOTION_MAX_ARRAY_ELEMENTS = 100;

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
            $activeRangeLabel = $this->activeRangeLabel($item, $today);
            $item['classification'] = $activeRangeLabel === null ? $this->classify($item, $today) : 'today';
            if ($activeRangeLabel !== null) {
                $item['active_range_label'] = $activeRangeLabel;
            }
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
    public function renderSchedule(array $items, DateTimeImmutable $today, string $format = self::FORMAT_TEXT, array $brief = []): string
    {
        $header = $this->formatText($this->todayHeader($today), $format);
        if ($format === self::FORMAT_HTML) {
            $header = '<p style="margin:0 0 16px;">' . $header . '</p>';
        }
        return $header . PHP_EOL . PHP_EOL
            . $this->renderDeliveryBlocks($this->renderNotionBlocks(null, $items, $today, $brief), $format) . PHP_EOL;
    }

    private function renderDeliveryBlocks(array $blocks, string $format): string
    {
        $lines = [];
        $inList = false;
        foreach ($blocks as $block) {
            $type = $block['type'];
            $content = $block[$type];
            if ($format === self::FORMAT_HTML && $inList && $type !== 'bulleted_list_item') {
                $lines[] = '</ul>';
                $inList = false;
            }
            $includeUrls = ($content['color'] ?? '') === 'gray';
            $text = $this->deliveryRichText($content['rich_text'], $format, $includeUrls);
            if ($type === 'callout') {
                $body = $this->renderDeliveryBlocks($content['children'] ?? [], $format);
                $lines[] = $format === self::FORMAT_HTML
                    ? '<div style="border:1px solid #d9dee7;border-radius:8px;padding:16px;margin:16px 0;overflow-wrap:anywhere;">'
                        . '<p style="margin:0 0 8px;">' . $text . '</p>' . PHP_EOL . $body . '</div>'
                    : $text . PHP_EOL . $body . PHP_EOL;
            } elseif ($type === 'bulleted_list_item') {
                if ($format === self::FORMAT_HTML) {
                    if (!$inList) {
                        $lines[] = '<ul style="margin:8px 0;padding-left:24px;">';
                        $inList = true;
                    }
                    $lines[] = '<li style="margin:6px 0;">' . $text . '</li>';
                } else {
                    $lines[] = '・' . $text;
                }
            } elseif ($type === 'paragraph') {
                $lines[] = $format === self::FORMAT_HTML
                    ? '<p style="margin:8px 0;' . ($includeUrls ? 'color:#6b7280;' : '') . '">' . $text . '</p>'
                    : $text;
            } else {
                throw new \InvalidArgumentException('Unsupported report block type: ' . $type);
            }
        }
        if ($inList) {
            $lines[] = '</ul>';
        }
        return implode(PHP_EOL, $lines);
    }

    private function deliveryRichText(array $fragments, string $format, bool $includePlainUrls): string
    {
        $text = '';
        foreach ($fragments as $fragment) {
            $value = $fragment['text']['content'];
            $url = $fragment['text']['link']['url'] ?? null;
            $formatted = $this->formatTitle(['title' => $value, 'url' => $url], $format);
            if ($format === self::FORMAT_TEXT && $url !== null && ($includePlainUrls || $value === '詳細')) {
                $formatted .= '：' . $url;
            }
            if (($fragment['annotations']['bold'] ?? false) === true) {
                $formatted = match ($format) {
                    self::FORMAT_HTML => '<strong>' . $formatted . '</strong>',
                    self::FORMAT_SLACK => preg_replace('/^(\s*)(.*?)(\s*)$/us', '$1*$2*$3', $formatted) ?? $formatted,
                    default => $formatted,
                };
            }
            if ($format === self::FORMAT_HTML) {
                $formatted = str_replace("\n", '<br>', $formatted);
                if (($fragment['annotations']['color'] ?? '') === 'gray') {
                    $formatted = '<span style="color:#6b7280;">' . $formatted . '</span>';
                }
            }
            $text .= $formatted;
        }
        return $text;
    }

    public function renderReport(?string $comment, string $schedule, string $format = self::FORMAT_TEXT): string
    {
        $comment = trim((string) $comment);
        if ($comment === '') {
            return rtrim($schedule) . PHP_EOL;
        }

        return sprintf("%s%s%s%s", $this->formatText($comment, $format), PHP_EOL . PHP_EOL, rtrim($schedule), PHP_EOL);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public function renderNotionBlocks(?string $comment, array $items, DateTimeImmutable $today, array $brief = []): array
    {
        $today = $today->setTimezone($this->timezone);
        $blocks = [];

        $todayRows = [];
        $this->appendNotionScheduleRows($todayRows, $this->todayTodoItems($items));
        if ($this->todayProjectTaskItems($items) !== []) {
            $todayRows[] = $this->notionLabel('本日期限');
            $this->appendNotionScheduleRows($todayRows, $this->todayProjectTaskItems($items));
        }
        if (($brief['source_status']['各案件のタスク'] ?? true) !== true) {
            $todayRows[] = $this->notionBullet($this->notionText('本日期限：取得不可'));
        }
        $this->appendNotionSection($blocks, '今日の予定', $todayRows);

        $trendRows = [];
        $groups = [];
        foreach ($brief['project_trends'] ?? [] as $trend) {
            $groups[$trend['project']][] = $trend;
        }
        foreach ($groups as $project => $trends) {
            $trendRows[] = $this->notionLabel((string) $project);
            foreach ($trends as $trend) {
                $this->appendNotionRichBullet($trendRows, $this->notionText($trend['timing'] . '｜' . $trend['text']));
                $source = $trend['source'];
                $sourceTitle = preg_replace('/^\d{4}(?:年|[-\/])\d{1,2}(?:月|[-\/])\d{1,2}(?:日)?\s*(?:\d{1,2}:\d{2})?\s*/u', '', $source['title']) ?? $source['title'];
                $trendRows[] = $this->notionParagraph($this->notionText('出典：' . $source['date_label'] . ' ' . $sourceTitle, $this->notionUrl($source)), 'gray');
            }
        }
        if ($trendRows === []) {
            $trendRows[] = $this->notionBullet($this->notionText(isset($brief['project_trends_error'])
                ? '案件動向：' . $brief['project_trends_error'] : '今日以降の予定は見つかりませんでした。'));
        }
        $this->appendNotionSection($blocks, '案件動向', $trendRows);

        $upcomingRows = [];
        $currentDate = null;
        $upcoming = array_merge($this->upcomingItems($items, $today), $this->tomorrowTimedTodoAndCalendarItems($items, $today));
        foreach ($this->sortNotionTableRows($upcoming) as $item) {
            $start = $this->dateTimeFromItem($item, 'date_start');
            $key = $this->dateGroupKey($start);
            if ($key !== $currentDate) {
                $upcomingRows[] = $this->notionLabel($this->upcomingDateLabel($start, $today));
                $currentDate = $key;
            }
            $this->appendNotionScheduleRows($upcomingRows, [$item]);
        }
        $this->appendNotionSection($blocks, '以降の予定', $upcomingRows);

        $healthGroups = $this->healthGroups($items);
        if ($healthGroups !== []) {
            $healthRows = [];
            foreach ($healthGroups as $label => $healthItems) {
                foreach ($healthItems as $healthItem) {
                    $row = $this->healthRow($label, $healthItem);
                    $row['text'] .= $this->healthComparisonText($healthItem);
                    $healthRows[] = $this->notionBullet($this->notionLinkedRow($row));
                }
            }
            $this->appendNotionSection($blocks, '健康', $healthRows);
        }

        $childLunches = $this->childLunchItems($items);
        $identityDocuments = $this->identityDocumentItems($items);
        $birthdays = $this->birthdayItems($items);
        if ($childLunches !== [] || $identityDocuments !== [] || $birthdays !== []) {
            $otherRows = [];

            foreach ($this->sortRows($childLunches, true) as $childLunch) {
                $text = ($childLunch['status'] ?? '') === '利用しない'
                    ? 'お弁当は利用しません。' : $this->childLunchText($childLunch);
                if ($text !== null) {
                    $this->appendNotionRichBullet($otherRows, $this->notionText('子どものお弁当：' . $text));
                }
            }

            if ($identityDocuments !== []) {
                $otherRows[] = $this->notionLabel('身分証明書の有効期限');
                $currentDate = null;
                foreach ($this->sortRows($identityDocuments, true) as $item) {
                    $start = $this->dateTimeFromItem($item, 'date_start');
                    $dateKey = $this->dateGroupKey($start);
                    if ($dateKey !== $currentDate) {
                        $otherRows[] = $this->notionLabel($this->identityDocumentDateLabel($start, $today));
                        $currentDate = $dateKey;
                    }

                    $this->appendNotionRichBullet($otherRows, $this->notionTitleText($item));
                }
            }

            if ($birthdays !== []) {
                foreach ($this->sortRows($birthdays, true) as $birthday) {
                    $this->appendNotionRichBullet($otherRows, $this->notionText('誕生日：' . substr($this->birthdayText($birthday), strlen('・'))));
                }
            }
            $this->appendNotionSection($blocks, 'その他', $otherRows);
        }

        return $blocks;
    }

    public function renderNotionMarkdown(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            $type = $block['type'];
            $content = $block[$type];
            $text = '';
            foreach ($content['rich_text'] as $fragment) {
                $value = htmlspecialchars($fragment['text']['content'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $value = preg_replace('/([\\\\`$*_{}\[\]()#+.!|>~\-])/u', '\\\\$1', $value) ?? $value;
                $value = str_replace("\n", '<br />', $value);
                $url = $fragment['text']['link']['url'] ?? null;
                if ($url !== null) {
                    $value = '[' . $value . '](' . strtr($url, ['(' => '%28', ')' => '%29']) . ')';
                }
                if (($fragment['annotations']['bold'] ?? false) === true) {
                    $value = '<b>' . $value . '</b>';
                }
                if (($fragment['annotations']['color'] ?? '') === 'gray') {
                    $value = '<span color="gray">' . $value . '</span>';
                }
                $text .= $value;
            }
            $parts[] = match ($type) {
                // The block API inserts a default icon; Markdown v2 supports a truly empty icon.
                'callout' => '<callout icon="">' . "\n" . $text . "\n\n" . $this->renderNotionMarkdown($content['children'] ?? []) . "\n</callout>",
                'bulleted_list_item' => '- ' . $text,
                'paragraph' => ($content['color'] ?? '') === 'gray' ? '<p color="gray">' . "\n" . $text . "\n</p>" : $text,
                default => throw new \InvalidArgumentException('Unsupported report block type: ' . $type),
            };
        }
        return implode("\n\n", $parts);
    }

    private function appendNotionSection(array &$blocks, string $title, array $children): void
    {
        $children = $children === [] ? [$this->notionBullet($this->notionText('該当なし'))] : $children;
        foreach (array_chunk($children, self::NOTION_MAX_ARRAY_ELEMENTS) as $index => $chunk) {
            $heading = $this->notionText($title . ($index === 0 ? '' : '（続き）'));
            foreach ($heading as &$text) {
                $text['annotations'] = ['bold' => true];
            }
            unset($text);
            $blocks[] = $this->notionCallout(null, $heading, $chunk);
        }
    }

    private function appendNotionRichBullet(array &$blocks, array $richText): void
    {
        foreach (array_chunk($richText, self::NOTION_MAX_ARRAY_ELEMENTS) as $index => $chunk) {
            $blocks[] = $index === 0 ? $this->notionBullet($chunk) : $this->notionParagraph($chunk);
        }
    }

    private function notionParagraph(array $richText, string $color = 'default'): array
    {
        return ['object' => 'block', 'type' => 'paragraph', 'paragraph' => ['rich_text' => $richText, 'color' => $color]];
    }

    private function appendNotionScheduleRows(array &$blocks, array $items): void
    {
        foreach ($this->sortNotionTableRows($items) as $item) {
            $text = [];
            $time = $this->dateText($item, false);
            if ($time !== '' && $time !== '終日') {
                $text = $this->notionText($time . '  ');
                foreach ($text as &$fragment) {
                    $fragment['annotations'] = ['bold' => true];
                }
                unset($fragment);
            }
            array_push($text, ...$this->notionTitleText($item));
            $group = $this->displayGroupName($item);
            if ($group !== null) {
                foreach ($this->notionText("\n" . $group) as $fragment) {
                    $fragment['annotations'] = ['color' => 'gray'];
                    $text[] = $fragment;
                }
            }
            $this->appendNotionRichBullet($blocks, $text);
        }
    }

    private function healthComparisonText(array $item): string
    {
        $metric = $item['health_metric'] ?? '';
        $previous = $item['previous_measurement'] ?? null;
        if (!in_array($metric, ['weight', 'steps'], true) || !is_array($previous)) {
            return '';
        }
        $currentValue = $item['numbers'][$metric] ?? null;
        $previousValue = $previous['numbers'][$metric] ?? null;
        if (!is_numeric($currentValue) || !is_numeric($previousValue)) {
            return '';
        }
        $difference = round((float) $currentValue - (float) $previousValue, 2);
        $arrow = $difference > 0 ? '↗' : ($difference < 0 ? '↘' : '→');
        $sign = $difference > 0 ? '＋' : ($difference < 0 ? '−' : '');
        $comparison = $metric === 'steps' && isset($item['date'], $previous['date'])
            && $previous['date'] === (new DateTimeImmutable($item['date'], $this->timezone))->modify('-1 day')->format('Y-m-d')
            ? '前日比' : '前回比';
        return sprintf(' %s（%s %s%s%s）', $arrow, $comparison, $sign, $this->healthNumber(abs($difference)), $metric === 'weight' ? 'kg' : '歩');
    }

    private function healthRow(string $label, array $item): array
    {
        return ['text' => $label . '：' . substr($this->healthText($item), strlen('・')), 'label' => '詳細', 'url' => $item['detail_url'] ?? null];
    }

    private function notionLinkedRow(array $row): array
    {
        $text = $this->notionText($row['text']);
        $url = $this->notionUrl($row);
        return $url === null ? $text : array_merge($text, $this->notionText('｜'), $this->notionText($row['label'], $url));
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
        $upcoming = [];
        foreach ($items as $item) {
            $rangeOccurrences = $this->upcomingRangeOccurrences($item, $today);
            if ($rangeOccurrences !== null) {
                array_push($upcoming, ...$rangeOccurrences);
                continue;
            }

            if (($item['classification'] ?? null) === 'upcoming') {
                $upcoming[] = $item;
            }
        }

        return array_values(array_filter(
            $upcoming,
            fn (array $item): bool => !$this->isIdentityDocument($item)
                && !$this->isChildLunch($item)
                && !$this->isBirthday($item)
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

    /** @param array<int, array<string, mixed>> $items */
    private function birthdayItems(array $items): array
    {
        return array_values(array_filter($items, fn (array $item): bool => $this->isBirthday($item)));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function healthGroups(array $items): array
    {
        $definitions = [
            'weight' => '体重',
            'steps' => '歩数',
            'vital' => 'バイタル',
        ];
        $groups = [];
        foreach ($definitions as $metric => $label) {
            $matches = array_values(array_filter(
                $items,
                static fn (array $item): bool => ($item['health_metric'] ?? null) === $metric
            ));
            if ($matches === []) {
                continue;
            }

            usort($matches, function (array $left, array $right): int {
                return [
                    $this->sortDateValue($right),
                    (string) ($right['created_time'] ?? ''),
                ] <=> [
                    $this->sortDateValue($left),
                    (string) ($left['created_time'] ?? ''),
                ];
            });
            $groups[$label] = array_slice($matches, 0, 1);
        }

        return $groups;
    }

    /** @param array<string, mixed> $item */
    private function healthText(array $item): string
    {
        $date = $this->healthDateText($item);
        $numbers = is_array($item['numbers'] ?? null) ? $item['numbers'] : [];

        return match ($item['health_metric'] ?? null) {
            'weight' => sprintf('・%s｜%skg', $date, $this->healthNumber($numbers['weight'] ?? null)),
            'steps' => sprintf('・%s｜%s歩', $date, $this->healthNumber($numbers['steps'] ?? null)),
            'vital' => sprintf(
                '・%s｜%s/%smmHg｜脈拍%s回/分',
                $date,
                $this->healthNumber($numbers['systolic'] ?? null),
                $this->healthNumber($numbers['diastolic'] ?? null),
                $this->healthNumber($numbers['pulse'] ?? null)
            ),
            default => sprintf('・%s', $date),
        };
    }

    /** @param array<string, mixed> $item */
    private function healthDateText(array $item): string
    {
        $date = $this->dateTimeFromItem($item, 'date_start');
        if ($date === null) {
            return '日付不明';
        }

        return $date->format(($item['health_metric'] ?? '') !== 'steps' && ($item['date_has_time'] ?? false) === true ? 'n月j日 H:i' : 'n月j日');
    }

    private function healthNumber(mixed $value): string
    {
        if (!is_int($value) && !is_float($value)) {
            return '-';
        }

        if ((float) $value === floor((float) $value)) {
            return number_format((float) $value, 0, '.', ',');
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.');
    }

    /** @param array<string, mixed> $item */
    private function birthdayText(array $item, string $format = self::FORMAT_TEXT): string
    {
        $birthdate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($item['extra']['birthdate'] ?? ''), $this->timezone);
        $date = $birthdate ? $birthdate->format('Y年m月d日') : '生年月日不明';
        $age = trim((string) ($item['extra']['age'] ?? ''));

        return sprintf('・%s｜%s｜%s', $this->formatText((string) ($item['title'] ?? '無題'), $format), $date, $age === '' ? '年齢不明' : $age . '歳');
    }

    /**
     * @param array<string, mixed> $item
     */
    private function formatTitle(array $item, string $format): string
    {
        $title = $this->displayTitle($item);
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

        $url = trim($url);
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function notionLabel(string $text): array
    {
        $richText = $this->notionText($text);
        foreach ($richText as &$fragment) {
            $fragment['annotations'] = ['bold' => true];
        }
        unset($fragment);
        return $this->notionParagraph($richText);
    }

    /**
     * @param array<int, array<string, mixed>> $richText
     * @param array<int, array<string, mixed>> $children
     * @return array<string, mixed>
     */
    private function notionBullet(array $richText): array
    {
        return [
            'object' => 'block',
            'type' => 'bulleted_list_item',
            'bulleted_list_item' => [
                'rich_text' => $richText,
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $richText
     * @return array<string, mixed>
     */
    private function notionCallout(?string $emoji, array $richText, array $children = []): array
    {
        $callout = [
            'object' => 'block',
            'type' => 'callout',
            'callout' => [
                'rich_text' => $richText,
                'color' => 'default',
            ],
        ];

        if ($children !== []) {
            $callout['callout']['children'] = $children;
        }

        if ($emoji !== null) {
            $callout['callout']['icon'] = [
                'type' => 'emoji',
                'emoji' => $emoji,
            ];
        }

        return $callout;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<int, array<string, mixed>>
     */
    private function notionTitleText(array $item): array
    {
        $title = $this->displayTitle($item);
        return $this->notionText($title, $this->notionUrl($item));
    }

    /**
     * @param array<string, mixed> $item
     */
    private function displayTitle(array $item): string
    {
        $title = (string) ($item['title'] ?? '無題');
        $activeRangeLabel = trim((string) ($item['active_range_label'] ?? ''));

        return $activeRangeLabel === '' ? $title : sprintf('%s（%s）', $title, $activeRangeLabel);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function activeRangeLabel(array $item, DateTimeImmutable $today): ?string
    {
        $start = $this->dateTimeFromItem($item, 'date_start');
        $end = $this->dateTimeFromItem($item, 'date_end');
        if ($start === null || $end === null) {
            return null;
        }

        $start = $start->setTime(0, 0);
        $end = $end->setTime(0, 0);
        $today = $today->setTimezone($this->timezone)->setTime(0, 0);
        if ($end <= $start || $today < $start || $today > $end) {
            return null;
        }

        $progress = $today == $end ? '最終日' : sprintf('%d日目', $start->diff($today)->days + 1);

        return sprintf('%s／%sまで', $progress, $end->format('n月j日'));
    }

    /**
     * @param array<string, mixed> $item
     * @return null|array<int, array<string, mixed>>
     */
    private function upcomingRangeOccurrences(array $item, DateTimeImmutable $today): ?array
    {
        $start = $this->dateTimeFromItem($item, 'date_start');
        $end = $this->dateTimeFromItem($item, 'date_end');
        $windowEnd = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            (string) ($item['report_window_end'] ?? ''),
            $this->timezone
        );
        if ($start === null || $end === null || !$windowEnd) {
            return null;
        }

        $start = $start->setTime(0, 0);
        $end = $end->setTime(0, 0);
        if ($end <= $start) {
            return null;
        }

        $firstOccurrence = $today->setTimezone($this->timezone)->setTime(0, 0)->modify('+1 day');
        if ($start > $firstOccurrence) {
            $firstOccurrence = $start;
        }

        $lastOccurrence = $end < $windowEnd ? $end : $windowEnd;
        $occurrences = [];
        for ($date = $firstOccurrence; $date <= $lastOccurrence; $date = $date->modify('+1 day')) {
            $occurrence = $item;
            $occurrence['date'] = $date->format('Y-m-d');
            $occurrence['date_start'] = $date->format('Y-m-d');
            $occurrence['date_has_time'] = false;
            $occurrence['classification'] = 'upcoming';
            $occurrence['active_range_label'] = $this->activeRangeLabel($item, $date);
            $occurrences[] = $occurrence;
        }

        return $occurrences;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function notionText(string $text, ?string $href = null): array
    {
        $text = $text === '' ? ' ' : $text;
        $chunks = $this->utf8ByteChunks($text, 1800);

        return array_map(static function (string $chunk) use ($href): array {
            return [
                'type' => 'text',
                'text' => [
                    'content' => $chunk,
                    'link' => $href === null ? null : ['url' => $href],
                ],
            ];
        }, $chunks);
    }

    /**
     * @return array<int, string>
     */
    private function utf8ByteChunks(string $text, int $maxBytes): array
    {
        if ($text === '') {
            return [' '];
        }

        if (function_exists('mb_strcut')) {
            $chunks = [];
            $offset = 0;
            $length = strlen($text);
            while ($offset < $length) {
                $chunk = mb_strcut($text, $offset, $maxBytes, 'UTF-8');
                if ($chunk === '') {
                    break;
                }

                $chunks[] = $chunk;
                $offset += strlen($chunk);
            }

            return $chunks === [] ? [' '] : $chunks;
        }

        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false || $characters === []) {
            return [$text];
        }

        $chunks = [];
        $chunk = '';
        foreach ($characters as $character) {
            if ($chunk !== '' && strlen($chunk . $character) > $maxBytes) {
                $chunks[] = $chunk;
                $chunk = '';
            }

            $chunk .= $character;
        }

        if ($chunk !== '') {
            $chunks[] = $chunk;
        }

        return $chunks === [] ? [' '] : $chunks;
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
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function sortNotionTableRows(array $items): array
    {
        usort($items, function (array $left, array $right): int {
            $leftDate = $this->dateTimeFromItem($left, 'date_start');
            $rightDate = $this->dateTimeFromItem($right, 'date_start');
            $leftDateKey = $this->dateGroupKey($leftDate);
            $rightDateKey = $this->dateGroupKey($rightDate);
            if ($leftDateKey !== $rightDateKey) {
                return $leftDateKey <=> $rightDateKey;
            }

            $leftHasTime = ($left['date_has_time'] ?? false) === true;
            $rightHasTime = ($right['date_has_time'] ?? false) === true;
            if ($leftHasTime !== $rightHasTime) {
                return $leftHasTime <=> $rightHasTime;
            }

            $leftSortDate = $leftDate?->format(DATE_ATOM) ?? '9999-12-31T23:59:59+00:00';
            $rightSortDate = $rightDate?->format(DATE_ATOM) ?? '9999-12-31T23:59:59+00:00';
            if ($leftSortDate !== $rightSortDate) {
                return $leftSortDate <=> $rightSortDate;
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
        if (($extra['shop'] ?? '') === 'RAMEN KIMURA') {
            $note = preg_replace('/\A合計金額: [^、]*(?:、|$)/u', '', $note) ?? $note;
        }
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

    /** @param array<string, mixed> $item */
    private function isBirthday(array $item): bool
    {
        return ($item['source_name'] ?? null) === self::SOURCE_BIRTHDAY;
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
