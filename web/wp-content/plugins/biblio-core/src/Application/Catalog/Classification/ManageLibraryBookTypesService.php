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
use Biblio\Core\Catalog\Classification\LibraryBookType;
use Biblio\Core\Catalog\Classification\LibraryBookTypeId;
use Biblio\Core\Catalog\Classification\WritableLibraryBookTypeRepository;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryContext;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMutationLock;

final readonly class ManageLibraryBookTypesService
{
    private const ENTITY_TYPE = "LibraryBookType";
    private const EVENT_PREFIX = "library_book_type.";

    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private LibraryAccessService $access,
        private WritableLibraryBookTypeRepository $terms,
        private ClassificationNameNormalizer $normalizer,
        private LibraryMutationLock $libraryLock,
        private ClassificationTermActivity $activity,
        private ActivityEventAppender $activityEvents,
        private TransactionManager $transactions
    ) {
    }

    public function create(
        LibraryId $libraryId,
        LibraryBookTypeId $id,
        ClassificationTermName $name
    ): LibraryBookType {
        $actorId = $this->authorize($libraryId);
        $normalized = $this->normalizer->normalize($name);

        return $this->transactions->run(function () use (
            $actorId,
            $libraryId,
            $id,
            $name,
            $normalized
        ): LibraryBookType {
            $this->libraryLock->acquire($libraryId);
            $term = new LibraryBookType(
                $libraryId,
                $id,
                $name,
                $normalized,
                ClassificationTermStatus::Active
            );
            $this->terms->add($term);
            $this->appendCreated($actorId, $term);

            return $term;
        });
    }

    public function rename(
        LibraryId $libraryId,
        LibraryBookTypeId $id,
        ClassificationTermName $name
    ): LibraryBookType {
        $actorId = $this->authorize($libraryId);
        $normalized = $this->normalizer->normalize($name);

        return $this->transactions->run(function () use (
            $actorId,
            $libraryId,
            $id,
            $name,
            $normalized
        ): LibraryBookType {
            $this->libraryLock->acquire($libraryId);
            $current = $this->requireTerm($libraryId, $id);

            if ($current->normalizedName()->equals($normalized)) {
                return $current;
            }

            $this->terms->rename($libraryId, $id, $name, $normalized);
            $renamed = new LibraryBookType(
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
        LibraryBookTypeId $id,
        bool $confirmLastActive
    ): LibraryBookType {
        $actorId = $this->authorize($libraryId);

        return $this->transactions->run(function () use (
            $actorId,
            $libraryId,
            $id,
            $confirmLastActive
        ): LibraryBookType {
            $this->libraryLock->acquire($libraryId);
            $current = $this->requireTerm($libraryId, $id);

            if ($current->status() === ClassificationTermStatus::Inactive) {
                return $current;
            }

            if ($this->terms->countActive($libraryId) === 1
                && !$confirmLastActive) {
                throw new ValidationException(
                    "Deactivating the last active Library Book Type requires "
                    . "explicit confirmation."
                );
            }

            return $this->changeStatus(
                $actorId,
                $current,
                ClassificationTermStatus::Inactive,
                "deactivated"
            );
        });
    }

    public function reactivate(
        LibraryId $libraryId,
        LibraryBookTypeId $id
    ): LibraryBookType {
        $actorId = $this->authorize($libraryId);

        return $this->transactions->run(function () use (
            $actorId,
            $libraryId,
            $id
        ): LibraryBookType {
            $this->libraryLock->acquire($libraryId);
            $current = $this->requireTerm($libraryId, $id);

            if ($current->status() === ClassificationTermStatus::Active) {
                return $current;
            }

            return $this->changeStatus(
                $actorId,
                $current,
                ClassificationTermStatus::Active,
                "reactivated"
            );
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
        LibraryBookTypeId $id
    ): LibraryBookType {
        return $this->terms->findForUpdate($libraryId, $id)
            ?? throw new ValidationException(
                "Library Book Type does not exist in this Library."
            );
    }

    private function appendCreated(UserId $actorId, LibraryBookType $term): void
    {
        $this->activityEvents->append($this->activity->created(
            $actorId,
            $term->libraryId(),
            self::ENTITY_TYPE,
            $term->id()->value(),
            self::EVENT_PREFIX . "created",
            $term->name()->value()
        ));
    }

    private function changeStatus(
        UserId $actorId,
        LibraryBookType $current,
        ClassificationTermStatus $status,
        string $event
    ): LibraryBookType {
        $this->terms->changeStatus(
            $current->libraryId(),
            $current->id(),
            $status
        );
        $changed = new LibraryBookType(
            $current->libraryId(),
            $current->id(),
            $current->name(),
            $current->normalizedName(),
            $status,
            $current->seedKey()
        );
        $this->activityEvents->append($this->activity->statusChanged(
            $actorId,
            $current->libraryId(),
            self::ENTITY_TYPE,
            $current->id()->value(),
            self::EVENT_PREFIX . $event,
            $current->name()->value(),
            $current->status()->value,
            $status->value
        ));

        return $changed;
    }
}
