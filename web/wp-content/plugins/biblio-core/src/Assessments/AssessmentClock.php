<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
use DateTimeImmutable;
interface AssessmentClock { public function now(): DateTimeImmutable; }
