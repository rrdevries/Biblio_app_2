<?php
declare(strict_types=1);
namespace Biblio\Core\Infrastructure\WordPress;
use Biblio\Core\Assessments\AssessmentClock;use DateTimeImmutable;use DateTimeZone;
final readonly class SystemAssessmentClock implements AssessmentClock{public function now():DateTimeImmutable{return new DateTimeImmutable('now',new DateTimeZone('UTC'));}}
