<?php

declare(strict_types=1);

namespace BrainX\MarketplaceVendor\Tests;

use BrainX\MarketplaceVendor\Domain\RolePolicy as P;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RolePolicyTest extends TestCase
{
    public static function matrix(): array
    {
        return [
            // permission, owner, manager, staff
            [P::DASHBOARD_VIEW, true, true, true],
            [P::ANALYTICS_VIEW, true, true, false],
            [P::OFFERS_VIEW, true, true, true],
            [P::OFFERS_EDIT, true, true, false],
            [P::POSITIONING_VIEW, true, true, false],
            [P::ORDERS_VIEW, true, true, true],
            [P::ORDERS_MANAGE, true, true, true],
            [P::PROFILE_VIEW, true, false, false],
            [P::PROFILE_EDIT, true, false, false],
            [P::SHIPPING_EDIT, true, false, false],
            [P::USERS_MANAGE, true, false, false],
            [P::CSV_IMPORT, true, true, false],
            [P::CSV_EXPORT_ORDERS, true, true, true],
        ];
    }

    #[DataProvider('matrix')]
    public function testMatrix(string $perm, bool $owner, bool $manager, bool $staff): void
    {
        self::assertSame($owner, P::can('owner', $perm));
        self::assertSame($manager, P::can('manager', $perm));
        self::assertSame($staff, P::can('staff', $perm));
    }

    public function testMatrixCoversEveryPermission(): void
    {
        self::assertEqualsCanonicalizing(array_column(self::matrix(), 0), P::permissions());
    }

    public function testUnknownRoleOrPermissionDenied(): void
    {
        self::assertFalse(P::can('admin', P::OFFERS_VIEW));
        self::assertFalse(P::can('', P::ORDERS_VIEW));
        self::assertFalse(P::can('owner', 'nuke.everything'));
        self::assertFalse(P::isRole('superowner'));
    }

    public function testOwnerIsSuperset(): void
    {
        self::assertSame([], array_diff(P::permissionsFor('manager'), P::permissionsFor('owner')));
        self::assertSame([], array_diff(P::permissionsFor('staff'), P::permissionsFor('manager')));
    }

    public function testFlagsAndAssignRole(): void
    {
        $flags = P::flagsFor('staff');
        self::assertTrue($flags['orders_manage']);
        self::assertFalse($flags['offers_edit']);
        self::assertTrue(P::canAssignRole('owner', 'manager'));
        self::assertFalse(P::canAssignRole('manager', 'staff'));
        self::assertFalse(P::canAssignRole('owner', 'root'));
    }
}
