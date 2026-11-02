<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Optimizer;

/** Delivery destination (PrestaShop id_country + postcode). */
final class Destination
{
    public function __construct(
        public readonly int $countryId,
        public readonly string $postcode = '',
        public readonly ?string $countryIso = null,
    ) {
    }

    public function toArray(): array
    {
        return ['id_country' => $this->countryId, 'postcode' => $this->postcode, 'country_iso' => $this->countryIso];
    }
}
