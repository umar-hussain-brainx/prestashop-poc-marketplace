<?php

declare(strict_types=1);

namespace BrainX\MarketplaceVendor\Domain;

/**
 * Vendor-portal permission matrix (enforced server-side by every portal controller / action).
 *
 *  owner   : everything, incl. vendor users, commercial data (company, VAT, payout) and shipping rules
 *  manager : offers, inventory, price positioning, orders, shipments, analytics, CSV
 *  staff   : orders & shipments only; offers read-only; no financial analytics
 */
final class RolePolicy
{
    public const OWNER = 'owner';
    public const MANAGER = 'manager';
    public const STAFF = 'staff';
    public const ROLES = [self::OWNER, self::MANAGER, self::STAFF];

    public const DASHBOARD_VIEW = 'dashboard.view';
    public const ANALYTICS_VIEW = 'analytics.view';
    public const OFFERS_VIEW = 'offers.view';
    public const OFFERS_EDIT = 'offers.edit';
    public const POSITIONING_VIEW = 'positioning.view';
    public const ORDERS_VIEW = 'orders.view';
    public const ORDERS_MANAGE = 'orders.manage';
    public const PROFILE_VIEW = 'profile.view';
    public const PROFILE_EDIT = 'profile.edit';
    public const SHIPPING_EDIT = 'shipping.edit';
    public const USERS_MANAGE = 'users.manage';
    public const CSV_IMPORT = 'csv.import';
    public const CSV_EXPORT_ORDERS = 'csv.export_orders';

    /** permission => roles allowed */
    private const MATRIX = [
        self::DASHBOARD_VIEW => [self::OWNER, self::MANAGER, self::STAFF],
        self::ANALYTICS_VIEW => [self::OWNER, self::MANAGER],
        self::OFFERS_VIEW => [self::OWNER, self::MANAGER, self::STAFF],
        self::OFFERS_EDIT => [self::OWNER, self::MANAGER],
        self::POSITIONING_VIEW => [self::OWNER, self::MANAGER],
        self::ORDERS_VIEW => [self::OWNER, self::MANAGER, self::STAFF],
        self::ORDERS_MANAGE => [self::OWNER, self::MANAGER, self::STAFF],
        self::PROFILE_VIEW => [self::OWNER],
        self::PROFILE_EDIT => [self::OWNER],
        self::SHIPPING_EDIT => [self::OWNER],
        self::USERS_MANAGE => [self::OWNER],
        self::CSV_IMPORT => [self::OWNER, self::MANAGER],
        self::CSV_EXPORT_ORDERS => [self::OWNER, self::MANAGER, self::STAFF],
    ];

    public static function isRole(string $role): bool
    {
        return in_array($role, self::ROLES, true);
    }

    /** Unknown roles and unknown permissions are always denied. */
    public static function can(string $role, string $permission): bool
    {
        return isset(self::MATRIX[$permission]) && in_array($role, self::MATRIX[$permission], true);
    }

    /** @return string[] */
    public static function permissionsFor(string $role): array
    {
        return array_keys(array_filter(self::MATRIX, static fn (array $roles) => in_array($role, $roles, true)));
    }

    /** @return array<string, bool> permission => allowed (handy for templates) */
    public static function flagsFor(string $role): array
    {
        $out = [];
        foreach (array_keys(self::MATRIX) as $p) {
            $out[str_replace('.', '_', $p)] = self::can($role, $p);
        }

        return $out;
    }

    /** @return string[] */
    public static function permissions(): array
    {
        return array_keys(self::MATRIX);
    }

    /**
     * Can $actorRole assign $targetRole / manage a user that currently has $targetRole?
     * Only owners manage users; they may grant any role.
     */
    public static function canAssignRole(string $actorRole, string $targetRole): bool
    {
        return self::can($actorRole, self::USERS_MANAGE) && self::isRole($targetRole);
    }
}
