<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Tests;

use BrainX\MarketplaceCheckout\Payment\Mangopay\MangopayConfig;
use BrainX\MarketplaceCheckout\Payment\Mangopay\MangopayPayloadBuilder;
use BrainX\MarketplaceCheckout\Payment\PaymentSplitRequest;
use BrainX\MarketplaceCheckout\Payment\VendorShare;
use PHPUnit\Framework\TestCase;

final class MangopayPayloadBuilderTest extends TestCase
{
    /** Client scenario: A = P1 €5 + €5 shipping (10 %), B = P2 €25 free shipping (12.5 %), customer pays €35. */
    public static function clientScenario(string $module = 'ps_wirepayment', float $paid = 35.0): PaymentSplitRequest
    {
        return new PaymentSplitRequest(42, 'ABCDEFGHI', 6, 'eur', $paid, $module, [
            new VendorShare(1, 101, 'ABCDEFGHI-V1', 'mangopay-sandbox-wallet-vendor-a', 10.0, 0.5, 'Vendor A'),
            new VendorShare(2, 102, 'ABCDEFGHI-V2', 'mangopay-sandbox-wallet-vendor-b', 25.0, 3.13, 'Vendor B'),
        ]);
    }

    public function testCentsRoundsHalfUp(): void
    {
        self::assertSame(3500, MangopayPayloadBuilder::cents(35.0));
        self::assertSame(313, MangopayPayloadBuilder::cents(3.125));
        self::assertSame(12023, MangopayPayloadBuilder::cents(120.23));
        self::assertSame(1, MangopayPayloadBuilder::cents(0.005));
    }

    public function testBankWirePayInGoesToEscrowWallet(): void
    {
        $b = new MangopayPayloadBuilder(new MangopayConfig(clientId: 'acme', escrowWalletId: 'W-ESCROW', escrowOwnerId: 'U-PLATFORM'));
        $call = $b->payIn(self::clientScenario());
        self::assertSame('POST', $call['method']);
        self::assertSame('/v2.01/acme/payins/bankwire/direct', $call['path']);
        self::assertSame('W-ESCROW', $call['body']['CreditedWalletId']);
        self::assertSame('U-PLATFORM', $call['body']['AuthorId']);
        self::assertSame(['Currency' => 'EUR', 'Amount' => 3500], $call['body']['DeclaredDebitedFunds']);
        self::assertSame(0, $call['body']['DeclaredFees']['Amount']);
        self::assertSame('order:ABCDEFGHI', $call['body']['Tag']);
    }

    public function testOtherPaymentModulesUseCardWebPayIn(): void
    {
        $call = (new MangopayPayloadBuilder())->payIn(self::clientScenario('ps_checkpayment'));
        self::assertStringEndsWith('/payins/card/web', $call['path']);
        self::assertSame(3500, $call['body']['DebitedFunds']['Amount']);
        self::assertSame('SIM-WALLET-C6', $call['body']['CreditedWalletId']);
        self::assertSame('SIM-USER-C6', $call['body']['AuthorId']);
        self::assertArrayHasKey('ReturnURL', $call['body']);
    }

    public function testTransferFeesAreTheCommission(): void
    {
        $r = self::clientScenario();
        $t = (new MangopayPayloadBuilder())->transfer($r, $r->shares[1]);
        self::assertStringEndsWith('/transfers', $t['path']);
        self::assertSame(['Currency' => 'EUR', 'Amount' => 2500], $t['body']['DebitedFunds']);
        self::assertSame(['Currency' => 'EUR', 'Amount' => 313], $t['body']['Fees']);
        self::assertSame('SIM-WALLET-C6', $t['body']['DebitedWalletId']);
        self::assertSame('mangopay-sandbox-wallet-vendor-b', $t['body']['CreditedWalletId']);
        self::assertSame('vendor-order:ABCDEFGHI-V2', $t['body']['Tag']);
    }

    public function testBuildReconcilesTheClientScenario(): void
    {
        $plan = (new MangopayPayloadBuilder())->build(self::clientScenario());
        self::assertCount(2, $plan['transfers']);
        self::assertSame([
            'currency' => 'EUR',
            'payin_amount' => 3500,
            'transfers_debited' => 3500,
            'platform_fees' => 363,
            'vendors_credited' => 3137,
            'residual_in_escrow' => 0,
        ], $plan['summary']);
    }

    public function testVatAboveVendorGrossStaysInEscrow(): void
    {
        $plan = (new MangopayPayloadBuilder())->build(self::clientScenario('ps_wirepayment', 42.70));
        self::assertSame(770, $plan['summary']['residual_in_escrow']);
    }

    public function testTransfersCannotExceedThePayment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MangopayPayloadBuilder())->build(self::clientScenario('ps_wirepayment', 30.0));
    }

    public function testVendorWithoutWalletIsRejected(): void
    {
        $r = new PaymentSplitRequest(1, 'REF', 1, 'EUR', 10, 'ps_wirepayment', [new VendorShare(9, 1, 'REF-V9', null, 10, 1)]);
        $this->expectException(\InvalidArgumentException::class);
        (new MangopayPayloadBuilder())->build($r);
    }

    public function testIdempotencyKeysAreDeterministicPerReference(): void
    {
        self::assertSame(MangopayPayloadBuilder::idempotencyKey('transfer', 'X-V1'), MangopayPayloadBuilder::idempotencyKey('transfer', 'X-V1'));
        self::assertNotSame(MangopayPayloadBuilder::idempotencyKey('transfer', 'X-V1'), MangopayPayloadBuilder::idempotencyKey('transfer', 'X-V2'));
        self::assertSame(32, strlen(MangopayPayloadBuilder::idempotencyKey('payin', 'X')));
    }

    public function testCommissionAboveGrossIsInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VendorShare(1, 1, 'R', 'w', 5.0, 6.0);
    }
}
