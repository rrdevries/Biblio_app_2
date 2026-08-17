<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Application\Library\EnsurePersonalPrivateLibraryService;
use Biblio\Core\Application\Library\GetAccessibleLibraryItemService;
use Biblio\Core\Application\Reading\GetOwnedReadingRoundService;
use Biblio\Core\Application\Reading\StartReadingFromExternalLoanService;
use Biblio\Core\Application\Reading\StartReadingFromLibraryItemService;
use Biblio\Core\Library\LibraryContext;
use Biblio\Core\Identity\UserId;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

final class ProductionApplicationBoundaryTest extends TestCase
{
    public function testProductionOperationSignaturesDoNotAcceptActorOrContext(): void
    {
        foreach ([
            [EnsurePersonalPrivateLibraryService::class, "ensure"],
            [GetAccessibleLibraryItemService::class, "get"],
            [GetOwnedExternalLoanService::class, "get"],
            [GetOwnedReadingRoundService::class, "get"],
            [StartReadingFromLibraryItemService::class, "start"],
            [StartReadingFromExternalLoanService::class, "start"],
        ] as [$class, $method]) {
            foreach ((new ReflectionMethod($class, $method))->getParameters()
                as $parameter) {
                $type = $parameter->getType();

                self::assertFalse(
                    $type instanceof ReflectionNamedType
                    && in_array($type->getName(), [
                        UserId::class,
                        LibraryContext::class,
                    ], true),
                    "{$class}::{$method} accepts caller-controlled actor state."
                );
            }
        }
    }

    public function testCoreApplicationExposesOnlyNamedApplicationServices(): void
    {
        $publicMethods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(CoreApplication::class))
                ->getMethods(ReflectionMethod::IS_PUBLIC)
        );

        sort($publicMethods);

        self::assertSame([
            "__construct",
            "accessibleLibraryItems",
            "externalLoanReading",
            "libraryItemReading",
            "ownedExternalLoans",
            "ownedReadingRounds",
            "personalLibraries",
        ], $publicMethods);
        self::assertNotContains("get", $publicMethods);
        self::assertNotContains("resolve", $publicMethods);
    }
}
