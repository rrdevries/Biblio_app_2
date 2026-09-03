<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Assessments\Read;

use Biblio\Core\Assessments\PublicationId;
use Biblio\Core\Temporal\PersistedDateTimeConstraints;
use DateTimeImmutable;

final readonly class PublicAssessmentCursor
{
    public function __construct(
        private DateTimeImmutable $beforeUpdatedAt,
        private PublicationId $beforePublicationId
    ) {
        PersistedDateTimeConstraints::assertSupported(
            $beforeUpdatedAt,
            "Public assessment cursor time"
        );
    }

    public function beforeUpdatedAt(): DateTimeImmutable
    {
        return $this->beforeUpdatedAt;
    }

    public function beforePublicationId(): PublicationId
    {
        return $this->beforePublicationId;
    }
}
