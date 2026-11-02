<?php

declare(strict_types=1);

namespace BrainX\Csv\Validation;

/** Outcome of validating one CSV record: typed canonical data, blocking errors, non-blocking warnings. */
final class RowResult
{
    /**
     * @param array<string, mixed> $data canonical typed values
     * @param array<string, mixed> $raw canonical raw strings
     * @param string[] $errors "column: message"
     * @param string[] $warnings
     */
    public function __construct(
        public readonly int $line,
        public readonly array $data,
        public readonly array $raw,
        public readonly array $errors = [],
        public readonly array $warnings = [],
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }
}
