<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Exception\ValidationException;
use DateTimeImmutable;
use DateTimeZone;

final readonly class ReadingDate
{
    public function __construct(
        private int $year,
        private ?int $month = null,
        private ?int $day = null
    ) {
        if ($this->year < 1000 || $this->year > 9999) {
            throw new ValidationException(
                "Reading date year must be between 1000 and 9999."
            );
        }

        if ($this->month === null && $this->day !== null) {
            throw new ValidationException(
                "Reading date day requires a known month."
            );
        }

        if (
            $this->month !== null
            && ($this->month < 1 || $this->month > 12)
        ) {
            throw new ValidationException(
                "Reading date month must be between 1 and 12."
            );
        }

        if (
            $this->day !== null
            && !checkdate($this->month ?? 0, $this->day, $this->year)
        ) {
            throw new ValidationException(
                "Reading date must be a valid calendar date."
            );
        }
    }

    public static function exact(int $year, int $month, int $day): self
    {
        return new self($year, $month, $day);
    }

    public static function month(int $year, int $month): self
    {
        return new self($year, $month);
    }

    public static function year(int $year): self
    {
        return new self($year);
    }

    public function yearValue(): int
    {
        return $this->year;
    }

    public function monthValue(): ?int
    {
        return $this->month;
    }

    public function dayValue(): ?int
    {
        return $this->day;
    }

    public function isExact(): bool
    {
        return $this->day !== null;
    }

    public function earliest(): DateTimeImmutable
    {
        return $this->calendarDate($this->month ?? 1, $this->day ?? 1);
    }

    public function latest(): DateTimeImmutable
    {
        $month = $this->month ?? 12;
        $day = $this->day ?? (int) $this->calendarDate($month, 1)->format("t");

        return $this->calendarDate($month, $day);
    }

    public function equals(self $other): bool
    {
        return $this->year === $other->year
            && $this->month === $other->month
            && $this->day === $other->day;
    }

    private function calendarDate(int $month, int $day): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            "!Y-n-j",
            "{$this->year}-{$month}-{$day}",
            new DateTimeZone("UTC")
        );

        if ($date === false) {
            throw new ValidationException("Reading date cannot be represented.");
        }

        return $date;
    }
}
