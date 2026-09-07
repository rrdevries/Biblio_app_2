<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Discovery;

use Biblio\Core\Catalog\{Work,WorkId};
use Biblio\Core\Exception\ValidationException;

final readonly class WorkDiscoveryCursor
{
    public function __construct(
        private WorkDiscoverySearchTerm $search,
        private string $title,
        private WorkId $workId
    ) {
        $length = preg_match_all('/./us', $title);

        if ($length === false || trim($title) === "") {
            throw new ValidationException("Work cursor title must not be empty.");
        }

        if ($length > Work::MAX_TITLE_LENGTH) {
            throw new ValidationException("Work cursor title is too long.");
        }
    }

    public function search(): WorkDiscoverySearchTerm { return $this->search; }
    public function title(): string { return $this->title; }
    public function workId(): WorkId { return $this->workId; }
}
