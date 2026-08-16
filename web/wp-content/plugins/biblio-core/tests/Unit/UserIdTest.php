<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit;

use Biblio\Core\Identity\UserId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UserIdTest extends TestCase
{
    public function testValidUserIdRetainsItsValue(): void
    {
        $userId = new UserId("user-1");

        self::assertSame("user-1", $userId->value());
    }

    public function testEmptyUserIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new UserId("");
    }

    public function testWhitespaceOnlyUserIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new UserId(" \t\n");
    }
}
