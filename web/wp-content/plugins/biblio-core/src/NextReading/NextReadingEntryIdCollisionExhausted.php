<?php
declare(strict_types=1);
namespace Biblio\Core\NextReading;
use Biblio\Core\Exception\{ConflictException,FailureReason};
final class NextReadingEntryIdCollisionExhausted extends ConflictException
{
    public function __construct() { parent::__construct("Could not issue a unique Next Reading Entry ID.", FailureReason::NextReadingEntryIdCollisionExhausted); }
}
