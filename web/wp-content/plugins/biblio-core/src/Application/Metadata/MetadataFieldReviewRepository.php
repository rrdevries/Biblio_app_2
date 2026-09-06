<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use DateTimeImmutable;

interface MetadataFieldReviewRepository
{
    public function find(
        MetadataRecordId $recordId,
        MetadataField $field
    ): ?MetadataFieldReview;

    public function findForUpdate(
        MetadataRecordId $recordId,
        MetadataField $field,
        DateTimeImmutable $whenMissing
    ): MetadataFieldReview;

    public function save(MetadataFieldReview $review): void;
}
