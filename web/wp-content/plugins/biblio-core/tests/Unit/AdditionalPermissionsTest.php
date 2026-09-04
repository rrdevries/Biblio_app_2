<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit;

use Biblio\Core\Library\AdditionalPermissions;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AdditionalPermissionsTest extends TestCase
{
    public function testNoAdditionalPermissionsIsAnExplicitEmptySet(): void
    {
        self::assertSame([], AdditionalPermissions::none()->values());
    }

    public function testOpaquePermissionValuesAreRetained(): void
    {
        $permissions = AdditionalPermissions::fromValues(
            "collections",
            "lending"
        );

        self::assertSame(
            ["collections", "lending"],
            $permissions->values()
        );
    }

    public function testCatalogPermissionsCoexistWithUnknownFutureValues(): void
    {
        $permissions = AdditionalPermissions::fromValues(
            "future.catalog.permission",
            AdditionalPermissions::CATALOG_ITEM_ADD,
            AdditionalPermissions::CATALOG_CLASSIFICATION_MANAGE,
            AdditionalPermissions::COLLECTIONS_MANAGE
        );

        self::assertSame([
            "future.catalog.permission",
            "catalog.item_add",
            "catalog.classification_manage",
            "collections",
        ], $permissions->values());
        self::assertTrue($permissions->contains(
            AdditionalPermissions::CATALOG_ITEM_ADD
        ));
        self::assertTrue($permissions->contains(
            AdditionalPermissions::CATALOG_CLASSIFICATION_MANAGE
        ));
        self::assertTrue($permissions->contains(
            AdditionalPermissions::COLLECTIONS_MANAGE
        ));
    }

    public function testEmptyPermissionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AdditionalPermissions::fromValues("");
    }

    public function testDuplicatePermissionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AdditionalPermissions::fromValues("lending", "lending");
    }

    public function testWhitespaceAndOrderArePreservedWithoutNormalization(): void
    {
        $permissions = AdditionalPermissions::fromValues(
            " lending ",
            "collections"
        );

        self::assertSame(
            [" lending ", "collections"],
            $permissions->values()
        );
    }

    public function testInvalidUtf8PermissionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AdditionalPermissions::fromValues("invalid-\xFF");
    }
}
