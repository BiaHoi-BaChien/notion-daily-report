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

    public function summarize(string $schedule): string
    {
        if (!$this->isConfigured()) {
            throw new OpenAIException('OPENAI_API_KEY is required.');
        }

        $lastException = null;
        foreach ($this->modelsToTry() as $model) {
            try {
                return $this->createSummary($schedule, $model);
            } catch (OpenAIException $exception) {
                $lastException = $exception;
            }
        }

        throw $lastException ?? new OpenAIException('No OpenAI model candidates are configured.');
    }

    /**
     */
    private function createSummary(string $schedule, string $model): string
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
                    'input' => $schedule,
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

    private function instructions(): string
    {
        return implode("\n", [
            'あなたは朝の予定確認を手伝うアシスタントです。',
            '入力はPHPで整形済みの今日と近日の予定です。整形や再分類はしないでください。',
            '入力に含まれる予定だけを根拠にし、推測や補完をしないでください。',
            'ユーザーが気持ちよく前向きに今日一日を始められるような、日本語の概要コメントだけを返してください。',
            '出力は2〜4文の自然な文章にしてください。見出し、箇条書き、番号付きリスト、URL、予定の再掲は出力しないでください。',
            '忙しさや注意点はやわらかく伝え、確認の優先度や進め方が自然に分かるコメントにしてください。',
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
