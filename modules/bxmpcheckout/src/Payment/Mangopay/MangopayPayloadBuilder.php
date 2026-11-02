<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Payment\Mangopay;

use BrainX\MarketplaceCheckout\Payment\PaymentSplitRequest;
use BrainX\MarketplaceCheckout\Payment\VendorShare;

/**
 * Pure builder of the Mangopay v2.01 API calls for one split order:
 *
 *  1. PayIn of the whole customer payment into the escrow wallet
 *     - bank wire  → POST /v2.01/{ClientId}/payins/bankwire/direct (DeclaredDebitedFunds / DeclaredFees)
 *     - otherwise  → POST /v2.01/{ClientId}/payins/card/web        (DebitedFunds / Fees, ReturnURL)
 *  2. one Transfer per vendor: escrow wallet → vendor wallet,
 *     DebitedFunds = vendor gross (products + shipping), Fees = platform commission
 *     ⇒ the vendor wallet is credited with DebitedFunds − Fees = vendor payout, the fees land in the platform fees wallet.
 *
 * Amounts are integers in cents (Mangopay convention). Anything paid above Σ vendor gross (e.g. VAT collected on the
 * parent order) stays in escrow and is reported as `residual` for the platform to handle.
 */
final class MangopayPayloadBuilder
{
    public function __construct(private readonly MangopayConfig $config = new MangopayConfig())
    {
    }

    public static function cents(float $amount): int
    {
        return (int) round($amount * 100 + ($amount >= 0 ? 1e-7 : -1e-7));
    }

    public function authorId(PaymentSplitRequest $r): string
    {
        return $this->config->escrowOwnerId !== '' ? $this->config->escrowOwnerId : 'SIM-USER-C' . $r->idCustomer;
    }

    public function escrowWalletId(PaymentSplitRequest $r): string
    {
        return $this->config->escrowWalletId !== '' ? $this->config->escrowWalletId : 'SIM-WALLET-C' . $r->idCustomer;
    }

    /** @return array{method:string, path:string, idempotency_key:string, body:array<string,mixed>} */
    public function payIn(PaymentSplitRequest $r): array
    {
        $currency = strtoupper($r->currencyIso);
        $amount = self::cents($r->amountPaid);
        $tag = 'order:' . $r->orderReference;
        if (self::isBankWire($r->paymentModule)) {
            return [
                'method' => 'POST',
                'path' => '/v2.01/' . $this->config->clientId . '/payins/bankwire/direct',
                'idempotency_key' => self::idempotencyKey('payin', $r->orderReference),
                'body' => [
                    'AuthorId' => $this->authorId($r),
                    'CreditedWalletId' => $this->escrowWalletId($r),
                    'DeclaredDebitedFunds' => ['Currency' => $currency, 'Amount' => $amount],
                    'DeclaredFees' => ['Currency' => $currency, 'Amount' => 0],
                    'Tag' => $tag,
                ],
            ];
        }

        return [
            'method' => 'POST',
            'path' => '/v2.01/' . $this->config->clientId . '/payins/card/web',
            'idempotency_key' => self::idempotencyKey('payin', $r->orderReference),
            'body' => [
                'AuthorId' => $this->authorId($r),
                'CreditedWalletId' => $this->escrowWalletId($r),
                'DebitedFunds' => ['Currency' => $currency, 'Amount' => $amount],
                'Fees' => ['Currency' => $currency, 'Amount' => 0],
                'ReturnURL' => $this->config->returnUrl,
                'CardType' => 'CB_VISA_MASTERCARD',
                'Culture' => 'EN',
                'Tag' => $tag,
            ],
        ];
    }

    /** @return array{method:string, path:string, idempotency_key:string, body:array<string,mixed>} */
    public function transfer(PaymentSplitRequest $r, VendorShare $s): array
    {
        if ($s->walletId === null || trim($s->walletId) === '') {
            throw new \InvalidArgumentException(sprintf('Vendor #%d has no Mangopay wallet (bxmp_vendor.payout_account)', $s->idVendor));
        }
        $currency = strtoupper($r->currencyIso);

        return [
            'method' => 'POST',
            'path' => '/v2.01/' . $this->config->clientId . '/transfers',
            'idempotency_key' => self::idempotencyKey('transfer', $s->vendorOrderReference),
            'body' => [
                'AuthorId' => $this->authorId($r),
                'DebitedFunds' => ['Currency' => $currency, 'Amount' => self::cents($s->gross)],
                'Fees' => ['Currency' => $currency, 'Amount' => self::cents($s->commission)],
                'DebitedWalletId' => $this->escrowWalletId($r),
                'CreditedWalletId' => trim($s->walletId),
                'Tag' => 'vendor-order:' . $s->vendorOrderReference,
            ],
        ];
    }

    /**
     * Full plan: PayIn + transfers + reconciliation summary (cents).
     *
     * @return array{payin: array, transfers: array<int, array>, summary: array<string,int|string>}
     */
    public function build(PaymentSplitRequest $r): array
    {
        $payIn = $this->payIn($r);
        $transfers = [];
        $debited = 0;
        $fees = 0;
        foreach ($r->shares as $s) {
            $t = $this->transfer($r, $s);
            $transfers[$s->idVendor] = $t;
            $debited += $t['body']['DebitedFunds']['Amount'];
            $fees += $t['body']['Fees']['Amount'];
        }
        $paid = self::cents($r->amountPaid);
        if ($debited > $paid) {
            throw new \InvalidArgumentException(sprintf('Vendor transfers (%d cents) exceed the customer payment (%d cents)', $debited, $paid));
        }

        return [
            'payin' => $payIn,
            'transfers' => $transfers,
            'summary' => [
                'currency' => strtoupper($r->currencyIso),
                'payin_amount' => $paid,
                'transfers_debited' => $debited,
                'platform_fees' => $fees,
                'vendors_credited' => $debited - $fees,
                'residual_in_escrow' => $paid - $debited,
            ],
        ];
    }

    public static function isBankWire(string $paymentModule): bool
    {
        return in_array(strtolower($paymentModule), ['ps_wirepayment', 'bankwire'], true);
    }

    public static function idempotencyKey(string $kind, string $ref): string
    {
        return substr(hash('sha256', 'bxmp|' . $kind . '|' . $ref), 0, 32);
    }
}
