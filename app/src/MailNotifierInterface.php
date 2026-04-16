<?php

declare(strict_types=1);

namespace App;

interface MailNotifierInterface
{
    public function isConfigured(): bool;

    public function send(string $subject, string $body): void;
}
