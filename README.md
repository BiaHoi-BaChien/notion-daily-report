# notion-daily-report

PHP 8.1+ CLI batch that reads near-term Notion data-source items, filters them in PHP, classifies them, and prints a Japanese daily report. Phase 1 intentionally stops at CLI output so the Notion extraction and date rules can be validated before adding Slack, OpenAI, and email.

## Requirements

- PHP 8.1 or newer
- Composer
- A Notion integration token
- A Notion data source shared with that integration

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
```

Update `app/config/app.php` if your Notion property names differ from the defaults:

- `date_property`: date property used for filtering
- `status_property`: status or select property used for exclusion; set to `null` for sources without status
- `exclude_statuses`: statuses removed before reporting
- `lookback_days` / `lookahead_days`: date window around today

## Usage

Run with today's date in `APP_TIMEZONE`:

```bash
php app/batch/daily_report.php
```

Run deterministically for a specific date:

```bash
php app/batch/daily_report.php --date=2026-04-16
```

The report is printed to stdout. Logs are written to `app/logs/daily_report.log` by default.

## Hostinger Cron Example

Hostinger cron is UTC-based, so choose the UTC trigger time that corresponds to your intended local report time. Use absolute paths:

```bash
/usr/bin/php /home/USER/domains/DOMAIN/private/notion-daily-report/app/batch/daily_report.php >> /home/USER/domains/DOMAIN/private/notion-daily-report/app/logs/cron.log 2>&1
```

Keep `.env` outside any public web root whenever possible.

## What Phase 1 Does

- Queries `POST /v1/data_sources/{data_source_id}/query` with `Notion-Version: 2026-03-11`
- Paginates through all results
- Extracts title, date, status/select, URL, and last edited time
- Filters in PHP by date window and excluded statuses
- Classifies items as `overdue`, `today`, `upcoming`, or `recent_past`
- Prints a Japanese CLI report and writes JSON-line logs

## Tests

```bash
composer test
```

The test suite covers date filtering, Notion property extraction, and the CLI orchestration with a stubbed Notion client.

## Phase 2+ Roadmap

- Process multiple sources in one run
- Post the report to Slack via webhook
- Send the same report by SMTP email
- Add OpenAI summarization with a max 100-item payload
- Harden source-level continuation and operational logging
