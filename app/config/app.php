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

$dataSourceIds = \App\SourceConfigBuilder::dataSourceIds(
    $env('NOTION_DATA_SOURCE_IDS', ''),
    $env('NOTION_DATA_SOURCE_ID', '')
);

$baseSources = [
    [
        'enabled' => true,
        'name' => 'ToDo',
        'role' => '今日やるべき作業の確認',
        'date_property' => 'いつまでに',
        'status_property' => 'ステータス',
        'project_property' => '関連プロジェクト',
        'lookback_days' => 0,
        'lookahead_days' => 3,
        'exclude_statuses' => ['完了', 'いつかやる'],
        'filter_property_ids' => [],
    ],
    [
        'enabled' => true,
        'name' => '各案件のタスク',
        'role' => '各案件ごとのタスクの確認',
        'date_property' => 'By when',
        'status_property' => 'Status',
        'project_property' => 'Projects',
        'lookback_days' => 0,
        'lookahead_days' => 5,
        'exclude_statuses' => ['Release', 'Archived'],
        'filter_property_ids' => [],
    ],
    [
        'enabled' => true,
        'name' => 'カレンダー',
        'role' => '今日以降1週間の予定の確認',
        'date_property' => 'Date',
        'status_property' => '',
        'genre_property' => 'ジャンル',
        'lookback_days' => 0,
        'lookahead_days' => 7,
        'exclude_statuses' => [],
        'filter_property_ids' => [],
    ],

];

$sources = \App\SourceConfigBuilder::buildSources($dataSourceIds, $baseSources);

return [
    'timezone' => $env('APP_TIMEZONE', 'Asia/Saigon'),
    'log_path' => $env('LOG_PATH', 'app/logs/daily_report.log'),
    'notion' => [
        'api_key' => $env('NOTION_API_KEY', ''),
        'version' => $env('NOTION_VERSION', '2026-03-11'),
        'timeout' => (int) $env('NOTION_TIMEOUT', 20),
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
