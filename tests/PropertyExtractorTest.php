<?php

declare(strict_types=1);

namespace Tests;

use App\Exception\PropertyExtractionException;
use App\PropertyExtractor;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class PropertyExtractorTest extends TestCase
{
    private PropertyExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new PropertyExtractor(new DateTimeZone('Asia/Saigon'));
    }

    public function testExtractsTitleDateStatusAndPageMetadata(): void
    {
        $item = $this->extractor->extract($this->page([
            'Name' => [
                'type' => 'title',
                'title' => [
                    ['plain_text' => '請求書確認'],
                ],
            ],
            '期限' => [
                'type' => 'date',
                'date' => ['start' => '2026-04-16'],
            ],
            'ステータス' => [
                'type' => 'status',
                'status' => ['name' => '未着手'],
            ],
        ]), $this->source());

        self::assertSame('請求書確認', $item['title']);
        self::assertSame('2026-04-16', $item['date']);
        self::assertSame('未着手', $item['status']);
        self::assertSame('https://notion.example/page', $item['url']);
        self::assertSame('個人ToDo', $item['source_name']);
    }

    public function testExtractsSelectStatusAndNormalizesDateTime(): void
    {
        $item = $this->extractor->extract($this->page([
            'Name' => [
                'type' => 'title',
                'title' => [
                    ['plain_text' => '会議準備'],
                ],
            ],
            '期限' => [
                'type' => 'date',
                'date' => ['start' => '2026-04-16T23:30:00+09:00'],
            ],
            'ステータス' => [
                'type' => 'select',
                'select' => ['name' => '進行中'],
            ],
        ]), $this->source());

        self::assertSame('2026-04-16', $item['date']);
        self::assertSame('進行中', $item['status']);
    }

    public function testNullDateAndStatusDoNotCrash(): void
    {
        $item = $this->extractor->extract($this->page([
            'Name' => [
                'type' => 'title',
                'title' => [],
            ],
            '期限' => [
                'type' => 'date',
                'date' => null,
            ],
            'ステータス' => [
                'type' => 'status',
                'status' => null,
            ],
        ]), $this->source());

        self::assertSame('無題', $item['title']);
        self::assertNull($item['date']);
        self::assertNull($item['status']);
    }

    public function testMissingConfiguredPropertyThrowsRecoverableException(): void
    {
        $this->expectException(PropertyExtractionException::class);
        $this->expectExceptionMessage('Property "期限" was not found.');

        $this->extractor->extract($this->page([
            'Name' => [
                'type' => 'title',
                'title' => [
                    ['plain_text' => 'No date'],
                ],
            ],
        ]), $this->source());
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private function page(array $properties): array
    {
        return [
            'id' => 'page-id',
            'url' => 'https://notion.example/page',
            'last_edited_time' => '2026-04-16T00:00:00.000Z',
            'properties' => $properties,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function source(): array
    {
        return [
            'name' => '個人ToDo',
            'role' => '今日やるべき作業の確認',
            'date_property' => '期限',
            'status_property' => 'ステータス',
        ];
    }
}
