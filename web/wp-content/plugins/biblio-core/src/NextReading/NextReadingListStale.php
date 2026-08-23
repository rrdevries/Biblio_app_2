<?php
declare(strict_types=1);
namespace Biblio\Core\NextReading;
use Biblio\Core\Exception\{ConflictException,FailureReason};
final class NextReadingListStale extends ConflictException
{
    public function __construct(private readonly NextReadingList $current)
    {
        parent::__construct("Next Reading List changed since it was loaded.", FailureReason::NextReadingListStale);
    }
    public function current(): NextReadingList { return $this->current; }
}
