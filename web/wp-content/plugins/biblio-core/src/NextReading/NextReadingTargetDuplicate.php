<?php
declare(strict_types=1);
namespace Biblio\Core\NextReading;
use Biblio\Core\Exception\{ConflictException,FailureReason};
final class NextReadingTargetDuplicate extends ConflictException
{
    public function __construct() { parent::__construct("This Next Reading target already exists.", FailureReason::NextReadingTargetDuplicate); }
}
