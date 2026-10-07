<?php

declare(strict_types=1);
namespace Biblio\Core\Application\Metadata\GlobalSearch;
use Biblio\Core\Exception\{CoreFailure,FailureReason};
final class BookSearchAccessChanged extends \RuntimeException implements CoreFailure
{
    public function reason(): FailureReason { return FailureReason::ValidationFailed; }
}
