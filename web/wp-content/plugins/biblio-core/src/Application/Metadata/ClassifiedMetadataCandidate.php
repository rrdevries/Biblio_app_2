<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use LogicException;

final readonly class ClassifiedMetadataCandidate
{
    public function __construct(
        private MetadataCandidate $candidate,
        private CandidateQuality $quality
    ) {
        if ($quality === CandidateQuality::Invalid) {
            throw new LogicException("Invalid metadata candidates are not usable.");
        }
    }

    public function candidate(): MetadataCandidate { return $this->candidate; }
    public function quality(): CandidateQuality { return $this->quality; }
}
