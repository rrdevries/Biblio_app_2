<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Metadata\EditionMetadataProvenanceRepository;
use Biblio\Core\Application\Metadata\MetadataCandidate;
use Biblio\Core\Catalog\EditionId;
use DateTimeZone;
use wpdb;

final readonly class WpdbEditionMetadataProvenanceRepository implements
    EditionMetadataProvenanceRepository
{
    private const string DATE_FORMAT = "Y-m-d H:i:s.u";

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
    }

    public function addReviewedCandidate(
        EditionId $editionId,
        MetadataCandidate $candidate,
        bool $corrected
    ): void {
        $identity = hash("sha256", implode("\0", [
            $editionId->value(),
            $candidate->providerKey(),
            $candidate->providerRecordId(),
            $candidate->queriedIsbn()->isbn13()->value(),
        ]));
        $previousSuppression = $this->database->suppress_errors(true);
        try {
            $result = $this->database->insert(
                $this->tables->editionMetadataProvenance(),
                [
                    "provenance_id" => $identity,
                    "edition_id" => $editionId->value(),
                    "provider_key" => $candidate->providerKey(),
                    "provider_record_id" => $candidate->providerRecordId(),
                    "retrieved_at" => $candidate->retrievedAt()
                        ->setTimezone(new DateTimeZone("UTC"))
                        ->format(self::DATE_FORMAT),
                    "match_method" => $candidate->matchMethod()->value,
                    "queried_identifier_type" => "isbn_13",
                    "queried_identifier" => $candidate->queriedIsbn()
                        ->isbn13()->value(),
                    "confirmation_state" => $corrected
                        ? "accepted_corrected"
                        : "accepted_unchanged",
                ],
                ["%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s"]
            );
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        if ($result === 1) {
            return;
        }
        $conflict = WpdbErrorTranslator::conflict($this->database->last_error);
        if ($conflict !== null) {
            $existingId = $this->database->get_var($this->database->prepare(
                "SELECT provenance_id FROM `{$this->tables->editionMetadataProvenance()}` "
                    . "WHERE edition_id=%s AND provider_key=%s "
                    . "AND provider_record_id=%s AND queried_identifier=%s",
                $editionId->value(),
                $candidate->providerKey(),
                $candidate->providerRecordId(),
                $candidate->queriedIsbn()->isbn13()->value()
            ));
            if (!is_string($existingId)) {
                throw WpdbErrorTranslator::writeFailure(
                    "Could not persist unique Edition metadata provenance.",
                    $this->database->last_error
                );
            }
            if ($corrected && $this->database->update(
                $this->tables->editionMetadataProvenance(),
                ["confirmation_state" => "accepted_corrected"],
                ["provenance_id" => $existingId],
                ["%s"],
                ["%s"]
            ) === false) {
                throw WpdbErrorTranslator::writeFailure(
                    "Could not update Edition metadata provenance.",
                    $this->database->last_error
                );
            }
            return;
        }
        throw WpdbErrorTranslator::writeFailure(
            "Could not persist Edition metadata provenance.",
            $this->database->last_error
        );
    }
}
