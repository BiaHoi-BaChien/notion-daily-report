# notion-daily-report

PHP 8.1+ CLI batch that reads near-term Notion data-source items, filters and formats them in PHP, asks OpenAI for an optional opening comment, prints a Japanese daily report, and can post the same report to Slack and email.

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
SLACK_ENABLED=true
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/...
OPENAI_ENABLED=true
OPENAI_API_KEY=sk-...
OPENAI_MODEL=auto
OPENAI_MODEL_CANDIDATES=gpt-4o-mini,gpt-4.1-mini,gpt-4o
MAIL_ENABLED=true
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
- `project_property`: optional project name property used for grouping ToDo and project tasks
- `genre_property`: optional calendar genre property used for grouping school/life plans and holiday topics
- `exclude_statuses`: statuses removed before reporting
- `lookback_days` / `lookahead_days`: date window around today

Add more entries to the `sources` array to process multiple Notion sources in one run. Each source is fetched, extracted, and filtered independently; if one source fails, the batch logs that failure and continues with the remaining enabled sources.

`OPENAI_API_KEY` is optional. When it is set and `OPENAI_ENABLED=true`, the PHP-formatted schedule is sent to OpenAI's Responses API and the generated Japanese opening comment is prepended to the report. The schedule formatting itself is handled locally in PHP. When the key is empty or `OPENAI_ENABLED=false`, the batch sends the same PHP-formatted schedule without an opening AI comment.

The OpenAI API requires a model in each request; there is no server-side `AUTO` model. This app supports an app-level `OPENAI_MODEL=auto`, which tries `OPENAI_MODEL_CANDIDATES` from left to right and falls back to the local classified report if none are available. You can also set `OPENAI_MODEL` to one exact model available in your project.

`SLACK_WEBHOOK_URL` is optional. When it is empty, the Slack step is logged as skipped. When it is set and `SLACK_ENABLED=true`, the final report text is posted to Slack using the incoming webhook. Set `SLACK_ENABLED=false` to skip Slack even when the webhook URL is configured.

SMTP settings are optional. Mail is sent only when `MAIL_ENABLED=true` and `SMTP_HOST`, `MAIL_FROM`, and `MAIL_TO` are configured. `MAIL_TO` accepts comma-separated recipients. Set `MAIL_ENABLED=false` to skip email even when SMTP settings are configured.

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
- Extracts title, date/time, status/select, URL, last edited time, optional genre, and optional project
- Filters in PHP by date window and excluded statuses
- Continues processing other sources when one source fails, and logs the failed source
- Classifies items as `overdue`, `today`, `upcoming`, or `recent_past`
- Formats the report locally in PHP by section, project, genre, and date/time
- Sends the formatted schedule to OpenAI for an optional positive opening comment when configured
- Prints the final report, optionally posts it to Slack, optionally sends it by email, and writes JSON-line logs
- Logs source-level start/completion/failure, fetch/extraction/filter counts, classification counts, notification status, report size, and run duration for operation checks
- Logs Slack or email delivery failures without blocking the remaining delivery steps

## Tests

```bash
composer test
```

The test suite covers config mapping, date filtering, Notion property extraction, Notion client request behavior, OpenAI summarization, Slack notification, email configuration, and CLI orchestration with stubbed clients.

## Phase 3+ Roadmap

- Completed Phase 4: hardened source-level continuation and operational logging
