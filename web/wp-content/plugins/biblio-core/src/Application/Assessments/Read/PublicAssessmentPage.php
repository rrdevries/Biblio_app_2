<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Assessments\Read;

use Biblio\Core\Assessments\RatingAggregate;

final readonly class PublicAssessmentPage
{
    /** @param list<PublicAssessmentView> $contributions */
    public function __construct(
        private array $contributions,
        private RatingAggregate $aggregate,
        private ?PublicAssessmentCursor $nextCursor
    ) {
    }

    /** @return list<PublicAssessmentView> */
    public function contributions(): array
    {
        return $this->contributions;
    }

    public function aggregate(): RatingAggregate
    {
        return $this->aggregate;
    }

    public function nextCursor(): ?PublicAssessmentCursor
    {
        return $this->nextCursor;
    }
}
