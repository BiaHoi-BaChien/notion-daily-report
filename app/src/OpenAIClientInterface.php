<?php

declare(strict_types=1);

namespace App;

interface OpenAIClientInterface
{
    public function isConfigured(): bool;

    public function summarize(string $schedule): string;
}
