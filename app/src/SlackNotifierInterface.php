<?php

declare(strict_types=1);

namespace App;

interface SlackNotifierInterface
{
    public function isConfigured(): bool;

    public function send(string $text): void;
}
