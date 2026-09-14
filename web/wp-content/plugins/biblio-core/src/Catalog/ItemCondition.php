<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

enum ItemCondition: string
{
    case Nieuwstaat = "nieuwstaat";
    case ZeerGoed = "zeer_goed";
    case Goed = "goed";
    case Redelijk = "redelijk";
    case Matig = "matig";
    case Slecht = "slecht";

    public function label(): string
    {
        return match ($this) {
            self::Nieuwstaat => "Nieuwstaat",
            self::ZeerGoed => "Zeer goed",
            self::Goed => "Goed",
            self::Redelijk => "Redelijk",
            self::Matig => "Matig",
            self::Slecht => "Slecht",
        };
    }
}
