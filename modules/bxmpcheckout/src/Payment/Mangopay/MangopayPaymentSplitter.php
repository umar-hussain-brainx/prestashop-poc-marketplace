<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Payment\Mangopay;

use BrainX\MarketplaceCheckout\Payment\PaymentSplitRequest;
use BrainX\MarketplaceCheckout\Payment\PaymentSplitResult;
use BrainX\MarketplaceCheckout\Payment\PaymentSplitter;

/**
 * Mangopay adapter: PayIn to escrow + one Transfer per vendor wallet with Fees = commission.
 * In `sandbox_simulation` mode (default, no credentials) the SimulatedMangopayTransport answers; plugging a real HTTP
 * transport (OAuth client credentials against api.sandbox.mangopay.com) is the only missing piece for the sandbox.
 */
final class MangopayPaymentSplitter implements PaymentSplitter
{
    private MangopayPayloadBuilder $builder;
    private MangopayTransport $transport;

    public function __construct(private readonly MangopayConfig $config = new MangopayConfig(), ?MangopayTransport $transport = null)
    {
        $this->builder = new MangopayPayloadBuilder($config);
        $this->transport = $transport ?? new SimulatedMangopayTransport();
    }

    public function name(): string
    {
        return $this->transport instanceof SimulatedMangopayTransport ? 'mangopay-sim' : 'mangopay';
    }

    public function split(PaymentSplitRequest $request): PaymentSplitResult
    {
        try {
            $plan = $this->builder->build($request);
        } catch (\InvalidArgumentException $e) {
            return new PaymentSplitResult($this->name(), PaymentSplitResult::STATUS_FAILED, null, [], [], $e->getMessage());
        }
        $responses = [];
        $transferRefs = [];
        try {
            $payIn = $this->transport->send($plan['payin']);
            $responses['payin'] = $payIn;
            foreach ($plan['transfers'] as $idVendor => $call) {
                $resp = $this->transport->send($call);
                $responses['transfers'][$idVendor] = $resp;
                $transferRefs[$idVendor] = (string) $resp['Id'];
            }
        } catch (\Throwable $e) {
            return new PaymentSplitResult($this->name(), PaymentSplitResult::STATUS_FAILED, $responses['payin']['Id'] ?? null, $transferRefs,
                ['plan' => $plan, 'responses' => $responses], 'Mangopay call failed: ' . $e->getMessage());
        }
        $simulated = $this->transport instanceof SimulatedMangopayTransport;

        return new PaymentSplitResult(
            $this->name(),
            $simulated ? PaymentSplitResult::STATUS_SIMULATED : PaymentSplitResult::STATUS_SUCCEEDED,
            (string) $payIn['Id'],
            $transferRefs,
            ['mode' => $this->config->mode, 'base_url' => $this->config->baseUrl(), 'plan' => $plan, 'responses' => $responses],
            sprintf('PayIn %s (%s) + %d transfer(s); platform fees %d cents; residual in escrow %d cents',
                $payIn['Id'], $payIn['Status'] ?? '?', count($transferRefs), $plan['summary']['platform_fees'], $plan['summary']['residual_in_escrow'])
        );
    }
}
