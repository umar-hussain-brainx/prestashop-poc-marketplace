<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Checkout;

use BrainX\Marketplace\Domain\Optimizer\AllocationResult;
use BrainX\Marketplace\Domain\Optimizer\Destination;

/** Result of synchronising a PS cart with the optimizer (kept in a per-request memo). */
final class SyncState
{
    public const OK = 'ok';
    public const EMPTY = 'empty';
    public const UNAVAILABLE = 'unavailable';
    public const ORDERED = 'ordered';

    /**
     * @param array<int, array{id_product:int,id_product_attribute:int,requested:int,available:int,name?:string}> $unavailable
     */
    public function __construct(
        public readonly string $status,
        public readonly ?AllocationResult $result = null,
        public readonly ?Destination $destination = null,
        public readonly array $unavailable = [],
        public readonly string $signature = '',
        public readonly bool $pricesChanged = false,
    ) {
    }

    public function isBlocking(): bool
    {
        return $this->status === self::UNAVAILABLE;
    }

    /** Customer-facing explanation of why checkout is blocked (never substitutes another product). */
    public function blockingMessage(): string
    {
        if (!$this->isBlocking()) {
            return '';
        }
        $parts = [];
        foreach ($this->unavailable as $u) {
            $name = $u['name'] ?? ('product #' . $u['id_product']);
            $parts[] = $u['available'] <= 0
                ? sprintf('"%s" is not offered by any vendor delivering to your address', $name)
                : sprintf('"%s": only %d of %d available from vendors delivering to your address', $name, $u['available'], $u['requested']);
        }

        return 'Checkout is not possible: ' . implode('; ', $parts) . '. Please adjust the quantity or remove the product (we never substitute another product).';
    }
}
