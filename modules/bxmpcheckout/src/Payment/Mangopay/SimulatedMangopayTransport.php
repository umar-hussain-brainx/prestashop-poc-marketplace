<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Payment\Mangopay;

/**
 * "Sandbox simulation" transport used while no Mangopay credentials are available: nothing leaves the server,
 * responses mimic the API (deterministic ids derived from the idempotency key, so replays return the same ids).
 */
final class SimulatedMangopayTransport implements MangopayTransport
{
    /** @var array<int, array> calls received (audit / tests) */
    public array $calls = [];

    public function send(array $call): array
    {
        $this->calls[] = $call;
        $suffix = strtoupper(substr($call['idempotency_key'], 0, 10));
        $body = $call['body'];
        if (str_contains($call['path'], '/payins/bankwire/')) {
            return ['Id' => 'SIM-PAYIN-' . $suffix, 'Status' => 'CREATED', 'Type' => 'PAYIN', 'PaymentType' => 'BANK_WIRE',
                'CreditedWalletId' => $body['CreditedWalletId'], 'DeclaredDebitedFunds' => $body['DeclaredDebitedFunds'],
                'WireReference' => substr($suffix, 0, 8), 'Tag' => $body['Tag'] ?? null];
        }
        if (str_contains($call['path'], '/payins/')) {
            return ['Id' => 'SIM-PAYIN-' . $suffix, 'Status' => 'SUCCEEDED', 'Type' => 'PAYIN', 'PaymentType' => 'CARD',
                'CreditedWalletId' => $body['CreditedWalletId'], 'DebitedFunds' => $body['DebitedFunds'], 'Tag' => $body['Tag'] ?? null];
        }
        if (str_ends_with($call['path'], '/transfers')) {
            $credited = $body['DebitedFunds']['Amount'] - $body['Fees']['Amount'];

            return ['Id' => 'SIM-TR-' . $suffix, 'Status' => 'SUCCEEDED', 'Type' => 'TRANSFER',
                'DebitedWalletId' => $body['DebitedWalletId'], 'CreditedWalletId' => $body['CreditedWalletId'],
                'DebitedFunds' => $body['DebitedFunds'], 'Fees' => $body['Fees'],
                'CreditedFunds' => ['Currency' => $body['DebitedFunds']['Currency'], 'Amount' => $credited], 'Tag' => $body['Tag'] ?? null];
        }

        throw new \InvalidArgumentException('Unsupported simulated Mangopay call ' . $call['path']);
    }
}
