<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Api;

final readonly class EventOutcome
{
    public function __construct(
        public ?int $httpStatus = null,
        public ?string $resultCode = null,
        public array $responseMetadata = [],
        public ?string $responsePayload = null,
    ) {
        if ($this->httpStatus !== null && ($this->httpStatus < 100 || $this->httpStatus > 599)) {
            throw new \InvalidArgumentException('httpStatus must be between 100 and 599.');
        }

        if ($this->resultCode !== null && ($this->resultCode === '' || strlen($this->resultCode) > 64)) {
            throw new \InvalidArgumentException('resultCode must contain 1 to 64 bytes.');
        }
    }
}
