<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Classification;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Audit\ActivityEventAppender;
use Biblio\Core\Catalog\Classification\ClassificationNameNormalizer;
use Biblio\Core\Catalog\Classification\ClassificationTermName;
use Biblio\Core\Catalog\Classification\ClassificationTermStatus;
use Biblio\Core\Catalog\Classification\LibraryGenre;
use Biblio\Core\Catalog\Classification\LibraryGenreId;
use Biblio\Core\Catalog\Classification\WritableLibraryGenreRepository;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryContext;
use Biblio\Core\Library\LibraryId;

final readonly class ManageLibraryGenresService
{
    private const ENTITY_TYPE = "LibraryGenre";
    private const EVENT_PREFIX = "library_genre.";

    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private LibraryAccessService $access,
        private WritableLibraryGenreRepository $terms,
        private ClassificationNameNormalizer $normalizer,
        private ClassificationTermActivity $activity,
        private ActivityEventAppender $activityEvents,
        private TransactionManager $transactions
    ) {
    }

    public function create(
        LibraryId $libraryId,
        LibraryGenreId $id,
        ClassificationTermName $name
    ): LibraryGenre {
        $actorId = $this->authorize($libraryId);
        $normalized = $this->normalizer->normalize($name);

        return $this->transactions->run(function () use (
            $actorId,
            $libraryId,
            $id,
            $name,
            $normalized
        ): LibraryGenre {
            $term = new LibraryGenre(
                $libraryId,
                $id,
                $name,
                $normalized,
                ClassificationTermStatus::Active
            );
            $this->terms->add($term);
            $this->activityEvents->append($this->activity->created(
                $actorId,
                $libraryId,
                self::ENTITY_TYPE,
                $id->value(),
                self::EVENT_PREFIX . "created",
                $name->value()
            ));

            return $term;
        });
    }

    public function rename(
        LibraryId $libraryId,
        LibraryGenreId $id,
        ClassificationTermName $name
    ): LibraryGenre {
        $actorId = $this->authorize($libraryId);
        $normalized = $this->normalizer->normalize($name);

        return $this->transactions->run(function () use (
            $actorId,
            $libraryId,
            $id,
            $name,
            $normalized
        ): LibraryGenre {
            $current = $this->requireTerm($libraryId, $id);

            if ($current->normalizedName()->equals($normalized)) {
                return $current;
            }

            $this->terms->rename($libraryId, $id, $name, $normalized);
            $renamed = new LibraryGenre(
                $libraryId,
                $id,
                $name,
                $normalized,
                $current->status(),
                $current->seedKey()
            );
            $this->activityEvents->append($this->activity->renamed(
                $actorId,
                $libraryId,
                self::ENTITY_TYPE,
                $id->value(),
                self::EVENT_PREFIX . "renamed",
                $current->name()->value(),
                $name->value()
            ));

            return $renamed;
        });
    }

    public function deactivate(
        LibraryId $libraryId,
        LibraryGenreId $id
    ): LibraryGenre {
        return $this->changeStatus(
            $libraryId,
            $id,
            ClassificationTermStatus::Inactive,
            "deactivated"
        );
    }

    public function reactivate(
        LibraryId $libraryId,
        LibraryGenreId $id
    ): LibraryGenre {
        return $this->changeStatus(
            $libraryId,
            $id,
            ClassificationTermStatus::Active,
            "reactivated"
        );
    }

    private function changeStatus(
        LibraryId $libraryId,
        LibraryGenreId $id,
        ClassificationTermStatus $status,
        string $event
    ): LibraryGenre {
        $actorId = $this->authorize($libraryId);

        return $this->transactions->run(function () use (
            $actorId,
            $libraryId,
            $id,
            $status,
            $event
        ): LibraryGenre {
            $current = $this->requireTerm($libraryId, $id);

            if ($current->status() === $status) {
                return $current;
            }

            $this->terms->changeStatus($libraryId, $id, $status);
            $changed = new LibraryGenre(
                $libraryId,
                $id,
                $current->name(),
                $current->normalizedName(),
                $status,
                $current->seedKey()
            );
            $this->activityEvents->append($this->activity->statusChanged(
                $actorId,
                $libraryId,
                self::ENTITY_TYPE,
                $id->value(),
                self::EVENT_PREFIX . $event,
                $current->name()->value(),
                $current->status()->value,
                $status->value
            ));

            return $changed;
        });
    }

    private function authorize(LibraryId $libraryId): UserId
    {
        $actorId = $this->authenticatedUser->requireUserId();

        if (!$this->access->canManageClassificationTerms(
            new LibraryContext($libraryId, $actorId)
        )) {
            throw new AuthorizationException(
                "Classification term management is not permitted for this Library."
            );
        }

        return $actorId;
    }

    private function requireTerm(
        LibraryId $libraryId,
        LibraryGenreId $id
    ): LibraryGenre {
        return $this->terms->findForUpdate($libraryId, $id)
            ?? throw new ValidationException(
                "Library Genre does not exist in this Library."
            );
    }
}
