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

    public function testRendersHtmlBodyForReadableMailLayout(): void
    {
        $html = MailNotifier::renderHtmlBody("1. 今日確認するべきToDo\n  【14:45】確認 <重要> | その他\n");

        self::assertStringContainsString('<pre', $html);
        self::assertStringContainsString('white-space:pre-wrap', $html);
        self::assertStringContainsString("1. 今日確認するべきToDo\n  【14:45】確認 &lt;重要&gt; | その他", $html);
        self::assertStringNotContainsString('確認 <重要>', $html);
    }
}
