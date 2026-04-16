<?php

declare(strict_types=1);

$env = static function (string $key, mixed $default = null): mixed {
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }

    $value = getenv($key);
    return $value === false ? $default : $value;
};

return [
    'timezone' => $env('APP_TIMEZONE', 'Asia/Saigon'),
    'log_path' => $env('LOG_PATH', 'app/logs/daily_report.log'),
    'notion' => [
        'api_key' => $env('NOTION_API_KEY', ''),
        'version' => $env('NOTION_VERSION', '2026-03-11'),
        'timeout' => (int) $env('NOTION_TIMEOUT', 20),
    ],
    'sources' => [
        [
            'enabled' => true,
            'name' => '個人ToDo',
            'role' => '今日やるべき作業の確認',
            'data_source_id' => $env('NOTION_DATA_SOURCE_ID', ''),
            'date_property' => '期限',
            'status_property' => 'ステータス',
            'lookback_days' => 1,
            'lookahead_days' => 3,
            'exclude_statuses' => ['完了', '中止'],
            'filter_property_ids' => [],
        ],
    ],
];
