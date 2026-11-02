<?php

declare(strict_types=1);

namespace BrainX\MarketplaceVendor\Domain;

/**
 * Vendor sub-order (bxmp_vendor_order) status workflow as seen by the vendor:
 *   pending → accepted → shipped (tracking + carrier) → delivered
 *   pending|accepted → cancelled
 */
final class OrderWorkflow
{
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const SHIPPED = 'shipped';
    public const DELIVERED = 'delivered';
    public const CANCELLED = 'cancelled';

    /** action => [from statuses, to status] */
    public const ACTIONS = [
        'accept' => [[self::PENDING], self::ACCEPTED],
        'ship' => [[self::ACCEPTED], self::SHIPPED],
        'deliver' => [[self::SHIPPED], self::DELIVERED],
        'cancel' => [[self::PENDING, self::ACCEPTED], self::CANCELLED],
    ];

    /** @return string[] actions available for the current status */
    public static function availableActions(string $status): array
    {
        return array_keys(array_filter(self::ACTIONS, static fn (array $a) => in_array($status, $a[0], true)));
    }

    public static function canApply(string $action, string $status): bool
    {
        return isset(self::ACTIONS[$action]) && in_array($status, self::ACTIONS[$action][0], true);
    }

    public static function target(string $action): ?string
    {
        return self::ACTIONS[$action][1] ?? null;
    }

    /**
     * Validates and normalises an action request.
     *
     * @return array{ok:bool, error:string|null, to:string|null, tracking_number:string|null, carrier_name:string|null}
     */
    public static function validate(string $action, string $status, array $input = []): array
    {
        $fail = static fn (string $e) => ['ok' => false, 'error' => $e, 'to' => null, 'tracking_number' => null, 'carrier_name' => null];
        if (!isset(self::ACTIONS[$action])) {
            return $fail('unknown_action');
        }
        if (!self::canApply($action, $status)) {
            return $fail('invalid_transition');
        }
        $tracking = null;
        $carrier = null;
        if ($action === 'ship') {
            $tracking = trim((string) ($input['tracking_number'] ?? ''));
            $carrier = trim((string) ($input['carrier_name'] ?? ''));
            if ($tracking === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._\/-]{2,63}$/', $tracking)) {
                return $fail('invalid_tracking_number');
            }
            if ($carrier === '' || mb_strlen($carrier) > 128 || preg_match('/[<>={}]/', $carrier)) {
                return $fail('invalid_carrier');
            }
        }

        return ['ok' => true, 'error' => null, 'to' => self::ACTIONS[$action][1], 'tracking_number' => $tracking, 'carrier_name' => $carrier];
    }
}
