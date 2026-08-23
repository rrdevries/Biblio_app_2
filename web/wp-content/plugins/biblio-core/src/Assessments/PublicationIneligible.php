<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
use Biblio\Core\Exception\AuthorizationException; use Biblio\Core\Exception\FailureReason;
final class PublicationIneligible extends AuthorizationException { public function __construct() { parent::__construct("Contribution is not eligible for publication in this Library.", FailureReason::PublicationIneligible); } }
