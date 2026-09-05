<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog;

use Biblio\Core\Catalog\BibliographicMetadataRepository;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionIdentifierClaimRepository;
use Biblio\Core\Catalog\EditionRepository;
use Biblio\Core\Catalog\InvalidIsbnInput;
use Biblio\Core\Catalog\IsbnCanonicalizer;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Exception\FailureReason;

final readonly class LocalEditionResolver
{
    public function __construct(
        private IsbnCanonicalizer $canonicalizer,
        private EditionIdentifierClaimRepository $claims,
        private EditionRepository $editions,
        private BibliographicMetadataRepository $legacyMetadata
    ) {
    }

    public function resolveInput(string $input): LocalEditionResolution
    {
        $parsed = $this->canonicalizer->parse($input);
        if (!$parsed->isValid()) {
            throw new InvalidIsbnInput(
                $parsed->error(),
                "ISBN input is invalid: {$parsed->error()?->value}."
            );
        }

        return $this->resolveIdentity($parsed->identity());
    }

    public function resolveIdentity(
        CanonicalIsbnIdentity $identity
    ): LocalEditionResolution {
        $claimedId = $this->claims->findByCanonicalIsbn13($identity->isbn13());

        if ($claimedId !== null) {
            $edition = $this->editions->find($claimedId);
            if ($edition === null) {
                throw new PersistenceException(
                    "Canonical ISBN claim references a missing Edition.",
                    failureReason: FailureReason::PersistenceReadFailed
                );
            }

            $storedIdentity = CanonicalIsbnIdentity::fromMetadata(
                $edition->isbnMetadata()
            );
            if ($storedIdentity?->isbn13()->value() !== $identity->isbn13()->value()) {
                throw new PersistenceException(
                    "Canonical ISBN claim disagrees with Edition metadata.",
                    failureReason: FailureReason::PersistenceReadFailed
                );
            }

            return LocalEditionResolution::exact($identity, $edition);
        }

        $legacy = $this->legacyCandidates($identity);
        if ($legacy === []) {
            return LocalEditionResolution::none($identity);
        }
        if (count($legacy) === 1) {
            return LocalEditionResolution::exact($identity, $legacy[0]);
        }

        return LocalEditionResolution::ambiguous($identity, $legacy);
    }

    /** @return list<Edition> */
    private function legacyCandidates(CanonicalIsbnIdentity $identity): array
    {
        $isbns = [$identity->isbn13()];
        if ($identity->isbn10() !== null) {
            $isbns[] = $identity->isbn10();
        }
        $found = $this->legacyMetadata->editionsForIsbns($isbns);
        $unique = [];

        foreach ($found as $editions) {
            foreach ($editions as $edition) {
                $unique[$edition->id()->value()] = $edition;
            }
        }

        ksort($unique);
        return array_values($unique);
    }
}
