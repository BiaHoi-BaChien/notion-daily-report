<?php

declare(strict_types=1);

$env = static function (string $key, mixed $default = null): mixed {
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }

    $value = getenv($key);
    return $value === false ? $default : $value;
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
        'lookback_days' => 0,
        'lookahead_days' => 5,
        'exclude_statuses' => ['Release', 'Archived'],
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
        'webhook_url' => $env('SLACK_WEBHOOK_URL', ''),
        'timeout' => (int) $env('SLACK_TIMEOUT', 10),
    ],
    'sources' => $sources,
];
