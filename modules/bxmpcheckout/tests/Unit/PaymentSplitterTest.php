<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Tests;

use BrainX\MarketplaceCheckout\Payment\Mangopay\MangopayPaymentSplitter;
use BrainX\MarketplaceCheckout\Payment\Mangopay\MangopayTransport;
use BrainX\MarketplaceCheckout\Payment\Mangopay\SimulatedMangopayTransport;
use BrainX\MarketplaceCheckout\Payment\NullPaymentSplitter;
use BrainX\MarketplaceCheckout\Payment\PaymentSplitRequest;
use BrainX\MarketplaceCheckout\Payment\PaymentSplitResult;
use BrainX\MarketplaceCheckout\Payment\PaymentSplitterFactory;
use BrainX\MarketplaceCheckout\Payment\VendorShare;
use BrainX\MarketplaceCheckout\Payment\Mangopay\MangopayConfig;
use PHPUnit\Framework\TestCase;

final class PaymentSplitterTest extends TestCase
{
    public function testSimulatedMangopaySplit(): void
    {
        $transport = new SimulatedMangopayTransport();
        $splitter = new MangopayPaymentSplitter(new MangopayConfig(), $transport);
        $res = $splitter->split(MangopayPayloadBuilderTest::clientScenario());

        self::assertSame('mangopay-sim', $splitter->name());
        self::assertSame(PaymentSplitResult::STATUS_SIMULATED, $res->status);
        self::assertStringStartsWith('SIM-PAYIN-', (string) $res->payInRef);
        self::assertCount(2, $res->transferRefs);
        self::assertCount(3, $transport->calls); // 1 PayIn + 2 transfers
        self::assertSame(950, $res->payloads['responses']['transfers'][1]['CreditedFunds']['Amount']);
        self::assertSame(2187, $res->payloads['responses']['transfers'][2]['CreditedFunds']['Amount']);
        self::assertSame('CREATED', $res->payloads['responses']['payin']['Status']); // bank wire: awaiting funds
        $ref = $res->referenceFor(1);
        self::assertMatchesRegularExpression('/^mangopay-sim:SIM-TR-[0-9A-F]+\|SIM-PAYIN-[0-9A-F]+$/', (string) $ref);
        self::assertLessThanOrEqual(128, strlen((string) $ref));
        self::assertTrue($res->isOk());
    }

    public function testReplayGivesSameSimulatedIds(): void
    {
        $a = (new MangopayPaymentSplitter())->split(MangopayPayloadBuilderTest::clientScenario());
        $b = (new MangopayPaymentSplitter())->split(MangopayPayloadBuilderTest::clientScenario());
        self::assertSame($a->transferRefs, $b->transferRefs);
        self::assertSame($a->payInRef, $b->payInRef);
    }

    public function testMissingWalletFailsWithoutCallingTheApi(): void
    {
        $transport = new SimulatedMangopayTransport();
        $req = new PaymentSplitRequest(1, 'R', 1, 'EUR', 10, 'ps_wirepayment', [new VendorShare(3, 1, 'R-V3', '', 10, 1)]);
        $res = (new MangopayPaymentSplitter(new MangopayConfig(), $transport))->split($req);
        self::assertSame(PaymentSplitResult::STATUS_FAILED, $res->status);
        self::assertSame([], $transport->calls);
        self::assertFalse($res->isOk());
    }

    public function testTransportErrorIsReportedAsFailure(): void
    {
        $failing = new class implements MangopayTransport {
            public function send(array $call): array
            {
                if (str_ends_with($call['path'], '/transfers')) {
                    throw new \RuntimeException('HTTP 500');
                }

                return ['Id' => 'P-1', 'Status' => 'SUCCEEDED'];
            }
        };
        $splitter = new MangopayPaymentSplitter(new MangopayConfig(), $failing);
        self::assertSame('mangopay', $splitter->name());
        $res = $splitter->split(MangopayPayloadBuilderTest::clientScenario());
        self::assertSame(PaymentSplitResult::STATUS_FAILED, $res->status);
        self::assertSame('P-1', $res->payInRef);
        self::assertStringContainsString('HTTP 500', $res->message);
    }

    public function testNullSplitterAndFactory(): void
    {
        $null = PaymentSplitterFactory::create(['splitter' => 'null']);
        self::assertInstanceOf(NullPaymentSplitter::class, $null);
        $res = $null->split(MangopayPayloadBuilderTest::clientScenario());
        self::assertSame(PaymentSplitResult::STATUS_SKIPPED, $res->status);
        self::assertNull($res->referenceFor(1));
        self::assertInstanceOf(MangopayPaymentSplitter::class, PaymentSplitterFactory::create(['splitter' => 'mangopay']));
        self::assertInstanceOf(MangopayPaymentSplitter::class, PaymentSplitterFactory::create([]));
    }
}
