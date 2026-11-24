<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Sanitizer;

final class Sanitizer
{
    public const REQUEST_METADATA_LIMIT = 16 * 1024;
    public const RESPONSE_METADATA_LIMIT = 16 * 1024;
    public const CONTEXT_LIMIT = 8 * 1024;
    public const PAYLOAD_LIMIT = 64 * 1024;
    public const ERROR_MESSAGE_LIMIT = 1000;
    public const MAX_DEPTH = 8;

    private const SENSITIVE_KEY_PATTERN =
        '/(?:authorization|proxy-authorization|cookie|set-cookie|password|passwd|token|secret|api[_-]?key|access[_-]?key|client[_-]?secret|private[_-]?key|signing[_-]?key|encryption[_-]?key|credentials?|session[_-]?id)/i';

    public function sanitizeMap(array $data, int $maxBytes): array
    {
        $clean = $this->sanitizeArray($data, 0);
        $encoded = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            return [];
        }

        if (strlen($encoded) <= $maxBytes) {
            return $clean;
        }

        return [
            '_inspector_truncated' => true,
            '_inspector_original_bytes' => strlen($encoded),
        ];
    }

    public function sanitizePayload(
        ?string $payload,
        bool $globallyEnabled,
        bool $explicitOptIn
    ): ?string {
        if ($payload === null || !$globallyEnabled || !$explicitOptIn) {
            return null;
        }

        if (strlen($payload) <= self::PAYLOAD_LIMIT) {
            return $payload;
        }

        return $this->truncateUtf8($payload, self::PAYLOAD_LIMIT);
    }

    public function sanitizeErrorMessage(string $message): string
    {
        $message = preg_replace(
            '/\b(authorization|proxy-authorization|cookie|set-cookie|password|passwd|token|secret|api[_-]?key|access[_-]?key|client[_-]?secret|private[_-]?key|signing[_-]?key|encryption[_-]?key|credentials?|session[_-]?id)\b(\s*[:=]\s*)(?:Bearer\s+)?([^\s,;]+)/i',
            '$1$2[REDACTED]',
            $message
        ) ?? '';

        return $this->truncateUtf8($message, self::ERROR_MESSAGE_LIMIT);
    }

    private function sanitizeArray(array $data, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['_inspector_truncated' => 'max_depth'];
        }

        $result = [];

        foreach ($data as $key => $value) {
            if (!is_int($key) && !is_string($key)) {
                continue;
            }

            if (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key)) {
                $result[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->sanitizeArray($value, $depth + 1);
                continue;
            }

            if (is_string($value)) {
                $result[$key] = $this->truncateUtf8($value, self::PAYLOAD_LIMIT);
                continue;
            }

            if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function truncateUtf8(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        if (function_exists('mb_strcut')) {
            return mb_strcut($value, 0, $maxBytes, 'UTF-8');
        }

        $value = substr($value, 0, $maxBytes);

        while ($value !== '' && !preg_match('//u', $value)) {
            $value = substr($value, 0, -1);
        }

        return $value;
    }
}
