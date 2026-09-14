<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

use Biblio\Core\Exception\ValidationException;

final readonly class MigrationParticipantRegistry
{
    /** @var array<string, MigrationParticipant> */
    private array $participants;

    /** @param list<MigrationParticipant> $participants */
    public function __construct(array $participants)
    {
        $indexed = [];
        foreach ($participants as $participant) {
            $type = $participant->sourceType();
            if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $type) !== 1) {
                throw new ValidationException("Participant source type is invalid.");
            }
            if (isset($indexed[$type])) {
                throw new MigrationRunnerFailure(
                    MigrationRunnerReason::DuplicateParticipant,
                    "More than one participant owns source type: {$type}."
                );
            }
            $indexed[$type] = $participant;
        }
        ksort($indexed, SORT_STRING);
        $this->participants = $indexed;
    }

    public function forType(string $sourceType): ?MigrationParticipant
    {
        return $this->participants[$sourceType] ?? null;
    }
}
