<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Catalog\CanonicalIsbnAlreadyClaimed;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\EditionIdentifierClaimRepository;
use Biblio\Core\Catalog\Isbn13;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use wpdb;

final readonly class WpdbEditionIdentifierClaimRepository implements
    EditionIdentifierClaimRepository
{
    private WpdbTransactionConnection $connection;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
        $this->connection = new WpdbTransactionConnection($database);
    }

    public function findByCanonicalIsbn13(Isbn13 $isbn13): ?EditionId
    {
        $table = $this->tables->editionIdentifierClaims();
        $value = $this->database->get_var($this->database->prepare(
            "SELECT edition_id FROM `{$table}` WHERE canonical_isbn_13=%s",
            $isbn13->value()
        ));

        return is_string($value) ? new EditionId($value) : null;
    }

    public function claim(Isbn13 $isbn13, EditionId $editionId): void
    {
        if ($this->connection->isTransactionActive() !== true) {
            throw new PersistenceException(
                "Canonical ISBN claims require an active transaction.",
                failureReason: FailureReason::PersistenceWriteFailed
            );
        }

        $previous = $this->database->suppress_errors(true);
        try {
            $result = $this->database->insert(
                $this->tables->editionIdentifierClaims(),
                [
                    "canonical_isbn_13" => $isbn13->value(),
                    "edition_id" => $editionId->value(),
                ],
                ["%s", "%s"]
            );
        } finally {
            $this->database->suppress_errors($previous);
        }

        if ($result === 1) {
            return;
        }

        $databaseError = $this->database->last_error;
        $existing = $this->findByCanonicalIsbn13($isbn13);
        if ($existing?->equals($editionId)) {
            return;
        }

        if (WpdbErrorTranslator::conflict($databaseError) !== null) {
            throw new CanonicalIsbnAlreadyClaimed();
        }

        throw WpdbErrorTranslator::writeFailure(
            "Could not persist canonical ISBN claim.",
            $databaseError
        );
    }
}
