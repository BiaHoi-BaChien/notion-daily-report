<?php

declare(strict_types=1);

$env = static function (string $key, mixed $default = null): mixed {
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }

    $value = getenv($key);
    return $value === false ? $default : $value;
};

$envBool = static function (string $key, bool $default = true) use ($env): bool {
    $value = $env($key, $default ? 'true' : 'false');
    if (is_bool($value)) {
        return $value;
    }

    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed ?? $default;
};

$baseSources = [
    [
        'name' => 'ToDo',
        'data_source_id' => trim((string) $env('NOTION_TODO_DATA_SOURCE_ID', '')),
        'role' => '今日やるべき作業の確認',
        'date_property' => 'いつまでに',
        'status_property' => 'ステータス',
        'project_property' => '関連プロジェクト',
        'lookback_days' => 0,
        'lookahead_days' => 3,
        'exclude_statuses' => ['完了', 'いつかやる'],
    ],
    [
        'name' => '各案件のタスク',
        'data_source_id' => trim((string) $env('NOTION_PROJECT_TASK_DATA_SOURCE_ID', '')),
        'role' => '各案件ごとのタスクの確認',
        'date_property' => 'By when',
        'status_property' => 'Status',
        'project_property' => 'Projects',
        'lookback_days' => 0,
        'lookahead_days' => 5,
        'exclude_statuses' => ['Release', 'Archived'],
    ],
    [
        'name' => 'カレンダー',
        'data_source_id' => trim((string) $env('NOTION_CALENDAR_DATA_SOURCE_ID', '')),
        'role' => '今日以降1週間の予定の確認',
        'date_property' => 'Date',
        'status_property' => '',
        'project_property' => 'Projects',
        'genre_property' => 'ジャンル',
        'lookback_days' => 0,
        'lookahead_days' => 7,
        'include_active_ranges' => true,
        'exclude_statuses' => [],
    ],
    [
        'name' => '身分証明書',
        'data_source_id' => trim((string) $env('NOTION_ID_DOCUMENT_DATA_SOURCE_ID', '')),
        'role' => '期限切れが迫っている身分証明書の確認',
        'date_property' => '有効期限',
        'status_property' => '状態',
        'genre_property' => '',
        'lookback_days' => 0,
        'lookahead_days' => 60,
        'exclude_statuses' => ['無効'],
    ],
    [
        'name' => '子供のお弁当',
        'data_source_id' => trim((string) $env('NOTION_CHILD_LUNCH_DATA_SOURCE_ID', '')),
        'role' => '今日の子供のお弁当の献立確認',
        'date_property' => '日付',
        'status_property' => '',
        'title_property' => '品名',
        'lookback_days' => 0,
        'lookahead_days' => 0,
        'exclude_statuses' => [],
        'extra_properties' => [
            'weekday' => '曜日',
            'size' => 'サイズ',
            'note' => '備考',
        ],
    ],
];

$sources = array_values(array_filter(
    $baseSources,
    static fn (array $source): bool => $source['data_source_id'] !== ''
));
$birthdayDataSourceId = trim((string) $env(
    'BIRTHDAY_NOTION_DATA_SOURCE_ID',
    'b050caed-409d-4085-93fa-25d6f0e24728'
));
if ($birthdayDataSourceId !== '') {
    $sources[] = [
        'name' => '誕生日',
        'role' => '今日から明後日までに誕生日を迎える在職者の確認',
        'data_source_id' => $birthdayDataSourceId,
        'date_property' => 'Birthday',
        'status_property' => 'Status',
        'title_property' => 'Full Name',
        'lookback_days' => 0,
        'lookahead_days' => 2,
        'exclude_statuses' => [],
        'include_statuses' => ['在職中'],
        'annual' => true,
    ];
}

return [
    'timezone' => $env('APP_TIMEZONE', 'Asia/Ho_Chi_Minh'),
    'log_path' => $env('LOG_PATH', 'app/logs/daily_report.log'),
    'http' => [
        'ca_bundle' => $env('HTTP_CA_BUNDLE', $env('SSL_CERT_FILE', $env('CURL_CA_BUNDLE', ''))),
    ],
    'notion' => [
        'api_key' => $env('NOTION_API_KEY', ''),
        'version' => $env('NOTION_VERSION', '2026-03-11'),
        'timeout' => (int) $env('NOTION_TIMEOUT', 20),
    ],
    'notion_report' => [
        'enabled' => $envBool('REPORT_NOTION_ENABLED', false),
        'data_source_id' => $env('REPORT_NOTION_DATA_SOURCE_ID', ''),
        'title_property' => $env('REPORT_NOTION_TITLE_PROPERTY', 'Name'),
        'date_property' => $env('REPORT_NOTION_DATE_PROPERTY', 'Date'),
        'run_id_property' => $env('REPORT_NOTION_RUN_ID_PROPERTY', ''),
    ],
    'slack' => [
        'enabled' => $envBool('SLACK_ENABLED', true),
        'webhook_url' => $env('SLACK_WEBHOOK_URL', ''),
        'timeout' => (int) $env('SLACK_TIMEOUT', 10),
    ],
    'openai' => [
        'enabled' => $envBool('OPENAI_ENABLED', true),
        'api_key' => $env('OPENAI_API_KEY', ''),
        'model' => $env('OPENAI_MODEL', 'auto'),
        'model_candidates' => \App\SourceConfigBuilder::splitCsv((string) $env('OPENAI_MODEL_CANDIDATES', 'gpt-4o-mini,gpt-4.1-mini,gpt-4o')),
        'timeout' => (int) $env('OPENAI_TIMEOUT', 30),
    ],
    'mail' => [
        'enabled' => $envBool('MAIL_ENABLED', true),
        'host' => $env('SMTP_HOST', ''),
        'port' => (int) $env('SMTP_PORT', 587),
        'secure' => $env('SMTP_SECURE', 'tls'),
        'user' => $env('SMTP_USER', ''),
        'password' => $env('SMTP_PASSWORD', ''),
        'from' => $env('MAIL_FROM', ''),
        'to' => \App\MailNotifier::parseRecipients((string) $env('MAIL_TO', '')),
    ],
    'sources' => $sources,
];
