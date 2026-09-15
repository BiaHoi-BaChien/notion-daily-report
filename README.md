# notion-daily-report

PHP 8.1+ CLI batch that reads near-term Notion data-source items, filters and formats them in PHP, asks OpenAI for optional structured highlights and meeting follow-ups, and can send a Japanese daily report to Slack and email.

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
NOTION_WEIGHT_DATA_SOURCE_ID=
NOTION_WEIGHT_DATE_PROPERTY=日付
NOTION_WEIGHT_VALUE_PROPERTY=体重
NOTION_STEPS_DATA_SOURCE_ID=
NOTION_STEPS_DATE_PROPERTY=日付
NOTION_STEPS_VALUE_PROPERTY=歩数
NOTION_VITAL_DATA_SOURCE_ID=
NOTION_VITAL_DATE_PROPERTY=日付
NOTION_VITAL_SYSTOLIC_PROPERTY=収縮期
NOTION_VITAL_DIASTOLIC_PROPERTY=拡張期
NOTION_VITAL_PULSE_PROPERTY=脈拍
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
NOTION_WEIGHT_DATA_SOURCE_ID=
NOTION_STEPS_DATA_SOURCE_ID=
NOTION_VITAL_DATA_SOURCE_ID=
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
| `NOTION_WEIGHT_DATA_SOURCE_ID` | Show recent weight measurements | Latest 1 on or before the report date | Requires a weight value |
| `NOTION_STEPS_DATA_SOURCE_ID` | Show recent step counts | Latest 1 on or before the report date | Requires a step value |
| `NOTION_VITAL_DATA_SOURCE_ID` | Show recent blood pressure and pulse measurements | Latest 1 on or before the report date | Requires all three vital values |
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
| Child lunch | `状況` | Status or Select | Yes | Reports `お弁当は利用しません` when set to `利用しない` |
| Child lunch | `曜日` | Rich text, Select, Formula, or Rollup | No | Displayed as supplementary information |
| Child lunch | `サイズ` | Rich text, Select, Formula, or Rollup | No | Displayed as supplementary information |
| Child lunch | `備考` | Rich text, Select, Formula, or Rollup | No | Displayed as supplementary information |
| Weight | `日付` | Date | Yes | Measurement date and optional time |
| Weight | `体重` | Number | Yes | Displayed in kg |
| Steps | `日付` | Date | Yes | Measurement date and optional time |
| Steps | `歩数` | Number | Yes | Displayed with digit grouping |
| Vitals | `日付` | Date | Yes | Measurement date and optional time |
| Vitals | `収縮期` | Number | Yes | Systolic blood pressure in mmHg |
| Vitals | `拡張期` | Number | Yes | Diastolic blood pressure in mmHg |
| Vitals | `脈拍` | Number | Yes | Pulse in beats per minute |
| Birthdays | `Full Name` | Title recommended; Rich text also supported | Yes | Employee name |
| Birthdays | `Birthday` | Date | Yes | Annual birthday comparison |
| Birthdays | `Status` | Status or Select | Yes | Includes active employees only |

`BIRTHDAY_NOTION_DATA_SOURCE_ID` can also be left empty to disable birthday checks. The birthday date must include the birth year when the report should display the employee's age.

Health data-source IDs and property names are configured entirely through environment variables. Leave an ID empty to omit that metric. The report adds a `🏥 健康` section immediately before `💡 その他トピックス` and displays the latest valid measurement per metric. The report date, including `--date`, is the upper bound. Steps display only the date; weight and vitals retain the measurement time. Set `NOTION_WEIGHT_DETAIL_URL`, `NOTION_STEPS_DETAIL_URL`, and `NOTION_VITAL_DETAIL_URL` to the respective database overview URLs to enable detail links. A metric with no usable records or a failed query is omitted; the whole section is omitted when all three metrics are unavailable.

```text
🏥 健康
・体重：8月11日 07:16｜73.25kg｜詳細
・歩数：8月11日｜4,275歩｜詳細
・バイタル：8月11日 21:11｜120/80mmHg｜脈拍70回/分｜詳細
```

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

`OPENAI_API_KEY` is optional. When it is set and `OPENAI_ENABLED=true`, the schedule (excluding health measurements) and related meeting notes are sent to the existing Responses API call. The response is structured JSON with at most two schedule highlights and two meeting points. Source IDs and text are validated locally; source dates and links are attached from Notion data. With AI disabled, unavailable, or returning invalid output, the overview uses the first two timed-order items plus the locally determined deadline status. Meeting summaries are marked unavailable, with source links when available.

The OpenAI API requires a model in each request; there is no server-side `AUTO` model. This app supports an app-level `OPENAI_MODEL=auto`, which tries `OPENAI_MODEL_CANDIDATES` from left to right and falls back to the local classified report if none are available. You can also set `OPENAI_MODEL` to one exact model available in your project.

`SLACK_WEBHOOK_URL` is optional. When it is empty, the Slack step is logged as skipped. When it is set and `SLACK_ENABLED=true`, the final report text is posted to Slack using the incoming webhook. Set `SLACK_ENABLED=false` to skip Slack even when the webhook URL is configured.

SMTP settings are optional. Mail is sent only when `MAIL_ENABLED=true` and `SMTP_HOST`, `MAIL_FROM`, and `MAIL_TO` are configured. `MAIL_TO` accepts comma-separated recipients. Set `MAIL_ENABLED=false` to skip email even when SMTP settings are configured.

Notion report creation is optional. Set `REPORT_NOTION_ENABLED=true` and `REPORT_NOTION_DATA_SOURCE_ID` to save each daily report as a page in a Notion data source. The page body starts with the up-to-three-item daily overview and is generated with Notion blocks: `heading_2`, `heading_3`, icon callouts, linked bullet items, and two-column schedule/task tables (time and linked title, with the group on the next line in gray without a link). Hidden or absent groups add no extra line. This layout is shared by mobile and desktop and applies to newly generated reports; existing pages are not rewritten. It intentionally does not use duplicate title headings or `divider` blocks. Configure `REPORT_NOTION_TITLE_PROPERTY`, `REPORT_NOTION_DATE_PROPERTY`, and optional `REPORT_NOTION_RUN_ID_PROPERTY` when your report data source uses different property names.

## モーニングブリーフ

- 「今日の要点」は予定の要点最大2件＋「本日期限」の1行。期限の対象は従来どおり「各案件のタスク」です。0件なら期限詳細欄を省略します。取得・解析失敗、または期限ソース未設定の場合は「取得不可」と表示し、取得済みのタスクは残します。
- 議事録は当日を含む14日間（当日−13日〜当日）、今日のToDo・カレンダー・案件タスクと同じProjectのものだけを対象とします。タイトル内の会議日（YYYY年M月D日、YYYY/M/D、YYYY-M-D）を優先し、なければ登録日を使います。Project未設定は対象外です。
- 作成・更新日時と会議日が異なるページを取りこぼさないよう、Projectで候補を取得して会議日／登録日を検査し、対象の本文だけを読みます。ページ内の入れ子・ページ分割には対応しますが、リンク先の別ページや子ページ・DBは読みません。本文取得は1議事録あたり最大100リクエストで中断し、取得不可と表示します。
- AIへの議事録送信には本文とチェック状態を含みます。新しい完了・変更情報を優先するよう指示し、古いフォロー項目は「完了確認が必要」と表示します。要約は自動生成なので原文リンクで確認できます。
- 読書中の本は購入日降順、同日の場合はページID昇順で1冊を表示します。購入日なしは日付ありの後、読了・未読は対象外です。候補なしは省略、取得失敗は取得不可と表示します。

追加設定（実値はローカル／本番の環境設定にのみ保存）：

| 環境変数 | 内容・既定値 |
|---|---|
| `NOTION_MEETING_DATA_SOURCE_ID` | 議事録。空なら無効 |
| `NOTION_MEETING_TITLE_PROPERTY` | `Name` |
| `NOTION_MEETING_CREATED_PROPERTY` | `Created`（created_time型） |
| `NOTION_MEETING_PROJECT_PROPERTY` | `関連Project`（Relation型） |
| `NOTION_READING_DATA_SOURCE_ID` | 読書リスト。空なら無効 |
| `NOTION_READING_TITLE_PROPERTY` | `タイトル` |
| `NOTION_READING_DATE_PROPERTY` | `購入日` |
| `NOTION_READING_STATUS_PROPERTY` | `ステータス`（Status型、対象値は「読書中」） |
| `NOTION_WEIGHT_DETAIL_URL` / `NOTION_STEPS_DETAIL_URL` / `NOTION_VITAL_DETAIL_URL` | 各健康DBの一覧URL。空ならリンク省略 |

既存のNotion integrationから追加DBを読み取れることを確認してください。モデル、通知先、スケジュールの設定は変更不要です。本文の意味に関するAIの判断は完全には機械検証できないため、出典のない要約は採用せず、件数・形式・相対日付・出典IDをPHPで検査します。

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
- Sends a health-free schedule and related meeting notes to OpenAI for optional structured highlights when configured
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
