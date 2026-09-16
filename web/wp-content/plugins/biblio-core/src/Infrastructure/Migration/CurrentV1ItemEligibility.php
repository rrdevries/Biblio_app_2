<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

enum CurrentV1ItemEligibility: string
{
    case ItemEligible = "item_eligible";
    case NotLibraryItemExternalBorrowed = "not_library_item_external_borrowed";
    case InvalidItemLocalAcquisitionDate = "invalid_item_local_acquisition_date";
}
