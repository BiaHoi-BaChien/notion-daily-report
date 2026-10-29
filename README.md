# notion-daily-report

PHP 8.5.12+ CLI batch that reads near-term Notion data-source items, filters and formats them in PHP, asks OpenAI for optional source-backed project trends, and can send a Japanese daily report to Slack and email.

## Requirements

- PHP 8.5.12 or newer
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
| Child lunch | `お店` | Select recommended; Rich text, Formula, or Rollup also supported | No | Identifies RAMEN KIMURA to hide its total amount in reports |
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

Health data-source IDs and property names are configured entirely through environment variables. Leave an ID empty to omit that metric. The report adds a `健康` section immediately before `その他` and displays the latest valid measurement per metric. The report date, including `--date`, is the upper bound. Steps display only the date; weight and vitals retain the measurement time. Set `NOTION_WEIGHT_DETAIL_URL`, `NOTION_STEPS_DETAIL_URL`, and `NOTION_VITAL_DETAIL_URL` to the respective database overview URLs to enable detail links. A metric with no usable records or a failed query is omitted; the whole section is omitted when all three metrics are unavailable.

```text
健康
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

`OPENAI_API_KEY` is optional. With `OPENAI_ENABLED=true`, recent meeting notes are sent to the Responses API for up to five source-backed project trends. The result is reused by every delivery format; schedules and health measurements are not sent. If AI is disabled, unavailable, or returns invalid results, the local schedule and health sections remain and project trends show their unavailable status.

The OpenAI API requires a model in each request; there is no server-side `AUTO` model. This app supports an app-level `OPENAI_MODEL=auto`, which tries `OPENAI_MODEL_CANDIDATES` from left to right and falls back to the local classified report if none are available. You can also set `OPENAI_MODEL` to one exact model available in your project.

`SLACK_WEBHOOK_URL` is optional. When it is empty, the Slack step is logged as skipped. When it is set and `SLACK_ENABLED=true`, the final report text is posted to Slack using the incoming webhook. Set `SLACK_ENABLED=false` to skip Slack even when the webhook URL is configured.

SMTP settings are optional. Mail is sent only when `MAIL_ENABLED=true` and `SMTP_HOST`, `MAIL_FROM`, and `MAIL_TO` are configured. `MAIL_TO` accepts comma-separated recipients. Set `MAIL_ENABLED=false` to skip email even when SMTP settings are configured.

Notion report creation is optional. Set `REPORT_NOTION_ENABLED=true` and `REPORT_NOTION_DATA_SOURCE_ID` to save each daily report as a page in a Notion data source. Configure `REPORT_NOTION_TITLE_PROPERTY`, `REPORT_NOTION_DATE_PROPERTY`, and optional `REPORT_NOTION_RUN_ID_PROPERTY` when your report data source uses different property names. The layout below is shared by Notion, Slack, HTML email, and plain-text email. Existing Notion pages are not rewritten.

### レポートの共通構成

- 「今日の予定」「案件動向」「以降の予定」「健康」「その他」の順に表示します。健康・その他は対象データがある場合に表示します。Notionはアイコンなしcallout、HTMLメールは枠付きセクション、Slack・テキストメールは見出しと箇条書きです。見出し・案件名・日付ラベルの文字サイズをそろえ、太字で区別します（テキストメールは装飾なし）。
- 「今日の要点」「明日の時間付き予定」は表示しません。本日期限の案件タスクは「今日の予定」内、明日の予定は「以降の予定」の日付別一覧にまとめます。期限ソースの取得失敗は「本日期限：取得不可」と表示します。
- 予定は表を使わず、太字の時刻とリンク付き予定名を同じ箇条書きに表示します。案件名は次の行にグレーで添えます。長いタイトルは省略せず折り返し、Notionの配列上限を超える場合は続きの段落・calloutに分割します。
- 「案件動向」は直近14日間に登録された議事録を会議日でも確認し、当日以降の具体的な予定を3〜5件を目安に最大5件紹介します。当日の予定にある案件には限定しません。案件名・予定時期・内容・出典リンクを表示し、完了・中止・変更は新しい情報を優先するようAIに指示します。相対日付は議事録の会議日基準で変換し、出典ID・原文引用・案件名・日付範囲をPHPで検査します。該当情報なしと取得・要約失敗は区別して表示します。健康データはAIへ送信しません。
- 体重は前回測定、歩数は前日（欠測時は前回記録）と比較し、値の後ろに `↗（前日比 ＋824歩）`、`↘（前回比 −0.2kg）`、同値は `→（前回比 0kg）` の形式で表示します。比較対象がない・取得できない場合は追記しません。バイタルには増減を付けません。
- その他の献立ラベルは「子どものお弁当」とします。RAMEN KIMURAの合計金額を隠す既存ルールは維持します。

案件動向のAI生成は1回だけ行い、その結果と健康の比較データを全配信先で共有します。Notion出力が無効でもメール・Slackに反映します。指定モデルが利用できない場合には `OPENAI_MODEL_CANDIDATES`、最後に `gpt-6-sol` を順に試します。AIの意味解釈や予定変更の判断は完全には機械検証できないため、出典から確認できます。

保存にはNotion公式APIのMarkdown v2を使用します。ブロックAPIではcalloutのアイコンが自動補完されるため、同じ生成ブロックから `<callout icon="">` を出力してアイコンなしを維持します。Notion API `2026-03-11` 以降が必要です。

## モーニングブリーフ

子供のお弁当の「お店」が `RAMEN KIMURA` の場合、備考先頭の `合計金額: ...` はSlack・メール・NotionレポートとAI要約への入力に表示しません。数量など後続の備考、他店の表示、元のNotionデータは維持します。

- 議事録は全案件を対象に、当日を含む直近14日間の登録候補を取得し、タイトル内の会議日（YYYY年M月D日、YYYY/M/D、YYYY-M-D）を優先して期間を確認します。会議日がなければ登録日を使います。
- AIへの入力には議事録の本文・会議日・案件名・チェック状態を含めます。今日以降の具体的な予定を最大5件抽出し、日付・案件名・出典・原文根拠を検証します。無効な項目だけを除外し、有効な項目を残します。
- ページ内の入れ子・ページ分割に対応しますが、リンク先の別ページ・子ページ・DBは読みません。本文取得は1議事録あたり最大100リクエストで中断し、取得不可と表示します。

追加設定（実値はローカル／本番の環境設定にのみ保存）：

| 環境変数 | 内容・既定値 |
|---|---|
| `NOTION_MEETING_DATA_SOURCE_ID` | 議事録。空なら無効 |
| `NOTION_MEETING_TITLE_PROPERTY` | `Name` |
| `NOTION_MEETING_CREATED_PROPERTY` | `Created`（created_time型） |
| `NOTION_MEETING_PROJECT_PROPERTY` | `関連Project`（Relation型） |
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

Notion版だけを実データで確認する場合（メール・Slack・失敗通知メールは送信しません）：

```powershell
php app/batch/daily_report.php --date=2026-10-29 --preview-notion
```

`--preview-notion` は設定済みのNotion出力先へタイトル末尾が「（確認用）」の新規ページを作成し、URLを標準出力へ表示します。通常配信用の `REPORT_NOTION_ENABLED=false` はこの実行時だけ無視し、設定ファイルは変更しません。案件動向の生成は通常実行と共通です。ページ作成失敗は終了コード1になります。

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
- Sends recent meeting notes to OpenAI for optional source-backed project trends when configured
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
