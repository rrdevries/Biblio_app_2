<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Classification;

use Biblio\Core\Audit\ActivityChange;
use Biblio\Core\Audit\ActivityEntityIdentity;
use Biblio\Core\Audit\ActivityEntitySnapshot;
use Biblio\Core\Audit\ActivityEvent;
use Biblio\Core\Audit\ActivityEventFactory;
use Biblio\Core\Audit\ActivityEventKey;
use Biblio\Core\Audit\ActivityLabel;
use Biblio\Core\Audit\ActivityPayload;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;

final readonly class LibraryCatalogContextActivity
{
    public function __construct(private ActivityEventFactory $factory)
    {
    }

    public function created(
        UserId $actorId,
        LibraryId $libraryId,
        Work $work,
        LibraryCatalogSelectionSnapshot $selection
    ): ActivityEvent {
        return $this->event(
            $actorId,
            $libraryId,
            $work,
            "library_catalog_context.created",
            null,
            $selection
        );
    }

    public function updated(
        UserId $actorId,
        LibraryId $libraryId,
        Work $work,
        LibraryCatalogSelectionSnapshot $oldSelection,
        LibraryCatalogSelectionSnapshot $newSelection
    ): ActivityEvent {
        return $this->event(
            $actorId,
            $libraryId,
            $work,
            "library_catalog_context.updated",
            $oldSelection,
            $newSelection
        );
    }

    private function event(
        UserId $actorId,
        LibraryId $libraryId,
        Work $work,
        string $key,
        ?LibraryCatalogSelectionSnapshot $oldSelection,
        LibraryCatalogSelectionSnapshot $newSelection
    ): ActivityEvent {
        $emptyTerm = new ActivityPayload([]);
        $emptySet = new ActivityPayload(["terms" => []]);

        return $this->factory->create(
            $actorId,
            $libraryId,
            new ActivityEventKey($key),
            new ActivityEntityIdentity(
                "LibraryCatalogContext",
                $work->id()->value()
            ),
            [
                new ActivityEntitySnapshot(
                    new ActivityEntityIdentity("Work", $work->id()->value()),
                    new ActivityLabel($work->title()),
                    new ActivityPayload(["work_id" => $work->id()->value()])
                ),
            ],
            [
                new ActivityChange(
                    "book_type",
                    $oldSelection === null
                        ? $emptyTerm
                        : new ActivityPayload(
                            $oldSelection->bookTypePayload()
                        ),
                    new ActivityPayload($newSelection->bookTypePayload())
                ),
                new ActivityChange(
                    "genres",
                    $oldSelection === null
                        ? $emptySet
                        : new ActivityPayload($oldSelection->genresPayload()),
                    new ActivityPayload($newSelection->genresPayload())
                ),
                new ActivityChange(
                    "subjects",
                    $oldSelection === null
                        ? $emptySet
                        : new ActivityPayload(
                            $oldSelection->subjectsPayload()
                        ),
                    new ActivityPayload($newSelection->subjectsPayload())
                ),
            ]
        );
    }
}
