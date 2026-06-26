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
        private readonly array $modelCandidates = [],
        ?string $caBundlePath = null
    ) {
        $options = [
            'base_uri' => self::BASE_URI,
            'timeout' => $timeout,
        ];
        if ($caBundlePath !== null && trim($caBundlePath) !== '') {
            $options['verify'] = $caBundlePath;
        }

        $this->client = $client ?? new Client($options);
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
            '入力はPHPで整形済みの今日と近日の予定、および先頭の日付・曜日行です。整形や再分類はしないでください。',
            'ユーザーはベトナム・ホーチミン在住の日本人ブリッジSEです。挨拶は現地生活に寄せつつ、同じ気候ネタに偏らないでください。',
            '冒頭の切り口は日替わりで変えてください。候補は、スポーツ、時事一般、ベトナム・ホーチミンのローカル生活、日本との違い、仕事の集中、学校・家族、季節感、週末・月初月末、祝日・記念日です。',
            '入力の先頭の日付・曜日、予定内容、月初・月末、週の始まり・週末前から自然に選べる切り口を1〜2個だけ使ってください。',
            '「ホーチミンは雨季で」「蒸し暑い一日」などの天候・雨季表現を毎回の定型句にしないでください。天候に触れる場合も、移動、体調、洗濯、渋滞など実用的な一言に留めてください。',
            '最新ニュース、試合結果、為替、政治・事故・災害など、入力にない現在情報は断定しないでください。時事やスポーツに触れる場合は、一般的な観点や日付に紐づく軽い話題として扱ってください。',
            '予定に「学校」と分類されているものはユーザーの子供の学校予定として扱い、仕事や生活の予定とは分けてコメントしてください。',
            '出力は自然な文章にしてください。文数や行数を2〜4に制限する必要はありません。見出し、箇条書き、番号付きリスト、URL、予定の再掲は出力しないでください。',
            'EC、AI、ベトナムローカル情報は、予定や日付に自然につながる場合だけ短く触れてください。',
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
