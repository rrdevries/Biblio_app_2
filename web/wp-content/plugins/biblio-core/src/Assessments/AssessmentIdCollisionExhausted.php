<?php
declare(strict_types=1);namespace Biblio\Core\Assessments;use Biblio\Core\Exception\{ConflictException,FailureReason};use Throwable;final class AssessmentIdCollisionExhausted extends ConflictException{public function __construct(Throwable $previous){parent::__construct('Assessment ID collisions exhausted.',FailureReason::AssessmentIdCollisionExhausted,$previous);}}
