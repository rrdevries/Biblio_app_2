<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

enum SourceDriftCategory: string
{
    case A = "A";
    case B = "B";
    case C = "C";
    case D = "D";
    case E = "E";
    case F = "F";
    case G = "G";
    case H = "H";
    case I = "I";
    case J = "J";

    public function requiresContractReview(): bool
    {
        return in_array($this, [self::E, self::F, self::G, self::H, self::I, self::J], true);
    }
}
