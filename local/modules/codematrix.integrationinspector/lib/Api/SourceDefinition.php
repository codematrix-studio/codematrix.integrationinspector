<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Api;

final readonly class SourceDefinition
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $description = null,
    ) {
        if (!preg_match('/^[a-z0-9._-]{1,64}$/D', $this->code)) {
            throw new \\InvalidArgumentException('Source code must be a lowercase ASCII slug of up to 64 characters.');
        }

        if (trim($this->name) === '' || strlen($this->name) > 255) {
            throw new \\InvalidArgumentException('Source name must contain 1 to 255 bytes.');
        }

        if ($this->description !== null && strlen($this->description) > 1000) {
            throw new \\InvalidArgumentException('Source description must not exceed 1000 bytes.');
        }
    }
}
