<?php
declare(strict_types=1);
namespace Biblio\Core\NextReading;
use Biblio\Core\Exception\{ConflictException,FailureReason};
final class NextReadingTargetUnavailable extends ConflictException
{
    public function __construct() { parent::__construct("Next Reading target is unavailable.", FailureReason::NextReadingTargetUnavailable); }
}
