<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Infrastructure\Api;

final class ApiException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly string $errorCode = 'error', public readonly array $details = [])
    {
        parent::__construct($message, $status);
    }

    public function toArray(): array
    {
        return ['error' => ['code' => $this->errorCode, 'message' => $this->getMessage()] + ($this->details !== [] ? ['details' => $this->details] : [])];
    }
}
