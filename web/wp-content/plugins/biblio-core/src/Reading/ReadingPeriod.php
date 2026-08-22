<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Exception\ValidationException;

final readonly class ReadingPeriod
{
    public function __construct(
        private ?ReadingDate $startedOn,
        private ?ReadingDate $finishedOn
    ) {
        if (
            $this->startedOn !== null
            && $this->finishedOn !== null
            && $this->startedOn->earliest() > $this->finishedOn->latest()
        ) {
            throw new ValidationException(
                "Reading period has no possible chronological ordering."
            );
        }
    }

    public static function active(ReadingDate $startedOn): self
    {
        return new self($startedOn, null);
    }

    public static function ended(
        ?ReadingDate $startedOn,
        ReadingDate $finishedOn
    ): self {
        return new self($startedOn, $finishedOn);
    }

    public function startedOn(): ?ReadingDate
    {
        return $this->startedOn;
    }

    public function finishedOn(): ?ReadingDate
    {
        return $this->finishedOn;
    }

    public function equals(self $other): bool
    {
        return self::sameDate($this->startedOn, $other->startedOn)
            && self::sameDate($this->finishedOn, $other->finishedOn);
    }

    private static function sameDate(
        ?ReadingDate $left,
        ?ReadingDate $right
    ): bool {
        return $left === null
            ? $right === null
            : $right !== null && $left->equals($right);
    }
}
