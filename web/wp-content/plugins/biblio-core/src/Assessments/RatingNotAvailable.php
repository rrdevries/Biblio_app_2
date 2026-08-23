<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
use Biblio\Core\Exception\AuthorizationException; use Biblio\Core\Exception\FailureReason;
final class RatingNotAvailable extends AuthorizationException { public function __construct() { parent::__construct("Rating is not available to this user.", FailureReason::RatingNotAvailable); } }
