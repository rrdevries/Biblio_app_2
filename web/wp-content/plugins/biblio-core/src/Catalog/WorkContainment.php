<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class WorkContainment
{
    public function __construct(
        private WorkId $parentWorkId,
        private WorkId $containedWorkId,
        private ContainmentPosition $position
    ) {
        if ($parentWorkId->value() === $containedWorkId->value()) {
            throw new ValidationException("A Work cannot contain itself.");
        }
    }

    public function parentWorkId(): WorkId { return $this->parentWorkId; }
    public function containedWorkId(): WorkId
    {
        return $this->containedWorkId;
    }
    public function position(): ContainmentPosition { return $this->position; }
}
