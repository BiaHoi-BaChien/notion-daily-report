<?php

declare(strict_types=1);

namespace App;

use App\Exception\ConfigException;
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
     * @param array<int, string> $argv
     */
    public function run(array $argv): int
    {
        $startedAt = new DateTimeImmutable('now', $this->timezone);
        $this->logger->info('daily_report_start', [
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

            foreach ($sources as $source) {
                try {
                    $sourceItems = $this->processSource($source, $today);
                    $items = array_merge($items, $sourceItems['items']);
                    $fetchedCount += $sourceItems['fetched_count'];
                    $successfulSources++;
                } catch (Throwable $exception) {
                    $failedSources++;
                    $this->logger->error('source_failed', [
                        'source' => $source['name'] ?? null,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            if ($successfulSources === 0) {
                throw new ConfigException('All enabled sources failed.');
            }

            $classified = $this->reportBuilder->classifyAndSort($items, $today);
            $fallbackReport = $this->reportBuilder->renderConsole($classified, $today);
            $apiSendCount = $this->shouldSummarizeWithOpenAI($classified) ? min(100, count($classified)) : 0;

            $this->logger->info('daily_report_filtered', [
                'source_count' => count($sources),
                'successful_source_count' => $successfulSources,
                'failed_source_count' => $failedSources,
                'fetched_count' => $fetchedCount,
                'filtered_count' => count($classified),
                'api_send_count' => $apiSendCount,
            ]);

            $report = $this->buildNotificationReport($classified, $fallbackReport, $today);
            echo $report;
            $this->sendSlackReport($report);
            $this->sendMailReport($report, $today);

            $this->logger->info('daily_report_end', [
                'ended_at' => (new DateTimeImmutable('now', $this->timezone))->format(DATE_ATOM),
                'exit_code' => 0,
            ]);

            return 0;
        } catch (Throwable $exception) {
            $this->logger->error('daily_report_failed', [
                'ended_at' => (new DateTimeImmutable('now', $this->timezone))->format(DATE_ATOM),
                'error' => $exception->getMessage(),
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
     * @return array{items: array<int, array<string, mixed>>, fetched_count: int}
     */
    private function processSource(array $source, DateTimeImmutable $today): array
    {
        $this->validateSource($source);

        $pages = $this->notionClient->queryDataSource(
            (string) $source['data_source_id'],
            $source['filter_property_ids'] ?? []
        );
        $this->logger->info('notion_fetch_complete', [
            'source' => $source['name'],
            'fetched_count' => count($pages),
        ]);

        $items = [];
        $extractionErrorCount = 0;
        foreach ($pages as $page) {
            try {
                $items[] = $this->propertyExtractor->extract($page, $source);
            } catch (PropertyExtractionException $exception) {
                $extractionErrorCount++;
                $this->logger->error('property_extraction_failed', [
                    'source' => $source['name'],
                    'page_id' => $page['id'] ?? null,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $filtered = $this->dateFilter->filter($items, $source, $today);
        $this->logger->info('source_filtered', [
            'source' => $source['name'],
            'extracted_count' => count($items),
            'extraction_error_count' => $extractionErrorCount,
            'filtered_count' => count($filtered),
        ]);

        return [
            'items' => $filtered,
            'fetched_count' => count($pages),
        ];
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

    private function sendSlackReport(string $report): void
    {
        if ($this->slackNotifier === null || !$this->slackNotifier->isConfigured()) {
            $this->logger->info('slack_notification_skipped', [
                'reason' => 'SLACK_WEBHOOK_URL is not configured.',
            ]);
            return;
        }

        $this->slackNotifier->send($report);
        $this->logger->info('slack_notification_sent');
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function buildNotificationReport(array $items, string $fallbackReport, DateTimeImmutable $today): string
    {
        if (!$this->shouldSummarizeWithOpenAI($items)) {
            $this->logger->info('openai_summary_skipped', [
                'reason' => $items === [] ? 'No filtered items.' : 'OPENAI_API_KEY is not configured.',
            ]);
            return $fallbackReport;
        }

        $payload = $this->openAIPayload($items);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->logger->info('openai_payload_prepared', [
            'api_send_count' => count($payload),
            'json_size_bytes' => $payloadJson === false ? 0 : strlen($payloadJson),
        ]);

        $summary = $this->openAIClient?->summarize($items) ?? '';
        $this->logger->info('openai_summary_complete', [
            'summary_length_bytes' => strlen($summary),
        ]);

        return $this->reportBuilder->renderSummary($summary, $today);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function shouldSummarizeWithOpenAI(array $items): bool
    {
        return $items !== []
            && $this->openAIClient !== null
            && $this->openAIClient->isConfigured();
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function openAIPayload(array $items): array
    {
        $payload = [];
        foreach (array_slice($items, 0, 100) as $item) {
            $payload[] = [
                'source_name' => $item['source_name'] ?? null,
                'source_role' => $item['source_role'] ?? null,
                'title' => $item['title'] ?? null,
                'date' => $item['date'] ?? null,
                'status' => $item['status'] ?? null,
                'classification' => $item['classification'] ?? null,
                'url' => $item['url'] ?? null,
            ];
        }

        return $payload;
    }

    private function sendMailReport(string $report, DateTimeImmutable $today): void
    {
        if ($this->mailNotifier === null || !$this->mailNotifier->isConfigured()) {
            $this->logger->info('mail_notification_skipped', [
                'reason' => 'SMTP_HOST, MAIL_FROM, or MAIL_TO is not configured.',
            ]);
            return;
        }

        $this->mailNotifier->send(
            sprintf('Notion Daily Report %s', $today->setTimezone($this->timezone)->format('Y-m-d')),
            $report
        );
        $this->logger->info('mail_notification_sent');
    }
}
