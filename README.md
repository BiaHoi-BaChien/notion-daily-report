# notion-daily-report

PHP 8.1+ CLI batch that reads near-term Notion data-source items, filters and formats them in PHP, asks OpenAI for an optional opening comment, and can send a Japanese daily report to Slack and email.

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
APP_TIMEZONE=Asia/Ho_Chi_Minh
HTTP_CA_BUNDLE=
NOTION_API_KEY=secret_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
NOTION_VERSION=2026-03-11
NOTION_TODO_DATA_SOURCE_ID=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
NOTION_PROJECT_TASK_DATA_SOURCE_ID=
NOTION_CALENDAR_DATA_SOURCE_ID=
NOTION_ID_DOCUMENT_DATA_SOURCE_ID=
NOTION_CHILD_LUNCH_DATA_SOURCE_ID=
BIRTHDAY_NOTION_DATA_SOURCE_ID=
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

On Windows PHP installations where HTTPS requests fail with `cURL error 60`, set `HTTP_CA_BUNDLE` to a CA bundle file such as a downloaded `cacert.pem`. Relative paths are resolved from the project root.

For API versions `2025-09-03` and newer, Notion distinguishes between database IDs and data-source IDs. Prefer the data-source ID from Notion's "Copy data source ID" action. If you provide a database ID, this script can resolve it automatically only when that database has exactly one data source.

Configure each data source independently. Leave an ID empty to skip that source:

```dotenv
NOTION_TODO_DATA_SOURCE_ID=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
NOTION_PROJECT_TASK_DATA_SOURCE_ID=yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy
NOTION_CALENDAR_DATA_SOURCE_ID=
NOTION_ID_DOCUMENT_DATA_SOURCE_ID=
NOTION_CHILD_LUNCH_DATA_SOURCE_ID=
BIRTHDAY_NOTION_DATA_SOURCE_ID=
```

### Data source requirements

Property names are case-sensitive. The names below are the defaults in `app/config/app.php`; change that file if your Notion data source uses different names. Every data source also needs a title property so report items have a name.

| Environment variable | Purpose | Report window | Status filtering |
|---|---|---|---|
| `NOTION_TODO_DATA_SOURCE_ID` | Check work that should be done today | Today through 3 days ahead | Excludes `完了` and `いつかやる` |
| `NOTION_PROJECT_TASK_DATA_SOURCE_ID` | Check tasks grouped by project | Today through 5 days ahead | Excludes `Release` and `Archived` |
| `NOTION_CALENDAR_DATA_SOURCE_ID` | Check the upcoming schedule | Today through 7 days ahead | None |
| `NOTION_ID_DOCUMENT_DATA_SOURCE_ID` | Check identification documents nearing expiry | Today through 60 days ahead | Excludes `無効` |
| `NOTION_CHILD_LUNCH_DATA_SOURCE_ID` | Check today's child lunch menu | Today only | None |
| `BIRTHDAY_NOTION_DATA_SOURCE_ID` | Check active employees with birthdays today through 2 days ahead | Annual comparison of month and day | Includes only `在職中` |

Required and optional properties for each source:

| Data source | Property | Notion type | Required | Usage |
|---|---|---|---|---|
| ToDo | `いつまでに` | Date | Yes | Due-date filtering |
| ToDo | `ステータス` | Status or Select | Yes | Exclusion filtering |
| ToDo | `関連プロジェクト` | Relation, Rich text, Select, Multi-select, Formula, or Rollup | No | Project grouping |
| Project tasks | `By when` | Date | Yes | Due-date filtering |
| Project tasks | `Status` | Status or Select | Yes | Exclusion filtering |
| Project tasks | `Projects` | Relation, Rich text, Select, Multi-select, Formula, or Rollup | No | Project grouping |
| Calendar | `Date` | Date | Yes | Schedule filtering; date ranges and times are supported |
| Calendar | `Projects` | Relation, Rich text, Select, Multi-select, Formula, or Rollup | No | Project grouping |
| Calendar | `ジャンル` | Select, Multi-select, Rich text, Formula, or Rollup | No | Schedule classification |
| Identification documents | `有効期限` | Date | Yes | Expiry-date filtering |
| Identification documents | `状態` | Status or Select | Yes | Excludes invalid documents |
| Child lunch | `品名` | Title recommended; Rich text also supported | Yes | Menu item name |
| Child lunch | `日付` | Date | Yes | Selects today's menu |
| Child lunch | `曜日` | Rich text, Select, Formula, or Rollup | No | Displayed as supplementary information |
| Child lunch | `サイズ` | Rich text, Select, Formula, or Rollup | No | Displayed as supplementary information |
| Child lunch | `備考` | Rich text, Select, Formula, or Rollup | No | Displayed as supplementary information |
| Birthdays | `Full Name` | Title recommended; Rich text also supported | Yes | Employee name |
| Birthdays | `Birthday` | Date | Yes | Annual birthday comparison |
| Birthdays | `Status` | Status or Select | Yes | Includes active employees only |

`BIRTHDAY_NOTION_DATA_SOURCE_ID` can also be left empty to disable birthday checks. The birthday date must include the birth year when the report should display the employee's age.

Update `app/config/app.php` if your Notion property names differ from the defaults:

- `date_property`: date property used for filtering
- `status_property`: status or select property used for exclusion; set to `null` for sources without status
- `project_property`: optional project name property used for grouping ToDo and project tasks
- `genre_property`: optional calendar genre property used for grouping school/life plans and holiday topics
- `title_property`: optional title/name property used instead of the first Notion title property
- `extra_properties`: optional text properties used by source-specific renderers, such as the child lunch weekday, size, and note
- `exclude_statuses`: statuses removed before reporting
- `lookback_days` / `lookahead_days`: date window around today

Add more entries to the `sources` array to process multiple Notion sources in one run. Each source is fetched, extracted, and filtered independently; if one source fails, the batch logs that failure and continues with the remaining enabled sources.

`OPENAI_API_KEY` is optional. When it is set and `OPENAI_ENABLED=true`, the PHP-formatted schedule is sent to OpenAI's Responses API and the generated Japanese opening comment is prepended to the report. The schedule formatting itself is handled locally in PHP. When the key is empty or `OPENAI_ENABLED=false`, the batch sends the same PHP-formatted schedule without an opening AI comment.

The OpenAI API requires a model in each request; there is no server-side `AUTO` model. This app supports an app-level `OPENAI_MODEL=auto`, which tries `OPENAI_MODEL_CANDIDATES` from left to right and falls back to the local classified report if none are available. You can also set `OPENAI_MODEL` to one exact model available in your project.

`SLACK_WEBHOOK_URL` is optional. When it is empty, the Slack step is logged as skipped. When it is set and `SLACK_ENABLED=true`, the final report text is posted to Slack using the incoming webhook. Set `SLACK_ENABLED=false` to skip Slack even when the webhook URL is configured.

SMTP settings are optional. Mail is sent only when `MAIL_ENABLED=true` and `SMTP_HOST`, `MAIL_FROM`, and `MAIL_TO` are configured. `MAIL_TO` accepts comma-separated recipients. Set `MAIL_ENABLED=false` to skip email even when SMTP settings are configured.

Notion report creation is optional. Set `REPORT_NOTION_ENABLED=true` and `REPORT_NOTION_DATA_SOURCE_ID` to save each daily report as a page in a Notion data source. The page body starts with the OpenAI comment callout and is generated with Notion blocks: `heading_2`, `heading_3`, icon callouts, linked bullet items, and three-column upcoming-item tables. It intentionally does not use duplicate title headings or `divider` blocks. Configure `REPORT_NOTION_TITLE_PROPERTY`, `REPORT_NOTION_DATE_PROPERTY`, and optional `REPORT_NOTION_RUN_ID_PROPERTY` when your report data source uses different property names.

## Usage

Run with today's date in `APP_TIMEZONE`:

```bash
php app/batch/daily_report.php
```

Run deterministically for a specific date:

```bash
php app/batch/daily_report.php --date=2026-04-16
```

The report is sent to Slack and email when configured. The batch does not print the report body to stdout; operational logs are written to `app/logs/daily_report.log` by default.

## Deployment

Deployments are handled by `.github/workflows/deploy.yml`. On every push to `main`, or when the workflow is run manually, GitHub Actions uploads the application files to:

```text
/home/u685478147/public_html/public_html/notion_daily_report
```

Configure these GitHub Actions secrets before running the workflow:

- `SCP_HOST`
- `SCP_USER`
- `SCP_PRIVATE_KEY`
- `SCP_PORT` (optional; defaults to `22`)

Keep the production `.env` on the Hostinger server. The deployment does not upload `.env`, and `app/logs` is created on the server for runtime logs. The deployed `.htaccess` denies web access to the deployment directory because this project is a CLI batch.

## Hostinger Cron Example

Hostinger cron is UTC-based, so choose the UTC trigger time that corresponds to your intended local report time. Use absolute paths:

```bash
/usr/bin/php /home/u685478147/public_html/public_html/notion_daily_report/app/batch/daily_report.php >> /home/u685478147/public_html/public_html/notion_daily_report/app/logs/cron.log 2>&1
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
- Saves the final report to Notion, Slack, and email when configured, and writes JSON-line logs
- Logs source-level start/completion/failure, fetch/extraction/filter counts, classification counts, notification status, report size, and run duration for operation checks
- Logs Notion, Slack, or email delivery failures without blocking the remaining delivery steps

## Tests

```bash
composer test
```

The test suite covers config mapping, date filtering, Notion property extraction, Notion client request behavior, OpenAI summarization, Slack notification, email configuration, and CLI orchestration with stubbed clients.

## Phase 3+ Roadmap

- Completed Phase 4: hardened source-level continuation and operational logging
