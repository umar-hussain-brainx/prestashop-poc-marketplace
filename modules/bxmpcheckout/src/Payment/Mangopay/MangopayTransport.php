<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Payment\Mangopay;

/** Executes one Mangopay API call and returns the decoded response (must contain "Id" and "Status"). */
interface MangopayTransport
{
    /** @param array{method:string, path:string, idempotency_key:string, body:array<string,mixed>} $call */
    public function send(array $call): array;
}
