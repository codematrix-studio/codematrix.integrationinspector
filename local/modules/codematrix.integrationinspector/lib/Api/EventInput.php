<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Api;

final readonly class EventInput
{
    public function __construct(
        public string $sourceCode,
        public string $operation,
        public EventDirection $direction,
        public ?string $correlationId = null,
        public ?string $parentEventId = null,
        public array $requestMetadata = [],
        public ?string $requestPayload = null,
        public array $context = [],
        public ?\\DateTimeImmutable $occurredAt = null,
    ) {
        if (!preg_match('/^[a-z0-9._-]{1,64}$/D', $this->sourceCode)) {
            throw new \\InvalidArgumentException('sourceCode must be a lowercase ASCII slug of up to 64 characters.');
        }

        if (trim($this->operation) === '' || strlen($this->operation) > 128) {
            throw new \\InvalidArgumentException('operation must contain 1 to 128 bytes.');
        }

        if ($this->correlationId !== null && ($this->correlationId === '' || strlen($this->correlationId) > 128)) {
            throw new \\InvalidArgumentException('correlationId must contain 1 to 128 bytes.');
        }

        if ($this->parentEventId !== null && !preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',
            $this->parentEventId
        )) {
            throw new \\InvalidArgumentException('parentEventId must be a UUID v4.');
        }
    }
}
