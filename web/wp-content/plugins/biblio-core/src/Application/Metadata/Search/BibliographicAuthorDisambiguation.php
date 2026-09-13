<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Catalog\Work;
use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicAuthorDisambiguation
{
    private const int MAX_JSON_SAFE_INTEGER = 9007199254740991;

    private function __construct(
        private ?string $representativeWorkTitle,
        private ?int $linkedWorkCount,
        private ?int $birthYear
    ) {
    }

    public static function unknown(): self
    {
        return new self(null, null, null);
    }

    public static function local(int $linkedWorkCount, ?string $representativeWorkTitle): self
    {
        if ($linkedWorkCount < 0 || $linkedWorkCount > self::MAX_JSON_SAFE_INTEGER) {
            throw new ValidationException("Invalid linked Work count.");
        }
        if (($linkedWorkCount === 1) !== ($representativeWorkTitle !== null)) {
            throw new ValidationException(
                "A representative Work title requires exactly one linked Work."
            );
        }
        if ($representativeWorkTitle !== null) {
            BibliographicAuthorSearchResult::assertText(
                $representativeWorkTitle,
                Work::MAX_TITLE_LENGTH,
                "representative Work title"
            );
        }

        return new self($representativeWorkTitle, $linkedWorkCount, null);
    }

    public function representativeWorkTitle(): ?string
    {
        return $this->representativeWorkTitle;
    }

    public function linkedWorkCount(): ?int
    {
        return $this->linkedWorkCount;
    }

    public function birthYear(): ?int
    {
        return $this->birthYear;
    }
}
