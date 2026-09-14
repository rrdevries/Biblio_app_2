<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class ItemAcquisitionDate
{
    public function __construct(
        private int $year,
        private ?int $month = null,
        private ?int $day = null
    ) {
        if ($this->year < 1000 || $this->year > 9999) {
            throw new ValidationException("Acquisition year is invalid.");
        }
        if ($this->month !== null && ($this->month < 1 || $this->month > 12)) {
            throw new ValidationException("Acquisition month is invalid.");
        }
        if ($this->day !== null && $this->month === null) {
            throw new ValidationException("Acquisition day requires a month.");
        }
        if ($this->day !== null && !checkdate($this->month, $this->day, $this->year)) {
            throw new ValidationException("Acquisition date is invalid.");
        }
    }

    public function year(): int { return $this->year; }
    public function month(): ?int { return $this->month; }
    public function day(): ?int { return $this->day; }

    /** @return array{year:int,month:int|null,day:int|null} */
    public function toArray(): array
    {
        return ["year" => $this->year, "month" => $this->month, "day" => $this->day];
    }

    public function equals(self $other): bool
    {
        return $this->year === $other->year
            && $this->month === $other->month
            && $this->day === $other->day;
    }
}
