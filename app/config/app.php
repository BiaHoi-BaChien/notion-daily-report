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

$baseSource = [
    'enabled' => true,
    'name' => 'ToDo',
    'role' => '今日やるべき作業の確認',
    'date_property' => 'いつまでに',
    'status_property' => 'ステータス',
    'lookback_days' => 1,
    'lookahead_days' => 3,
    'exclude_statuses' => ['完了', 'いつかやる'],
    'filter_property_ids' => [],
];

$sources = array_map(
    static function (string $dataSourceId, int $index) use ($baseSource): array {
        return array_merge($baseSource, [
            'name' => sprintf('ToDo %d', $index + 1),
            'data_source_id' => $dataSourceId,
        ]);
    },
    $dataSourceIds,
    array_keys($dataSourceIds)
);

if (count($sources) === 1) {
    $sources[0]['name'] = 'ToDo';
}

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
