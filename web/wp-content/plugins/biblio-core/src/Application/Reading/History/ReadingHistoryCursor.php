<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading\History;

use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingRoundId;
use DateTimeImmutable;
use DateTimeZone;

final readonly class ReadingHistoryCursor
{
    public function __construct(
        private int $finishedEarliest,
        private int $finishedLatest,
        private ReadingRoundId $tieBreaker
    ) {
        if (!$this->isReadingDateInterval($finishedEarliest, $finishedLatest)) {
            throw new ValidationException(
                "Reading history cursor contains an invalid finish interval."
            );
        }
    }

    public static function after(
        ReadingDate $finishedOn,
        ReadingRoundId $tieBreaker
    ): self {
        return new self(
            self::dateKey($finishedOn->earliest()),
            self::dateKey($finishedOn->latest()),
            $tieBreaker
        );
    }

    public function finishedEarliest(): int
    {
        return $this->finishedEarliest;
    }

    public function finishedLatest(): int
    {
        return $this->finishedLatest;
    }

    public function tieBreaker(): ReadingRoundId
    {
        return $this->tieBreaker;
    }

    private static function dateKey(DateTimeImmutable $date): int
    {
        return (int) $date->format("Ymd");
    }

    private function isReadingDateInterval(int $earliest, int $latest): bool
    {
        $earliestDate = $this->dateFromKey($earliest);
        $latestDate = $this->dateFromKey($latest);

        if (
            $earliestDate === null
            || $latestDate === null
            || $earliestDate > $latestDate
            || $earliestDate->format("Y") !== $latestDate->format("Y")
        ) {
            return false;
        }

        if ($earliest === $latest) {
            return true;
        }

        $isMonth = $earliestDate->format("Ym") === $latestDate->format("Ym")
            && $earliestDate->format("j") === "1"
            && $latestDate->format("j") === $latestDate->format("t");
        $isYear = $earliestDate->format("md") === "0101"
            && $latestDate->format("md") === "1231";

        return $isMonth || $isYear;
    }

    private function dateFromKey(int $key): ?DateTimeImmutable
    {
        if ($key < 10000101 || $key > 99991231) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(
            "!Ymd",
            (string) $key,
            new DateTimeZone("UTC")
        );

        return $date !== false && $date->format("Ymd") === (string) $key
            ? $date
            : null;
    }
}
