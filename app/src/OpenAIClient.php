<?php

declare(strict_types=1);

namespace App;

use App\Exception\OpenAIException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

final class OpenAIClient implements OpenAIClientInterface
{
    private const BASE_URI = 'https://api.openai.com';

    private ClientInterface $client;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        int $timeout,
        ?ClientInterface $client = null,
        private readonly array $modelCandidates = []
    ) {
        $this->client = $client ?? new Client([
            'base_uri' => self::BASE_URI,
            'timeout' => $timeout,
        ]);
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    public function summarize(array $items): string
    {
        if (!$this->isConfigured()) {
            throw new OpenAIException('OPENAI_API_KEY is required.');
        }

        $payload = $this->buildPayload($items);
        $lastException = null;
        foreach ($this->modelsToTry() as $model) {
            try {
                return $this->createSummary($payload, $model);
            } catch (OpenAIException $exception) {
                $lastException = $exception;
            }
        }

        throw $lastException ?? new OpenAIException('No OpenAI model candidates are configured.');
    }

    /**
     * @param array<int, array<string, mixed>> $payload
     */
    private function createSummary(array $payload, string $model): string
    {
        try {
            $response = $this->client->request('POST', '/v1/responses', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'instructions' => $this->instructions(),
                    'input' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ]);
        } catch (RequestException $exception) {
            throw new OpenAIException('OpenAI API request failed: ' . $this->requestErrorMessage($exception), 0, $exception);
        } catch (GuzzleException $exception) {
            throw new OpenAIException('OpenAI API request failed: ' . $exception->getMessage(), 0, $exception);
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new OpenAIException('OpenAI API response was not valid JSON.');
        }

        $text = $this->extractText($decoded);
        if ($text === '') {
            throw new OpenAIException('OpenAI API response did not include output text.');
        }

        return $text;
    }

    /**
     * @return array<int, string>
     */
    public function modelsToTry(): array
    {
        $model = trim($this->model);
        if ($model !== '' && strtolower($model) !== 'auto') {
            return [$model];
        }

        $candidates = array_values(array_filter(
            array_map('strval', $this->modelCandidates),
            static fn (string $candidate): bool => trim($candidate) !== ''
        ));

        return $candidates === [] ? ['gpt-4o-mini', 'gpt-4.1-mini', 'gpt-4o'] : $candidates;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public function buildPayload(array $items): array
    {
        $payload = [];
        foreach (array_slice($items, 0, 100) as $item) {
            $payload[] = [
                'source_name' => $item['source_name'] ?? null,
                'source_role' => $item['source_role'] ?? null,
                'title' => $item['title'] ?? null,
                'date' => $item['date'] ?? null,
                'status' => $item['status'] ?? null,
                'classification' => $item['classification'] ?? null,
                'url' => $item['url'] ?? null,
            ];
        }

        return $payload;
    }

    private function instructions(): string
    {
        return implode("\n", [
            'あなたはNotionの行動項目を人間向けに整理するアシスタントです。',
            '入力JSONに含まれる事実だけを使い、推測や補完をしないでください。',
            '日本語で簡潔に出力してください。',
            'URLがある項目は必ずURLを含めてください。',
            '次の3見出しを必ずこの順番で出力してください: 1. 今日やること 2. 今日確認したほうがいいこと 3. 近日中に準備したほうがいいこと',
            '該当項目がない見出しには「該当なし」と書いてください。',
            '重要度は classification の overdue, today, upcoming, recent_past の順に考慮してください。',
        ]);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractText(array $decoded): string
    {
        if (isset($decoded['output_text']) && is_string($decoded['output_text'])) {
            return trim($decoded['output_text']);
        }

        $parts = [];
        foreach (($decoded['output'] ?? []) as $output) {
            if (!is_array($output)) {
                continue;
            }

            foreach (($output['content'] ?? []) as $content) {
                if (is_array($content) && isset($content['text']) && is_string($content['text'])) {
                    $parts[] = $content['text'];
                }
            }
        }

        return trim(implode("\n", $parts));
    }

    private function requestErrorMessage(RequestException $exception): string
    {
        $response = $exception->getResponse();
        if ($response === null) {
            return $exception->getMessage();
        }

        $decoded = json_decode((string) $response->getBody(), true);
        $message = is_array($decoded) && isset($decoded['error']['message']) && is_string($decoded['error']['message'])
            ? $decoded['error']['message']
            : trim((string) $response->getBody());

        return sprintf('HTTP %d: %s', $response->getStatusCode(), $message);
    }
}
