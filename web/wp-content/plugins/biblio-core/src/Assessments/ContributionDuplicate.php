<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
use Biblio\Core\Exception\ConflictException; use Biblio\Core\Exception\FailureReason;
final class ContributionDuplicate extends ConflictException { public function __construct() { parent::__construct("A contribution of this type already occupies that context.", FailureReason::ContributionDuplicate); } }
