<?php

declare(strict_types=1);

namespace CodeMatrix\\IntegrationInspector\\Admin;

/**
 * Экранирование значений журнала перед выводом в HTML.
 */
final class HtmlEscaper
{
    public static function escape($value): string
    {
        return htmlspecialchars(
            (string) ($value ?? ''),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
