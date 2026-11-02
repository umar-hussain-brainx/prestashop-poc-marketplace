<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Infrastructure\Api;

/** Authenticated API caller (one bxmp_api_token). idVendor null = admin scope. */
final class ApiContext
{
    public function __construct(public readonly int $idApiToken, public readonly ?int $idVendor, public readonly string $label = '')
    {
    }

    public static function fromTokenRow(array $row): self
    {
        return new self((int) $row['id_api_token'], empty($row['id_vendor']) ? null : (int) $row['id_vendor'], (string) ($row['label'] ?? ''));
    }

    public function isAdmin(): bool
    {
        return $this->idVendor === null;
    }

    /** Throws 403 unless admin or the given vendor. */
    public function assertCanAccessVendor(int $idVendor): void
    {
        if (!$this->isAdmin() && $this->idVendor !== $idVendor) {
            throw new ApiException(403, 'Token is scoped to another vendor', 'forbidden');
        }
    }
}
