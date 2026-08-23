<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
use Biblio\Core\Exception\AuthorizationException; use Biblio\Core\Exception\FailureReason;
final class PublicationNotAvailable extends AuthorizationException { public function __construct() { parent::__construct("Publication is not available in this context.", FailureReason::PublicationNotAvailable); } }
