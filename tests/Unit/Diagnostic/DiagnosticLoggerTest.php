<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Tests\Diagnostic;

use CodeMatrix\IntegrationInspector\Diagnostic\DiagnosticLogger;
use CodeMatrix\IntegrationInspector\Sanitizer\Sanitizer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DiagnosticLoggerTest extends TestCase
{
    public function testFormattedDiagnosticContainsCauseAndRedactsCredentials(): void
    {
        $message = DiagnosticLogger::format(
            'event.begin',
            new RuntimeException('Storage failed: Authorization: Bearer private-token password=private-password'),
            new Sanitizer()
        );

        self::assertStringContainsString('event.begin', $message);
        self::assertStringContainsString(RuntimeException::class, $message);
        self::assertStringNotContainsString('private-token', $message);
        self::assertStringNotContainsString('private-password', $message);
        self::assertStringContainsString('[REDACTED]', $message);
    }

    public function testFormattedDiagnosticIsSingleLine(): void
    {
        $message = DiagnosticLogger::format(
            'inspector.begin',
            new RuntimeException("First line\nSecond line\tThird line"),
            new Sanitizer()
        );

        self::assertStringNotContainsString("\n", $message);
        self::assertStringNotContainsString("\r", $message);
        self::assertStringNotContainsString("\t", $message);
    }
}
