<?php

declare(strict_types=1);

namespace App;

use App\Exception\ConfigException;
use Dotenv\Dotenv;
use Throwable;

final class Bootstrap
{
    public static function runCli(string $projectRoot, array $argv): int
    {
        try {
            self::loadAutoload($projectRoot);
            $config = self::loadConfig($projectRoot);
            $timezone = new \DateTimeZone((string) $config['timezone']);
            date_default_timezone_set($timezone->getName());

            $logger = new Logger(self::resolvePath($projectRoot, (string) $config['log_path']), $timezone);
            $notion = $config['notion'];
            $slack = $config['slack'] ?? [];
            $openai = $config['openai'] ?? [];
            $mail = $config['mail'] ?? [];

            $command = new DailyReportCommand(
                $config,
                new NotionClient(
                    (string) $notion['api_key'],
                    (string) $notion['version'],
                    (int) $notion['timeout']
                ),
                new PropertyExtractor($timezone),
                new DateFilter($timezone),
                new ReportBuilder($timezone),
                $logger,
                $timezone,
                true,
                new SlackNotifier(
                    (string) ($slack['webhook_url'] ?? ''),
                    (int) ($slack['timeout'] ?? 10)
                ),
                new OpenAIClient(
                    (string) ($openai['api_key'] ?? ''),
                    (string) ($openai['model'] ?? 'gpt-4.1-mini'),
                    (int) ($openai['timeout'] ?? 30)
                ),
                new MailNotifier(
                    (string) ($mail['host'] ?? ''),
                    (int) ($mail['port'] ?? 587),
                    (string) ($mail['user'] ?? ''),
                    (string) ($mail['password'] ?? ''),
                    (string) ($mail['from'] ?? ''),
                    is_array($mail['to'] ?? null) ? $mail['to'] : [],
                    (string) ($mail['secure'] ?? 'tls')
                )
            );

            return $command->run($argv);
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Fatal: ' . $exception->getMessage() . PHP_EOL);
            return 1;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function loadConfig(string $projectRoot): array
    {
        self::loadDotenv($projectRoot);

        $configPath = $projectRoot . '/app/config/app.php';
        if (!is_file($configPath)) {
            throw new ConfigException('Config file not found: ' . $configPath);
        }

        $config = require $configPath;
        if (!is_array($config)) {
            throw new ConfigException('Config file must return an array.');
        }

        self::validateConfig($config);
        return $config;
    }

    private static function loadAutoload(string $projectRoot): void
    {
        $autoloadPath = $projectRoot . '/vendor/autoload.php';
        if (!is_file($autoloadPath)) {
            throw new ConfigException('Composer autoload not found. Run "composer install" first.');
        }

        require_once $autoloadPath;
    }

    private static function loadDotenv(string $projectRoot): void
    {
        if (class_exists(Dotenv::class)) {
            Dotenv::createImmutable($projectRoot)->safeLoad();
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function validateConfig(array $config): void
    {
        foreach (['timezone', 'log_path', 'notion', 'sources'] as $key) {
            if (!array_key_exists($key, $config)) {
                throw new ConfigException(sprintf('Missing config key: %s', $key));
            }
        }

        if (!is_array($config['notion'])) {
            throw new ConfigException('Config key "notion" must be an array.');
        }

        if (trim((string) ($config['notion']['api_key'] ?? '')) === '') {
            throw new ConfigException('NOTION_API_KEY is required.');
        }

        if (!is_array($config['sources']) || $config['sources'] === []) {
            throw new ConfigException('At least one source is required.');
        }
    }

    private static function resolvePath(string $projectRoot, string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, '/')) {
            return $path;
        }

        return $projectRoot . '/' . str_replace('\\', '/', $path);
    }
}
