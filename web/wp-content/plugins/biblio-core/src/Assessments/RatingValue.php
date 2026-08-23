<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
use Biblio\Core\Exception\ValidationException;
final readonly class RatingValue
{
    private function __construct(private int $halfUnits) {}
    public static function fromHalfUnits(int $halfUnits): self { if ($halfUnits < 2 || $halfUnits > 10) throw new ValidationException("Rating must be 1.0 through 5.0 in half-star steps."); return new self($halfUnits); }
    public static function fromStars(float $stars): self { if (!is_finite($stars)) throw new ValidationException("Rating must be finite."); $scaled = $stars * 2; if ($scaled !== (float) (int) $scaled) throw new ValidationException("Rating must use half-star steps."); return self::fromHalfUnits((int) $scaled); }
    public function halfUnits(): int { return $this->halfUnits; }
    public function stars(): float { return $this->halfUnits / 2; }
    public function equals(self $other): bool { return $this->halfUnits === $other->halfUnits; }
}
