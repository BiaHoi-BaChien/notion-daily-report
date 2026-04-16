# notion-daily-report

PHP 8.1+ CLI batch that reads near-term Notion data-source items, filters them in PHP, classifies them, prints a Japanese daily report, and can post the same report to Slack. OpenAI summarization and email are intentionally left for later phases.

## Requirements

- PHP 8.1 or newer
- Composer
- A Notion integration token
- A Notion data source, or a single-data-source database, shared with that integration

## Setup

```bash
composer install
cp .env.example .env
```

Edit `.env`:

```dotenv
APP_TIMEZONE=Asia/Saigon
NOTION_API_KEY=secret_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
NOTION_VERSION=2026-03-11
NOTION_DATA_SOURCE_ID=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/...
```

For API versions `2025-09-03` and newer, Notion distinguishes between database IDs and data-source IDs. Prefer the data-source ID from Notion's "Copy data source ID" action. If you provide a database ID, this script can resolve it automatically only when that database has exactly one data source.

Update `app/config/app.php` if your Notion property names differ from the defaults:

- `date_property`: date property used for filtering
- `status_property`: status or select property used for exclusion; set to `null` for sources without status
- `exclude_statuses`: statuses removed before reporting
- `lookback_days` / `lookahead_days`: date window around today

Add more entries to the `sources` array to process multiple Notion sources in one run. Each source is fetched, extracted, and filtered independently; if one source fails, the batch logs that failure and continues with the remaining enabled sources.

`SLACK_WEBHOOK_URL` is optional. When it is empty, the report is printed only to stdout and the Slack step is logged as skipped. When it is set, the exact report text is posted to Slack using the incoming webhook.

## Usage

Run with today's date in `APP_TIMEZONE`:

```bash
php app/batch/daily_report.php
```

Run deterministically for a specific date:

```bash
php app/batch/daily_report.php --date=2026-04-16
```

The report is printed to stdout and, when configured, sent to Slack. Logs are written to `app/logs/daily_report.log` by default.

## Hostinger Cron Example

Hostinger cron is UTC-based, so choose the UTC trigger time that corresponds to your intended local report time. Use absolute paths:

```bash
/usr/bin/php /home/USER/domains/DOMAIN/private/notion-daily-report/app/batch/daily_report.php >> /home/USER/domains/DOMAIN/private/notion-daily-report/app/logs/cron.log 2>&1
```

Keep `.env` outside any public web root whenever possible.

## What This Batch Does

- Queries `POST /v1/data_sources/{data_source_id}/query` with `Notion-Version: 2026-03-11`
- Resolves a configured single-source database ID to its child data-source ID when needed
- Processes all enabled configured sources
- Paginates through all results per source
- Extracts title, date, status/select, URL, and last edited time
- Filters in PHP by date window and excluded statuses
- Classifies items as `overdue`, `today`, `upcoming`, or `recent_past`
- Prints a Japanese CLI report, optionally posts it to Slack, and writes JSON-line logs

## Tests

```bash
composer test
```

The test suite covers date filtering, Notion property extraction, Notion client request behavior, Slack notification, and CLI orchestration with stubbed clients.

## Phase 3+ Roadmap

- Send the same report by SMTP email
- Add OpenAI summarization with a max 100-item payload
- Harden source-level continuation and operational logging
