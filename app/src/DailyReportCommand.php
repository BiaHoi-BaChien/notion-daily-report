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
        private readonly bool $writeErrors = true
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
            $source = $this->firstEnabledSource();
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
            foreach ($pages as $page) {
                try {
                    $items[] = $this->propertyExtractor->extract($page, $source);
                } catch (PropertyExtractionException $exception) {
                    $this->logger->error('property_extraction_failed', [
                        'source' => $source['name'],
                        'page_id' => $page['id'] ?? null,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $filtered = $this->dateFilter->filter($items, $source, $today);
            $classified = $this->reportBuilder->classifyAndSort($filtered, $today);

            $this->logger->info('daily_report_filtered', [
                'source' => $source['name'],
                'filtered_count' => count($classified),
                'api_send_count' => 0,
            ]);

            echo $this->reportBuilder->renderConsole($classified, $today);

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
     * @return array<string, mixed>
     */
    private function firstEnabledSource(): array
    {
        foreach ($this->config['sources'] as $source) {
            if (($source['enabled'] ?? true) !== false) {
                return $source;
            }
        }

        throw new ConfigException('No enabled source found.');
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
}
