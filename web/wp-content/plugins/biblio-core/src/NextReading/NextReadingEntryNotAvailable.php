<?php
declare(strict_types=1);
namespace Biblio\Core\NextReading;
use Biblio\Core\Exception\{ConflictException,FailureReason};
final class NextReadingEntryNotAvailable extends ConflictException
{
    public function __construct() { parent::__construct("Next Reading Entry is unavailable.", FailureReason::NextReadingEntryNotAvailable); }
}
