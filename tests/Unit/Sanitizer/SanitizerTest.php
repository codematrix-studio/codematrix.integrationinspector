<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Tests\Sanitizer;

use CodeMatrix\IntegrationInspector\Sanitizer\Sanitizer;
use PHPUnit\Framework\TestCase;

final class SanitizerTest extends TestCase
{
    public function testSensitiveValuesAreRedactedRecursively(): void
    {
        $sanitizer = new Sanitizer();

        $actual = $sanitizer->sanitizeMap([
            'Authorization' => 'Bearer secret',
            'nested' => [
                'api_key' => 'private',
                'private_key' => 'private-key-value',
                'credentials' => 'credential-value',
                'safe' => 'visible',
            ],
        ], 16384);

        self::assertSame('[REDACTED]', $actual['Authorization']);
        self::assertSame('[REDACTED]', $actual['nested']['api_key']);
        self::assertSame('[REDACTED]', $actual['nested']['private_key']);
        self::assertSame('[REDACTED]', $actual['nested']['credentials']);
        self::assertSame('visible', $actual['nested']['safe']);
    }

    public function testUnsupportedValuesAreDropped(): void
    {
        $sanitizer = new Sanitizer();

        $actual = $sanitizer->sanitizeMap([
            'resource' => fopen('php://memory', 'r'),
            'safe' => true,
        ], 16384);

        self::assertArrayNotHasKey('resource', $actual);
        self::assertTrue($actual['safe']);
    }

    public function testOversizedMapIsReplacedWithTruncationMarker(): void
    {
        $sanitizer = new Sanitizer();

        $actual = $sanitizer->sanitizeMap([
            'value' => str_repeat('x', 200),
        ], 32);

        self::assertTrue($actual['_inspector_truncated']);
        self::assertSame(212, $actual['_inspector_original_bytes']);
    }

    public function testErrorMessageRedactsCredentialAssignments(): void
    {
        $sanitizer = new Sanitizer();

        $actual = $sanitizer->sanitizeErrorMessage(
            'Request failed: Authorization: Bearer bearer-token password=secret-value private_key=signing-material'
        );

        self::assertStringNotContainsString('bearer-token', $actual);
        self::assertStringNotContainsString('secret-value', $actual);
        self::assertStringNotContainsString('signing-material', $actual);
        self::assertStringContainsString('[REDACTED]', $actual);
    }

    public function testPayloadRequiresBothGlobalAndCallOptIn(): void
    {
        $sanitizer = new Sanitizer();

        self::assertNull($sanitizer->sanitizePayload('body', false, true));
        self::assertNull($sanitizer->sanitizePayload('body', true, false));
        self::assertSame('body', $sanitizer->sanitizePayload('body', true, true));
    }

    public function testPayloadIsLimitedWithoutBreakingUtf8(): void
    {
        $sanitizer = new Sanitizer();
        $payload = str_repeat('я', 40000);

        $actual = $sanitizer->sanitizePayload($payload, true, true);

        self::assertLessThanOrEqual(Sanitizer::PAYLOAD_LIMIT, strlen($actual));
        self::assertTrue(preg_match('//u', $actual) === 1);
    }
}
