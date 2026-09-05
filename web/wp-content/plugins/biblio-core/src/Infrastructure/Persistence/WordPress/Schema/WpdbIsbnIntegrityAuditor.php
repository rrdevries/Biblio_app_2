<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\EditionIsbnMetadata;
use Biblio\Core\Catalog\Isbn10;
use Biblio\Core\Catalog\Isbn13;
use Biblio\Core\Catalog\IsbnRules;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Throwable;
use wpdb;

final readonly class WpdbIsbnIntegrityAuditor
{
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
    }

    public function audit(): IsbnIntegrityAuditReport
    {
        $rows = $this->database->get_results(
            "SELECT edition_id,isbn_10,isbn_13,explicitly_no_isbn "
                . "FROM `{$this->tables->editions()}` ORDER BY edition_id"
        );
        $with13 = 0;
        $only10 = 0;
        $without = 0;
        $invalid = [];
        $internal = [];
        $format = [];
        $exact13 = [];
        $canonicalGroups = [];

        foreach ($rows as $row) {
            $editionId = (string) $row->edition_id;
            $raw10 = is_string($row->isbn_10) ? $row->isbn_10 : null;
            $raw13 = is_string($row->isbn_13) ? $row->isbn_13 : null;

            if ($raw13 !== null) {
                $with13++;
                $exact13[$raw13][] = $editionId;
            } elseif ($raw10 !== null) {
                $only10++;
            } else {
                $without++;
            }

            if (
                ($raw10 !== null && IsbnRules::normalized($raw10) !== $raw10)
                || ($raw13 !== null && IsbnRules::normalized($raw13) !== $raw13)
            ) {
                $format[] = $editionId;
            }

            if ($raw10 === null && $raw13 === null) {
                continue;
            }

            try {
                $isbn10 = $raw10 === null ? null : new Isbn10($raw10);
                $isbn13 = $raw13 === null ? null : new Isbn13($raw13);
                $metadata = EditionIsbnMetadata::identified($isbn10, $isbn13);
                $identity = CanonicalIsbnIdentity::fromMetadata($metadata);
                if ($identity === null) {
                    $invalid[] = $editionId;
                    continue;
                }
                $canonicalGroups[$identity->isbn13()->value()][] = $editionId;
            } catch (Throwable $exception) {
                if ($raw10 !== null && $raw13 !== null) {
                    try {
                        $tenIdentity = CanonicalIsbnIdentity::fromIsbn(
                            new Isbn10($raw10)
                        );
                        $thirteenIdentity = CanonicalIsbnIdentity::fromIsbn(
                            new Isbn13($raw13)
                        );
                        if (
                            $tenIdentity->isbn13()->value()
                            !== $thirteenIdentity->isbn13()->value()
                        ) {
                            $internal[] = $editionId;
                            continue;
                        }
                    } catch (Throwable) {
                        // The original row is invalid and is reported below.
                    }
                }
                $invalid[] = $editionId;
            }
        }

        $exactCollisions = $this->collisions($exact13);
        $equivalenceCollisions = $this->collisions($canonicalGroups);
        $claims = [];
        foreach ($canonicalGroups as $canonical => $editionIds) {
            if (count($editionIds) === 1) {
                $claims[$canonical] = $editionIds[0];
            }
        }

        return new IsbnIntegrityAuditReport(
            count($rows),
            $with13,
            $only10,
            $without,
            array_values(array_unique($invalid)),
            $exactCollisions,
            $equivalenceCollisions,
            array_values(array_unique($internal)),
            array_values(array_unique($format)),
            $claims
        );
    }

    /**
     * @param array<string, list<string>> $groups
     * @return array<string, list<string>>
     */
    private function collisions(array $groups): array
    {
        return array_filter(
            $groups,
            static fn (array $editionIds): bool => count($editionIds) > 1
        );
    }
}
