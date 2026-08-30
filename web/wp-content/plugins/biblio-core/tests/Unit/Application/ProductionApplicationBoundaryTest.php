<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Application\Catalog\AddLibraryItemService;
use Biblio\Core\Application\Catalog\Classification\CreateLibraryCatalogContextService;
use Biblio\Core\Application\Catalog\Classification\ManageLibraryBookTypesService;
use Biblio\Core\Application\Catalog\Classification\ManageLibraryGenresService;
use Biblio\Core\Application\Catalog\Classification\ManageLibrarySubjectsService;
use Biblio\Core\Application\Catalog\Classification\SaveLibraryCatalogContextService;
use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Application\Library\EnsurePersonalPrivateLibraryService;
use Biblio\Core\Application\Library\GetAccessibleLibraryItemService;
use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Application\Notes\CorrectPrivateNoteReadingRoundService;
use Biblio\Core\Application\Notes\CreatePrivateNoteService;
use Biblio\Core\Application\Notes\DeletePrivateNoteService;
use Biblio\Core\Application\Notes\GetPrivateNoteService;
use Biblio\Core\Application\Notes\ListMyPrivateNotesService;
use Biblio\Core\Application\Notes\ListPrivateNotesForReadingRoundService;
use Biblio\Core\Application\Notes\ListPrivateNotesForWorkService;
use Biblio\Core\Application\Notes\UpdatePrivateNoteContentService;
use Biblio\Core\Application\Reading\GetOwnedReadingRoundService;
use Biblio\Core\Application\Reading\CreateActiveReadingRoundService;
use Biblio\Core\Application\Reading\StartReadingFromExternalLoanService;
use Biblio\Core\Application\Reading\StartReadingFromLibraryItemService;
use Biblio\Core\Application\Reading\History\GetMyReadingHistoryForWorkService;
use Biblio\Core\Library\LibraryContext;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Reading\ReadingSource;
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
            [LibraryContextQueryService::class, "myLibraries"],
            [LibraryContextQueryService::class, "get"],
            [AddLibraryItemService::class, "addForExistingEdition"],
            [
                AddLibraryItemService::class,
                "addWithNewEditionForExistingWork",
            ],
            [AddLibraryItemService::class, "addWithNewWorkAndEdition"],
            [GetAccessibleLibraryItemService::class, "get"],
            [GetOwnedExternalLoanService::class, "get"],
            [GetOwnedReadingRoundService::class, "get"],
            [StartReadingFromLibraryItemService::class, "start"],
            [StartReadingFromExternalLoanService::class, "start"],
            [GetMyReadingHistoryForWorkService::class, "forWork"],
            [CreatePrivateNoteService::class, "createForWork"],
            [CreatePrivateNoteService::class, "createForReadingRound"],
            [UpdatePrivateNoteContentService::class, "update"],
            [CorrectPrivateNoteReadingRoundService::class, "correct"],
            [DeletePrivateNoteService::class, "delete"],
            [GetPrivateNoteService::class, "get"],
            [ListPrivateNotesForWorkService::class, "list"],
            [ListPrivateNotesForReadingRoundService::class, "list"],
            [ListMyPrivateNotesService::class, "list"],
            [
                CreateLibraryCatalogContextService::class,
                "createForRepresentedWork",
            ],
            [SaveLibraryCatalogContextService::class, "save"],
            [ManageLibraryBookTypesService::class, "create"],
            [ManageLibraryBookTypesService::class, "rename"],
            [ManageLibraryBookTypesService::class, "deactivate"],
            [ManageLibraryBookTypesService::class, "reactivate"],
            [ManageLibraryGenresService::class, "create"],
            [ManageLibraryGenresService::class, "rename"],
            [ManageLibraryGenresService::class, "deactivate"],
            [ManageLibraryGenresService::class, "reactivate"],
            [ManageLibrarySubjectsService::class, "create"],
            [ManageLibrarySubjectsService::class, "rename"],
            [ManageLibrarySubjectsService::class, "deactivate"],
            [ManageLibrarySubjectsService::class, "reactivate"],
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
            "assessmentQueries",
            "bookTypeManagement",
            "catalogContextCreation",
            "catalogContextManagement",
            "catalogUiReads",
            "endedReadingRoundCorrection",
            "externalLoanReading",
            "finishReadingRound",
            "genreManagement",
            "historicalReadingRoundDeletion",
            "historicalReadingRounds",
            "libraryContexts",
            "libraryItemCreation",
            "libraryItemReading",
            "myNextReadingList",
            "myPrivateNotes",
            "nextReadingExternalLoanAdd",
            "nextReadingHome",
            "nextReadingItemAdd",
            "nextReadingRemove",
            "nextReadingReorder",
            "nextReadingWorkAdd",
            "ownedExternalLoans",
            "ownedReadingRounds",
            "personalLibraries",
            "personalWorkReadingStatus",
            "privateNoteContentUpdate",
            "privateNoteContextCorrection",
            "privateNoteCreation",
            "privateNoteDeletion",
            "privateNoteRendering",
            "privateNotes",
            "privateNotesForReadingRound",
            "privateNotesForWork",
            "publicationModeration",
            "publicationMove",
            "publicationRestoration",
            "publicationWithdrawal",
            "ratingContextCorrection",
            "ratingDeletion",
            "ratingForRoundCreation",
            "ratingForWorkCreation",
            "ratingPublication",
            "ratingValueUpdate",
            "readingHistory",
            "readingRoundSourceCorrection",
            "readingSequence",
            "reviewContentUpdate",
            "reviewContextCorrection",
            "reviewDeletion",
            "reviewForRoundCreation",
            "reviewForWorkCreation",
            "reviewPublication",
            "stopReadingRound",
            "subjectManagement",
        ], $publicMethods);
        self::assertNotContains("get", $publicMethods);
        self::assertNotContains("resolve", $publicMethods);
    }

    public function testCoreApplicationDoesNotExposeRepositoryTypes(): void
    {
        foreach ((new ReflectionClass(CoreApplication::class))->getMethods(
            ReflectionMethod::IS_PUBLIC
        ) as $method) {
            if ($method->getName() === "__construct") {
                continue;
            }

            $returnType = $method->getReturnType();
            self::assertInstanceOf(ReflectionNamedType::class, $returnType);
            self::assertStringStartsWith(
                "Biblio\\Core\\Application\\",
                $returnType->getName()
            );
            self::assertStringNotContainsString(
                "Repository",
                $returnType->getName()
            );
        }
    }

    public function testReadingCreationHasOnlySourceSpecificPublicMethods(): void
    {
        $methods = array_filter(
            (new ReflectionClass(CreateActiveReadingRoundService::class))
                ->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool =>
                $method->getName() !== "__construct"
        );
        $methodNames = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $methods
        );
        sort($methodNames);

        self::assertSame([
            "createFromExternalLoan",
            "createFromLibraryItem",
        ], $methodNames);

        foreach ($methods as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                self::assertFalse(
                    $type instanceof ReflectionNamedType
                    && in_array($type->getName(), [
                        WorkId::class,
                        ReadingSource::class,
                    ], true),
                    $method->getName()
                        . " accepts an independent Work or Reading source."
                );
            }
        }
    }
}
