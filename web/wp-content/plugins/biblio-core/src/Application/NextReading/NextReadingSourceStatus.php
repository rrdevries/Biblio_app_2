<?php
declare(strict_types=1);
namespace Biblio\Core\Application\NextReading;
enum NextReadingSourceStatus: string
{
    case Live = "live";
    case Unavailable = "unavailable";
    case Inaccessible = "inaccessible";
    case Missing = "missing";
}
