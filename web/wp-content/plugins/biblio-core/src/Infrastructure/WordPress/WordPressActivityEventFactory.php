<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress;

use Biblio\Core\Audit\ActivityActorSnapshot;
use Biblio\Core\Audit\ActivityChange;
use Biblio\Core\Audit\ActivityEntityIdentity;
use Biblio\Core\Audit\ActivityEntitySnapshot;
use Biblio\Core\Audit\ActivityEvent;
use Biblio\Core\Audit\ActivityEventFactory;
use Biblio\Core\Audit\ActivityEventId;
use Biblio\Core\Audit\ActivityEventKey;
use Biblio\Core\Audit\ActivityEventSource;
use Biblio\Core\Audit\ActivityLabel;
use Biblio\Core\Exception\AuthenticationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use WP_User;

final readonly class WordPressActivityEventFactory implements
    ActivityEventFactory
{
    public function __construct(private ActivityEventSource $source)
    {
    }

    /**
     * @param list<ActivityEntitySnapshot> $relatedEntities
     * @param list<ActivityChange> $changes
     */
    public function create(
        UserId $actorId,
        LibraryId $libraryId,
        ActivityEventKey $eventKey,
        ActivityEntityIdentity $primaryEntity,
        array $relatedEntities,
        array $changes
    ): ActivityEvent {
        $user = get_userdata((int) $actorId->value());

        if (!$user instanceof WP_User) {
            throw new AuthenticationException();
        }

        return new ActivityEvent(
            new ActivityEventId(wp_generate_uuid4()),
            $libraryId,
            current_datetime(),
            new ActivityActorSnapshot(
                $actorId,
                new ActivityLabel($user->display_name)
            ),
            $this->source,
            $eventKey,
            $primaryEntity,
            $relatedEntities,
            $changes
        );
    }
}
