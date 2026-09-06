<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Metadata\UserObservedMetadataEvidence;
use Biblio\Core\Application\Metadata\UserObservedMetadataEvidenceRepository;
use DateTimeZone;
use wpdb;

final readonly class WpdbUserObservedMetadataEvidenceRepository implements
    UserObservedMetadataEvidenceRepository
{
    private const string DATE_FORMAT = "Y-m-d H:i:s.u";

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
    }

    public function add(UserObservedMetadataEvidence $evidence): void
    {
        $result = $this->database->insert(
            $this->tables->metadataUserObservations(),
            [
                "observation_id" => $evidence->id(),
                "metadata_record_id" => $evidence->recordId()->value(),
                "field_key" => $evidence->field()->value,
                "value_hash" => $evidence->value()->hash(),
                "value_json" => $evidence->value()->json(),
                "edition_id" => $evidence->editionId()->value(),
                "library_id" => $evidence->libraryId()->value(),
                "item_id" => $evidence->itemId()->value(),
                "actor_user_id" => $evidence->actorId()->value(),
                "observed_at" => $evidence->observedAt()
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format(self::DATE_FORMAT),
                "source_context" => $evidence->source()->value,
                "correction_proposal" => $evidence->isCorrectionProposal() ? 1 : 0,
            ],
            ["%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%d"]
        );
        if ($result !== 1) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not persist user-observed metadata evidence.",
                $this->database->last_error
            );
        }
    }
}
