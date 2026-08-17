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
