<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit;

use Biblio\Core\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    public function testCoreVersionIsDefined(): void
    {
        self::assertSame("2.1.0", Plugin::VERSION);
    }
}
