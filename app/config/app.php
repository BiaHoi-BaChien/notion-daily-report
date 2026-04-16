<?php

declare(strict_types=1);

$env = static function (string $key, mixed $default = null): mixed {
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }

    $value = getenv($key);
    return $value === false ? $default : $value;
};

$splitCsv = static function (mixed $value): array {
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    return array_values(array_filter(
        array_map('trim', explode(',', $value)),
        static fn (string $item): bool => $item !== ''
    ));
};

$dataSourceIds = $splitCsv($env('NOTION_DATA_SOURCE_IDS', ''));
if ($dataSourceIds === []) {
    $singleDataSourceId = trim((string) $env('NOTION_DATA_SOURCE_ID', ''));
    if ($singleDataSourceId !== '') {
        $dataSourceIds = [$singleDataSourceId];
    }
}

$baseSources = [
    [
        'enabled' => true,
        'name' => 'ToDo',
        'role' => '今日やるべき作業の確認',
        'date_property' => 'いつまでに',
        'status_property' => 'ステータス',
        'lookback_days' => 1,
        'lookahead_days' => 3,
        'exclude_statuses' => ['完了', 'いつかやる'],
        'filter_property_ids' => [],
    ],
    [
        'enabled' => true,
        'name' => '会議予定',
        'role' => '直近の会議準備',
        'date_property' => '開始日',
        'status_property' => null,
        'lookback_days' => 0,
        'lookahead_days' => 7,
        'exclude_statuses' => [],
        'filter_property_ids' => [],
    ],
];

if (count($dataSourceIds) > count($baseSources)) {
    throw new UnexpectedValueException(sprintf(
        'NOTION_DATA_SOURCE_IDS has %d IDs, but app/config/app.php defines only %d base sources.',
        count($dataSourceIds),
        count($baseSources)
    ));
}

$sources = array_map(
    static function (string $dataSourceId, array $baseSource): array {
        return array_merge($baseSource, [
            'data_source_id' => $dataSourceId,
        ]);
    },
    $dataSourceIds,
    array_slice($baseSources, 0, count($dataSourceIds))
);

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
