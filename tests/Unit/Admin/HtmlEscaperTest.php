<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Tests\Unit\Admin;

use CodeMatrix\IntegrationInspector\Admin\HtmlEscaper;
use PHPUnit\Framework\TestCase;

final class HtmlEscaperTest extends TestCase
{
    public function testHtmlAndScriptAreRenderedAsText(): void
    {
        $value = '<script>alert("xss")</script><img src=x onerror=alert(1)>';

        $escaped = HtmlEscaper::escape($value);

        self::assertSame(
            '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;&lt;img src=x onerror=alert(1)&gt;',
            $escaped
        );
        self::assertStringNotContainsString('<script', $escaped);
        self::assertStringNotContainsString('<img', $escaped);
    }

    public function testQuotesAreEscapedForHtmlAttributes(): void
    {
        self::assertSame(
            '&quot; onmouseover=&quot;alert(1)',
            HtmlEscaper::escape('" onmouseover="alert(1)')
        );
        self::assertSame('&#039;', HtmlEscaper::escape("'"));
    }

    public function testInvalidUtf8IsSubstitutedInsteadOfBreakingOutput(): void
    {
        self::assertSame('�', HtmlEscaper::escape("\xFF"));
    }

    public function testNullBecomesEmptyString(): void
    {
        self::assertSame('', HtmlEscaper::escape(null));
    }
}
