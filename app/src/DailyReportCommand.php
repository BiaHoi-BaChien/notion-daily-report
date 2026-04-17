<?php

declare(strict_types=1);

namespace App;

use App\Exception\ConfigException;
use App\Exception\OpenAIException;
use App\Exception\PropertyExtractionException;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class DailyReportCommand
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly NotionClientInterface $notionClient,
        private readonly PropertyExtractor $propertyExtractor,
        private readonly DateFilter $dateFilter,
        private readonly ReportBuilder $reportBuilder,
        private readonly Logger $logger,
        private readonly DateTimeZone $timezone,
        private readonly bool $writeErrors = true,
        private readonly ?SlackNotifierInterface $slackNotifier = null,
        private readonly ?OpenAIClientInterface $openAIClient = null,
        private readonly ?MailNotifierInterface $mailNotifier = null
    ) {
    }

    /**
     * @var array<string, ?string>
     */
    private array $pageTitleCache = [];

    /**
     * @param array<int, string> $argv
     */
    public function run(array $argv): int
    {
        $startedAt = new DateTimeImmutable('now', $this->timezone);
        $startedAtFloat = microtime(true);
        $runId = uniqid('daily_report_', true);
        $this->logger->info('daily_report_start', [
            'run_id' => $runId,
            'started_at' => $startedAt->format(DATE_ATOM),
            'source_count' => count($this->config['sources']),
        ]);

        try {
            $today = $this->resolveToday($argv);
            $sources = $this->enabledSources();
            $items = [];
            $successfulSources = 0;
            $failedSources = 0;
            $fetchedCount = 0;
            $extractedCount = 0;
            $extractionErrorCount = 0;
            $filteredBeforeClassificationCount = 0;

            foreach ($sources as $source) {
                $sourceStartedAt = microtime(true);
                $this->logger->info('source_processing_start', [
                    'run_id' => $runId,
                    'source' => $source['name'] ?? null,
                    'data_source_id' => $source['data_source_id'] ?? null,
                    'date_property' => $source['date_property'] ?? null,
                    'lookback_days' => $source['lookback_days'] ?? null,
                    'lookahead_days' => $source['lookahead_days'] ?? null,
                ]);

                try {
                    $sourceItems = $this->processSource($source, $today, $runId);
                    $items = array_merge($items, $sourceItems['items']);
                    $fetchedCount += $sourceItems['fetched_count'];
                    $extractedCount += $sourceItems['extracted_count'];
                    $extractionErrorCount += $sourceItems['extraction_error_count'];
                    $filteredBeforeClassificationCount += $sourceItems['filtered_count'];
                    $successfulSources++;

                    $this->logger->info('source_processing_complete', [
                        'run_id' => $runId,
                        'source' => $source['name'] ?? null,
                        'data_source_id' => $source['data_source_id'] ?? null,
                        'fetched_count' => $sourceItems['fetched_count'],
                        'extracted_count' => $sourceItems['extracted_count'],
                        'extraction_error_count' => $sourceItems['extraction_error_count'],
                        'filtered_count' => $sourceItems['filtered_count'],
                        'duration_ms' => $this->durationMs($sourceStartedAt),
                    ]);
                } catch (Throwable $exception) {
                    $failedSources++;
                    $this->logger->error('source_processing_failed', [
                        'run_id' => $runId,
                        'source' => $source['name'] ?? null,
                        'data_source_id' => $source['data_source_id'] ?? null,
                        'exception_class' => $exception::class,
                        'error' => $exception->getMessage(),
                        'duration_ms' => $this->durationMs($sourceStartedAt),
                    ]);
                }
            }

            if ($successfulSources === 0) {
                throw new ConfigException('All enabled sources failed.');
            }

            $classified = $this->reportBuilder->classifyAndSort($items, $today);
            $schedule = $this->reportBuilder->renderSchedule($classified, $today);
            $apiSendCount = $this->shouldSummarizeWithOpenAI($classified) ? 1 : 0;

            $this->logger->info('daily_report_filtered', [
                'run_id' => $runId,
                'source_count' => count($sources),
                'successful_source_count' => $successfulSources,
                'failed_source_count' => $failedSources,
                'fetched_count' => $fetchedCount,
                'extracted_count' => $extractedCount,
                'extraction_error_count' => $extractionErrorCount,
                'filtered_before_classification_count' => $filteredBeforeClassificationCount,
                'filtered_count' => count($classified),
                'classification_counts' => $this->classificationCounts($classified),
                'api_send_count' => $apiSendCount,
            ]);

            $report = $this->buildNotificationReport($classified, $schedule, $runId);
            echo $report;
            $slackStatus = $this->sendSlackReport($report, $runId);
            $mailStatus = $this->sendMailReport($report, $today, $runId);

            $this->logger->info('daily_report_end', [
                'run_id' => $runId,
                'ended_at' => (new DateTimeImmutable('now', $this->timezone))->format(DATE_ATOM),
                'exit_code' => 0,
                'duration_ms' => $this->durationMs($startedAtFloat),
                'report_size_bytes' => strlen($report),
                'slack_status' => $slackStatus,
                'mail_status' => $mailStatus,
            ]);

            return 0;
        } catch (Throwable $exception) {
            $this->logger->error('daily_report_failed', [
                'run_id' => $runId,
                'ended_at' => (new DateTimeImmutable('now', $this->timezone))->format(DATE_ATOM),
                'exception_class' => $exception::class,
                'error' => $exception->getMessage(),
                'duration_ms' => $this->durationMs($startedAtFloat),
            ]);

            if ($this->writeErrors) {
                fwrite(STDERR, 'Fatal: ' . $exception->getMessage() . PHP_EOL);
            }
            return 1;
        }
    }

    /**
     * @param array<int, string> $argv
     */
    private function resolveToday(array $argv): DateTimeImmutable
    {
        $date = null;
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--date=')) {
                $date = substr($arg, 7);
                break;
            }
        }

        if ($date === null || $date === '') {
            return new DateTimeImmutable('today', $this->timezone);
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->timezone);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new ConfigException('Invalid --date value. Expected YYYY-MM-DD.');
        }

        return $parsed;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function enabledSources(): array
    {
        $enabledSources = [];
        foreach ($this->config['sources'] as $source) {
            if (($source['enabled'] ?? true) !== false) {
                $enabledSources[] = $source;
            }
        }

        if ($enabledSources === []) {
            throw new ConfigException('No enabled source found.');
        }

        return $enabledSources;
    }

    /**
     * @param array<string, mixed> $source
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     fetched_count: int,
     *     extracted_count: int,
     *     extraction_error_count: int,
     *     filtered_count: int
     * }
     */
    private function processSource(array $source, DateTimeImmutable $today, string $runId): array
    {
        $this->validateSource($source);

        $filter = $this->notionDateFilter($source, $today);
        $pages = $this->notionClient->queryDataSource(
            (string) $source['data_source_id'],
            $source['filter_property_ids'] ?? [],
            $filter
        );
        $this->logger->info('notion_fetch_complete', [
            'run_id' => $runId,
            'source' => $source['name'],
            'data_source_id' => $source['data_source_id'],
            'fetched_count' => count($pages),
            'date_filter' => $this->dateFilterForLog($filter),
        ]);

        $items = [];
        $extractedCount = 0;
        $extractionErrorCount = 0;
        foreach ($pages as $page) {
            try {
                $item = $this->propertyExtractor->extract($page, $source);
                $extractedCount++;

                $filteredItem = $this->dateFilter->filter([$item], $source, $today);
                if ($filteredItem !== []) {
                    $items[] = $filteredItem[0];
                }
            } catch (PropertyExtractionException $exception) {
                $extractionErrorCount++;
                $this->logger->error('property_extraction_failed', [
                    'run_id' => $runId,
                    'source' => $source['name'],
                    'page_id' => $page['id'] ?? null,
                    'exception_class' => $exception::class,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $items = $this->resolveProjectRelationTitles($items, (string) $source['name'], $runId);
        $this->logger->info('source_filtered', [
            'run_id' => $runId,
            'source' => $source['name'],
            'data_source_id' => $source['data_source_id'],
            'extracted_count' => $extractedCount,
            'extraction_error_count' => $extractionErrorCount,
            'filtered_count' => count($items),
        ]);

        return [
            'items' => $items,
            'fetched_count' => count($pages),
            'extracted_count' => $extractedCount,
            'extraction_error_count' => $extractionErrorCount,
            'filtered_count' => count($items),
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function notionDateFilter(array $source, DateTimeImmutable $today): array
    {
        $dateProperty = trim((string) ($source['date_property'] ?? ''));
        if ($dateProperty === '') {
            return [];
        }

        $lookbackDays = max(0, (int) ($source['lookback_days'] ?? 0));
        $lookaheadDays = max(0, (int) ($source['lookahead_days'] ?? 0));
        $today = $today->setTimezone($this->timezone);
        $start = $today->modify(sprintf('-%d days', $lookbackDays))->format('Y-m-d');
        $end = $today->modify(sprintf('+%d days', $lookaheadDays))->format('Y-m-d');

        return [
            'and' => [
                [
                    'property' => $dateProperty,
                    'date' => [
                        'on_or_after' => $start,
                    ],
                ],
                [
                    'property' => $dateProperty,
                    'date' => [
                        'on_or_before' => $end,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function resolveProjectRelationTitles(array $items, string $sourceName, string $runId): array
    {
        foreach ($items as $index => $item) {
            if (trim((string) ($item['project'] ?? '')) !== '') {
                continue;
            }

            $relationIds = $item['project_relation_ids'] ?? [];
            if (!is_array($relationIds) || $relationIds === []) {
                continue;
            }

            $titles = [];
            foreach ($relationIds as $relationId) {
                if (!is_string($relationId) || trim($relationId) === '') {
                    continue;
                }

                $title = $this->resolvePageTitle($relationId, $sourceName, (string) ($item['title'] ?? ''), $runId);
                if ($title !== null) {
                    $titles[] = $title;
                }
            }

            if ($titles !== []) {
                $items[$index]['project'] = implode('、', $titles);
            }
        }

        return $items;
    }

    private function resolvePageTitle(string $pageId, string $sourceName, string $itemTitle, string $runId): ?string
    {
        $pageId = trim($pageId);
        if ($pageId === '') {
            return null;
        }

        if (array_key_exists($pageId, $this->pageTitleCache)) {
            return $this->pageTitleCache[$pageId];
        }

        try {
            $page = $this->notionClient->retrievePage($pageId);
            $title = $this->extractPageTitle($page);
            $this->pageTitleCache[$pageId] = $title;
            return $title;
        } catch (Throwable $exception) {
            $this->logger->error('project_relation_resolution_failed', [
                'run_id' => $runId,
                'source' => $sourceName,
                'item_title' => $itemTitle,
                'relation_page_id' => $pageId,
                'exception_class' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
            $this->pageTitleCache[$pageId] = null;
            return null;
        }
    }

    /**
     * @param array<string, mixed> $page
     */
    private function extractPageTitle(array $page): ?string
    {
        $properties = $page['properties'] ?? null;
        if (!is_array($properties)) {
            return null;
        }

        foreach ($properties as $property) {
            if (!is_array($property) || ($property['type'] ?? null) !== 'title') {
                continue;
            }

            $chunks = $property['title'] ?? [];
            if (!is_array($chunks)) {
                return null;
            }

            $title = '';
            foreach ($chunks as $chunk) {
                if (is_array($chunk)) {
                    $title .= (string) ($chunk['plain_text'] ?? '');
                }
            }

            $title = trim($title);
            return $title === '' ? null : $title;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function validateSource(array $source): void
    {
        foreach (['name', 'role', 'data_source_id', 'date_property', 'lookback_days', 'lookahead_days', 'exclude_statuses'] as $key) {
            if (!array_key_exists($key, $source)) {
                throw new ConfigException(sprintf('Missing source key "%s".', $key));
            }
        }

        if (trim((string) $source['data_source_id']) === '') {
            throw new ConfigException(sprintf('Source "%s" is missing data_source_id.', $source['name']));
        }

        if (!is_array($source['exclude_statuses'])) {
            throw new ConfigException(sprintf('Source "%s" exclude_statuses must be an array.', $source['name']));
        }
    }

    private function sendSlackReport(string $report, string $runId): string
    {
        if (!$this->isFeatureEnabled('slack')) {
            $this->logger->info('slack_notification_skipped', [
                'run_id' => $runId,
                'reason' => 'Slack notification is disabled.',
            ]);
            return 'disabled';
        }

        if ($this->slackNotifier === null || !$this->slackNotifier->isConfigured()) {
            $this->logger->info('slack_notification_skipped', [
                'run_id' => $runId,
                'reason' => 'SLACK_WEBHOOK_URL is not configured.',
            ]);
            return 'not_configured';
        }

        try {
            $this->slackNotifier->send($report);
        } catch (Throwable $exception) {
            $this->logger->error('slack_notification_failed', [
                'run_id' => $runId,
                'exception_class' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
            return 'failed';
        }

        $this->logger->info('slack_notification_sent', [
            'run_id' => $runId,
            'report_size_bytes' => strlen($report),
        ]);
        return 'sent';
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function buildNotificationReport(array $items, string $schedule, string $runId): string
    {
        if (!$this->shouldSummarizeWithOpenAI($items)) {
            $this->logger->info('openai_summary_skipped', [
                'run_id' => $runId,
                'reason' => $this->openAISkipReason($items),
            ]);
            return $this->reportBuilder->renderReport(null, $schedule);
        }

        $this->logger->info('openai_payload_prepared', [
            'run_id' => $runId,
            'api_send_count' => 1,
            'schedule_size_bytes' => strlen($schedule),
        ]);

        try {
            $summary = $this->openAIClient?->summarize($schedule) ?? '';
        } catch (OpenAIException $exception) {
            $this->logger->error('openai_summary_failed', [
                'run_id' => $runId,
                'exception_class' => $exception::class,
                'error' => $exception->getMessage(),
                'fallback' => 'local_report',
            ]);
            return $this->reportBuilder->renderReport(null, $schedule);
        }

        $this->logger->info('openai_summary_complete', [
            'run_id' => $runId,
            'summary_length_bytes' => strlen($summary),
        ]);

        return $this->reportBuilder->renderReport($summary, $schedule);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function shouldSummarizeWithOpenAI(array $items): bool
    {
        return $items !== []
            && $this->isFeatureEnabled('openai')
            && $this->openAIClient !== null
            && $this->openAIClient->isConfigured();
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function openAISkipReason(array $items): string
    {
        if ($items === []) {
            return 'No filtered items.';
        }

        if (!$this->isFeatureEnabled('openai')) {
            return 'OpenAI summary is disabled.';
        }

        return 'OPENAI_API_KEY is not configured.';
    }

    private function sendMailReport(string $report, DateTimeImmutable $today, string $runId): string
    {
        if (!$this->isFeatureEnabled('mail')) {
            $this->logger->info('mail_notification_skipped', [
                'run_id' => $runId,
                'reason' => 'Mail notification is disabled.',
            ]);
            return 'disabled';
        }

        if ($this->mailNotifier === null || !$this->mailNotifier->isConfigured()) {
            $this->logger->info('mail_notification_skipped', [
                'run_id' => $runId,
                'reason' => 'SMTP_HOST, MAIL_FROM, or MAIL_TO is not configured.',
            ]);
            return 'not_configured';
        }

        $subject = sprintf('Notion Daily Report %s', $today->setTimezone($this->timezone)->format('Y-m-d'));
        try {
            $this->mailNotifier->send($subject, $report);
        } catch (Throwable $exception) {
            $this->logger->error('mail_notification_failed', [
                'run_id' => $runId,
                'exception_class' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
            return 'failed';
        }

        $this->logger->info('mail_notification_sent', [
            'run_id' => $runId,
            'subject' => $subject,
            'report_size_bytes' => strlen($report),
        ]);
        return 'sent';
    }

    private function isFeatureEnabled(string $key): bool
    {
        $section = $this->config[$key] ?? [];
        if (!is_array($section) || !array_key_exists('enabled', $section)) {
            return true;
        }

        return $section['enabled'] === true;
    }

    private function durationMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, int>
     */
    private function classificationCounts(array $items): array
    {
        $counts = [
            'overdue' => 0,
            'today' => 0,
            'upcoming' => 0,
            'recent_past' => 0,
        ];

        foreach ($items as $item) {
            $classification = (string) ($item['classification'] ?? '');
            if (!array_key_exists($classification, $counts)) {
                $counts[$classification] = 0;
            }

            $counts[$classification]++;
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    private function dateFilterForLog(array $filter): array
    {
        $conditions = $filter['and'] ?? null;
        if (!is_array($conditions)) {
            return [];
        }

        $result = [];
        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }

            $property = $condition['property'] ?? null;
            if (is_string($property)) {
                $result['property'] = $property;
            }

            $date = $condition['date'] ?? null;
            if (is_array($date)) {
                foreach (['on_or_after', 'on_or_before'] as $key) {
                    if (isset($date[$key]) && is_string($date[$key])) {
                        $result[$key] = $date[$key];
                    }
                }
            }
        }

        return $result;
    }
}
