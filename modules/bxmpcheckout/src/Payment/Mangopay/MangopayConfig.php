<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Payment\Mangopay;

/** Mangopay connection settings (module configuration BXMPCO_MANGOPAY_*). */
final class MangopayConfig
{
    public const MODE_SIMULATION = 'sandbox_simulation';
    public const MODE_SANDBOX = 'sandbox';
    public const MODE_LIVE = 'live';

    public function __construct(
        public readonly string $clientId = 'brainx-poc',
        public readonly string $mode = self::MODE_SIMULATION,
        /** escrow wallet receiving the PayIn; empty = one simulated wallet per customer */
        public readonly string $escrowWalletId = '',
        /** Mangopay user owning the escrow wallet; empty = simulated buyer user per customer */
        public readonly string $escrowOwnerId = '',
        public readonly string $returnUrl = 'https://example.invalid/mangopay/return',
        public readonly string $apiKey = '',
    ) {
    }

    public function baseUrl(): string
    {
        return $this->mode === self::MODE_LIVE ? 'https://api.mangopay.com' : 'https://api.sandbox.mangopay.com';
    }

    public function hasCredentials(): bool
    {
        return $this->apiKey !== '' && $this->clientId !== '';
    }
}
