<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
use Biblio\Core\Exception\ConflictException; use Biblio\Core\Exception\FailureReason;
final class PublicationStale extends ConflictException { public function __construct() { parent::__construct("Publication changed since it was loaded.", FailureReason::PublicationStale); } }
