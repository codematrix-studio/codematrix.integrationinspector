<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Diagnostic;

use CodeMatrix\IntegrationInspector\Sanitizer\Sanitizer;
use Throwable;

final class DiagnosticLogger
{
    public static function log(string $stage, Throwable $exception, Sanitizer $sanitizer): void
    {
        try {
            error_log(self::format($stage, $exception, $sanitizer));
        } catch (Throwable) {
            // Сбой диагностического журнала не должен влиять на работу приложения.
        }
    }

    public static function format(string $stage, Throwable $exception, Sanitizer $sanitizer): string
    {
        $message = $sanitizer->sanitizeErrorMessage($exception->getMessage());
        $message = preg_replace('/[\r\n\t]+/', ' ', $message) ?? '';

        return sprintf(
            '[CodeMatrix Integration Inspector] %s: %s: %s',
            $stage,
            $exception::class,
            $message
        );
    }
}
