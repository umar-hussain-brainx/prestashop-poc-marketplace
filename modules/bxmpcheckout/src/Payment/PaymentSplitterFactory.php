<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Payment;

use BrainX\MarketplaceCheckout\Payment\Mangopay\MangopayConfig;
use BrainX\MarketplaceCheckout\Payment\Mangopay\MangopayPaymentSplitter;

/** Picks the PaymentSplitter from the module configuration (BXMPCO_PAYMENT_SPLITTER = mangopay | null). */
final class PaymentSplitterFactory
{
    public const SPLITTERS = ['mangopay', 'null'];

    /** @param array<string, mixed> $cfg keys: splitter, mangopay_client_id, mangopay_mode, mangopay_escrow_wallet, mangopay_escrow_owner, mangopay_api_key */
    public static function create(array $cfg): PaymentSplitter
    {
        if (($cfg['splitter'] ?? 'mangopay') !== 'mangopay') {
            return new NullPaymentSplitter();
        }

        // No live HTTP transport in the POC: every mode currently runs through the simulated transport.
        return new MangopayPaymentSplitter(new MangopayConfig(
            clientId: (string) (($cfg['mangopay_client_id'] ?? '') ?: 'brainx-poc'),
            mode: (string) (($cfg['mangopay_mode'] ?? '') ?: MangopayConfig::MODE_SIMULATION),
            escrowWalletId: (string) ($cfg['mangopay_escrow_wallet'] ?? ''),
            escrowOwnerId: (string) ($cfg['mangopay_escrow_owner'] ?? ''),
            apiKey: (string) ($cfg['mangopay_api_key'] ?? ''),
        ));
    }
}
