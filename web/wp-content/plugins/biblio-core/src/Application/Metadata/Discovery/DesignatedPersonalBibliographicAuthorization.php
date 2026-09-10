<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Discovery;

use Biblio\Core\Application\Library\ActorLibraryContextRepository;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;

final readonly class DesignatedPersonalBibliographicAuthorization implements
    BibliographicMaterializationAuthorization
{
    public function __construct(private ActorLibraryContextRepository $contexts) {}

    public function assertAllowed(UserId $actorId): void
    {
        foreach ($this->contexts->listForActor($actorId) as $context) {
            $membership = $context->membership();
            if ($context->isDesignatedPersonal()
                && $membership->userId()->equals($actorId)
                && $membership->membership()->status() === MembershipStatus::Active
                && $membership->membership()->managementRole() === ManagementRole::Owner) {
                return;
            }
        }

        throw new AuthorizationException(
            "Bibliographic materialization requires ownership of the designated personal Library."
        );
    }
}
