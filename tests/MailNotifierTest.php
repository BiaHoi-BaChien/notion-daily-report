<?php

declare(strict_types=1);

namespace Tests;

use App\MailNotifier;
use PHPUnit\Framework\TestCase;

final class MailNotifierTest extends TestCase
{
    public function testParsesCommaSeparatedRecipients(): void
    {
        self::assertSame(
            ['a@example.com', 'b@example.com'],
            MailNotifier::parseRecipients(' a@example.com, b@example.com,, ')
        );
    }

    public function testIsNotConfiguredWithoutRequiredFields(): void
    {
        $notifier = new MailNotifier('', 587, '', '', '', [], 'tls');

        self::assertFalse($notifier->isConfigured());
    }
}
