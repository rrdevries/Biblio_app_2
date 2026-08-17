<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Library;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\PersonalLibraryDesignationConflict;
use Biblio\Core\Library\PersonalLibraryRepository;

final readonly class EnsurePersonalPrivateLibraryService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private PersonalLibraryRepository $personalLibraryRepository,
        private CreateLibraryService $createLibraryService
    ) {
    }

    public function ensure(): LibraryId
    {
        $userId = $this->authenticatedUser->requireUserId();
        $designatedLibraryId = $this->personalLibraryRepository
            ->findForUser($userId);

        if ($designatedLibraryId !== null) {
            return $designatedLibraryId;
        }

        $library = Library::privateLibrary($this->newLibraryId());

        try {
            return $this->createLibraryService->createAndThen(
                $library,
                $userId,
                function () use ($userId, $library): LibraryId {
                    $this->personalLibraryRepository->designate(
                        $userId,
                        $library->id()
                    );

                    return $library->id();
                }
            );
        } catch (PersonalLibraryDesignationConflict $exception) {
            $designatedLibraryId = $this->personalLibraryRepository
                ->findForUser($userId);

            if ($designatedLibraryId !== null) {
                return $designatedLibraryId;
            }

            throw $exception;
        }
    }

    private function newLibraryId(): LibraryId
    {
        return new LibraryId(
            "personal-" . bin2hex(random_bytes(16))
        );
    }
}
