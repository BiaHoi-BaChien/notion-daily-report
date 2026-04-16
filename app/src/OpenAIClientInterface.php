<?php

declare(strict_types=1);

namespace App;

interface OpenAIClientInterface
{
    public function isConfigured(): bool;

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function summarize(array $items): string;
}
