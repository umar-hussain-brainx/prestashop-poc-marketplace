<?php

declare(strict_types=1);

namespace BrainX\Csv\Validation;

/** Validation of one order status update record. */
final class OrderStatusRowValidator
{
    public const STATUSES = ['pending', 'accepted', 'shipped', 'delivered', 'cancelled'];

    public const STATUS_ALIASES = [
        'new' => 'pending', 'open' => 'pending', 'in_attesa' => 'pending',
        'confirmed' => 'accepted', 'processing' => 'accepted', 'accettato' => 'accepted', 'in_lavorazione' => 'accepted',
        'sent' => 'shipped', 'dispatched' => 'shipped', 'spedito' => 'shipped',
        'completed' => 'delivered', 'consegnato' => 'delivered',
        'canceled' => 'cancelled', 'annullato' => 'cancelled', 'refused' => 'cancelled',
    ];

    /**
     * Allowed transitions (same status is always allowed, e.g. to add a tracking number).
     */
    public const TRANSITIONS = [
        'pending' => ['accepted', 'shipped', 'cancelled'],
        'accepted' => ['shipped', 'cancelled'],
        'shipped' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
    ];

    /** @param array<string,string> $raw */
    public function validate(array $raw, int $line = 0): RowResult
    {
        $e = [];
        $w = [];
        $ref = trim((string) ($raw['vendor_order_reference'] ?? ''));
        if ($ref === '') {
            $e[] = 'vendor_order_reference: required';
        } elseif (mb_strlen($ref) > 64) {
            $e[] = 'vendor_order_reference: too long';
        }
        $status = self::normalizeStatus((string) ($raw['status'] ?? ''));
        if ($status === false) {
            $e[] = 'status: "' . ($raw['status'] ?? '') . '" must be one of ' . implode(', ', self::STATUSES);
            $status = null;
        }
        $tracking = trim((string) ($raw['tracking_number'] ?? ''));
        $carrier = trim((string) ($raw['carrier_name'] ?? ''));
        if (mb_strlen($tracking) > 128 || preg_match('/[<>{}]/', $tracking)) {
            $e[] = 'tracking_number: invalid (max 128 chars, no < > { })';
        }
        if (mb_strlen($carrier) > 128 || preg_match('/[<>{}]/', $carrier)) {
            $e[] = 'carrier_name: invalid (max 128 chars, no < > { })';
        }
        if ($status === null && $tracking === '' && $carrier === '') {
            $e[] = 'row: nothing to update (status, tracking_number or carrier_name)';
        }
        if ($status === 'shipped' && $tracking === '') {
            $w[] = 'tracking_number: missing for a shipped order';
        }

        return new RowResult($line, [
            'vendor_order_reference' => $ref,
            'status' => $status,
            'tracking_number' => $tracking === '' ? null : $tracking,
            'carrier_name' => $carrier === '' ? null : $carrier,
        ], $raw, $e, $w);
    }

    /** @return string|null|false canonical status, null when empty, false when unknown */
    public static function normalizeStatus(string $raw): string|null|false
    {
        $s = strtolower(trim(str_replace([' ', '-'], '_', $raw)));
        if ($s === '') {
            return null;
        }
        if (in_array($s, self::STATUSES, true)) {
            return $s;
        }

        return self::STATUS_ALIASES[$s] ?? false;
    }

    public static function canTransition(string $from, string $to): bool
    {
        return $from === $to || in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}
