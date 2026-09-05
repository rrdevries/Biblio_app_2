<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Catalog\CanonicalIsbnIdentity;

final readonly class CandidateClassifier
{
    public function classify(
        MetadataCandidate $candidate,
        CanonicalIsbnIdentity $queriedIsbn
    ): CandidateQuality {
        $canonicalQuery = $queriedIsbn->isbn13()->value();
        if (
            $candidate->queriedIsbn()->isbn13()->value() !== $canonicalQuery
            || $candidate->returnedIsbn()->isbn13()->value() !== $canonicalQuery
            || $candidate->title() === null
        ) {
            return CandidateQuality::Invalid;
        }

        if (
            $candidate->contributors() === []
            || $candidate->languages() === []
            || $candidate->publishers() === []
            || $candidate->publicationDate() === null
        ) {
            return CandidateQuality::Incomplete;
        }

        return CandidateQuality::Sufficient;
    }
}
