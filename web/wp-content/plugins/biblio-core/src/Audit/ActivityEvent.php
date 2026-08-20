<?php

declare(strict_types=1);

namespace Biblio\Core\Audit;

use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Temporal\PersistedDateTimeConstraints;
use DateTimeImmutable;

final readonly class ActivityEvent
{
    /** @var list<ActivityEntitySnapshot> */
    private array $relatedEntities;

    /** @var list<ActivityChange> */
    private array $changes;

    /**
     * @param list<ActivityEntitySnapshot> $relatedEntities
     * @param list<ActivityChange> $changes
     */
    public function __construct(
        private ActivityEventId $eventId,
        private LibraryId $libraryId,
        private DateTimeImmutable $occurredAt,
        private ?ActivityActorSnapshot $actor,
        private ActivityEventSource $source,
        private ActivityEventKey $eventKey,
        private ActivityEntityIdentity $primaryEntity,
        array $relatedEntities,
        array $changes
    ) {
        PersistedDateTimeConstraints::assertSupported(
            $this->occurredAt,
            "Activity Event timestamp"
        );

        $this->relatedEntities = self::validateRelatedEntities($relatedEntities);
        $this->changes = self::validateChanges($changes);
    }

    public function eventId(): ActivityEventId
    {
        return $this->eventId;
    }

    public function libraryId(): LibraryId
    {
        return $this->libraryId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function actor(): ?ActivityActorSnapshot
    {
        return $this->actor;
    }

    public function source(): ActivityEventSource
    {
        return $this->source;
    }

    public function eventKey(): ActivityEventKey
    {
        return $this->eventKey;
    }

    public function primaryEntity(): ActivityEntityIdentity
    {
        return $this->primaryEntity;
    }

    /** @return list<ActivityEntitySnapshot> */
    public function relatedEntities(): array
    {
        return $this->relatedEntities;
    }

    /** @return list<ActivityChange> */
    public function changes(): array
    {
        return $this->changes;
    }

    /**
     * @param list<ActivityEntitySnapshot> $entities
     * @return list<ActivityEntitySnapshot>
     */
    private static function validateRelatedEntities(array $entities): array
    {
        $identities = [];

        foreach ($entities as $entity) {
            $key = $entity->identity()->key();

            if (isset($identities[$key])) {
                throw new ValidationException(
                    "Activity Event related entities must be duplicate-free."
                );
            }

            $identities[$key] = true;
        }

        return $entities;
    }

    /**
     * @param list<ActivityChange> $changes
     * @return list<ActivityChange>
     */
    private static function validateChanges(array $changes): array
    {
        $fields = [];

        foreach ($changes as $change) {
            if (isset($fields[$change->field()])) {
                throw new ValidationException(
                    "Activity Event changes must use unique field names."
                );
            }

            $fields[$change->field()] = true;
        }

        return $changes;
    }
}
