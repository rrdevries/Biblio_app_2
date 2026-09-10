<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Assessments\Read;

enum OwnAssessmentKind: string
{
    case Rating = "rating";
    case Review = "review";
}
