# notion-daily-report

PHP 8.1+ CLI batch that reads near-term Notion data-source items, filters them in PHP, summarizes them with OpenAI when configured, prints a Japanese daily report, and can post the same report to Slack and email.

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
NOTION_DATA_SOURCE_IDS=
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/...
OPENAI_API_KEY=sk-...
OPENAI_MODEL=auto
OPENAI_MODEL_CANDIDATES=gpt-4o-mini,gpt-4.1-mini,gpt-4o
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USER=user@example.com
SMTP_PASSWORD=password
MAIL_FROM=user@example.com
MAIL_TO=recipient1@example.com,recipient2@example.com
```

For API versions `2025-09-03` and newer, Notion distinguishes between database IDs and data-source IDs. Prefer the data-source ID from Notion's "Copy data source ID" action. If you provide a database ID, this script can resolve it automatically only when that database has exactly one data source.

Use `NOTION_DATA_SOURCE_IDS` when multiple data sources should be processed:

```dotenv
NOTION_DATA_SOURCE_IDS=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx,yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy
```

When `NOTION_DATA_SOURCE_IDS` is set, it takes precedence over `NOTION_DATA_SOURCE_ID`. Each ID is matched by index to one entry in `$baseSources` inside `app/config/app.php`. Leave `NOTION_DATA_SOURCE_IDS` empty when you only need the single-source setting.

Example mapping:

```php
$baseSources = [
    [
        'name' => 'ToDo',
        'date_property' => 'いつまでに',
        'status_property' => 'ステータス',
        'lookback_days' => 1,
        'lookahead_days' => 3,
        'exclude_statuses' => ['完了', 'いつかやる'],
    ],
    [
        'name' => '会議予定',
        'date_property' => '開始日',
        'status_property' => null,
        'lookback_days' => 0,
        'lookahead_days' => 7,
        'exclude_statuses' => [],
    ],
];
```

With two IDs in `NOTION_DATA_SOURCE_IDS`, the first ID uses `ToDo` settings and the second ID uses `会議予定` settings. If you add a third ID, add a third `$baseSources` entry.

Update `app/config/app.php` if your Notion property names differ from the defaults:

- `date_property`: date property used for filtering
- `status_property`: status or select property used for exclusion; set to `null` for sources without status
- `exclude_statuses`: statuses removed before reporting
- `lookback_days` / `lookahead_days`: date window around today

Add more entries to the `sources` array to process multiple Notion sources in one run. Each source is fetched, extracted, and filtered independently; if one source fails, the batch logs that failure and continues with the remaining enabled sources.

`OPENAI_API_KEY` is optional. When it is set, up to 100 filtered items are sent to OpenAI's Responses API as compact JSON and the generated Japanese summary becomes the notification body. When it is empty, the batch uses the local classified report.

The OpenAI API requires a model in each request; there is no server-side `AUTO` model. This app supports an app-level `OPENAI_MODEL=auto`, which tries `OPENAI_MODEL_CANDIDATES` from left to right and falls back to the local classified report if none are available. You can also set `OPENAI_MODEL` to one exact model available in your project.

`SLACK_WEBHOOK_URL` is optional. When it is empty, the Slack step is logged as skipped. When it is set, the final report text is posted to Slack using the incoming webhook.

SMTP settings are optional. Mail is sent only when `SMTP_HOST`, `MAIL_FROM`, and `MAIL_TO` are configured. `MAIL_TO` accepts comma-separated recipients.

## Usage

Run with today's date in `APP_TIMEZONE`:

```bash
php app/batch/daily_report.php
```

Run deterministically for a specific date:

```bash
php app/batch/daily_report.php --date=2026-04-16
```

The report is printed to stdout and, when configured, sent to Slack and email. Logs are written to `app/logs/daily_report.log` by default.

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
- Sends up to 100 compact items to OpenAI for a Japanese action summary when configured
- Prints the final report, optionally posts it to Slack, optionally sends it by email, and writes JSON-line logs

## Tests

```bash
composer test
```

The test suite covers config mapping, date filtering, Notion property extraction, Notion client request behavior, OpenAI summarization, Slack notification, email configuration, and CLI orchestration with stubbed clients.

## Phase 3+ Roadmap

- Harden source-level continuation and operational logging
