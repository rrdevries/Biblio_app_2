<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Classification;

use Biblio\Core\Catalog\Classification\ClassificationNameNormalizer;
use Biblio\Core\Catalog\Classification\ClassificationSeed;
use Biblio\Core\Catalog\Classification\ClassificationSeedAdoptionAmbiguity;
use Biblio\Core\Catalog\Classification\ClassificationSeedEvolution;
use Biblio\Core\Catalog\Classification\ClassificationTaxonomyType;
use Biblio\Core\Catalog\Classification\ClassificationTermConflict;
use Biblio\Core\Catalog\Classification\ClassificationTermStatus;
use Biblio\Core\Catalog\Classification\DefaultClassificationSeeds;
use Biblio\Core\Catalog\Classification\LibraryBookType;
use Biblio\Core\Catalog\Classification\LibraryBookTypeId;
use Biblio\Core\Catalog\Classification\LibraryGenre;
use Biblio\Core\Catalog\Classification\LibraryGenreId;
use Biblio\Core\Catalog\Classification\WritableLibraryBookTypeRepository;
use Biblio\Core\Catalog\Classification\WritableLibraryGenreRepository;
use Biblio\Core\Library\LibraryId;

final readonly class ClassificationSeedEvolutionService implements
    ClassificationSeedEvolution
{
    public function __construct(
        private WritableLibraryBookTypeRepository $bookTypes,
        private WritableLibraryGenreRepository $genres,
        private ClassificationNameNormalizer $normalizer
    ) {
    }

    public function evolve(LibraryId $libraryId): void
    {
        foreach (DefaultClassificationSeeds::bookTypes() as $seed) {
            $this->evolveBookType($libraryId, $seed);
        }

        foreach (DefaultClassificationSeeds::genres() as $seed) {
            $this->evolveGenre($libraryId, $seed);
        }
    }

    public function isConverged(LibraryId $libraryId): bool
    {
        foreach (DefaultClassificationSeeds::bookTypes() as $seed) {
            if (!$this->bookTypeResolved($libraryId, $seed)) {
                return false;
            }
        }

        foreach (DefaultClassificationSeeds::genres() as $seed) {
            if (!$this->genreResolved($libraryId, $seed)) {
                return false;
            }
        }

        return true;
    }

    public function ambiguities(LibraryId $libraryId): array
    {
        $ambiguities = [];

        foreach (DefaultClassificationSeeds::bookTypes() as $seed) {
            $candidateIds = $this->unsafeBookTypeCandidateIds(
                $libraryId,
                $seed
            );

            if ($candidateIds !== []) {
                $ambiguities[] = new ClassificationSeedAdoptionAmbiguity(
                    $libraryId,
                    ClassificationTaxonomyType::BookType,
                    $seed->key(),
                    $candidateIds
                );
            }
        }

        foreach (DefaultClassificationSeeds::genres() as $seed) {
            $candidateIds = $this->unsafeGenreCandidateIds($libraryId, $seed);

            if ($candidateIds !== []) {
                $ambiguities[] = new ClassificationSeedAdoptionAmbiguity(
                    $libraryId,
                    ClassificationTaxonomyType::Genre,
                    $seed->key(),
                    $candidateIds
                );
            }
        }

        return $ambiguities;
    }

    private function evolveBookType(
        LibraryId $libraryId,
        ClassificationSeed $seed
    ): void {
        if ($this->bookTypes->findBySeedKey($libraryId, $seed->key()) !== null) {
            return;
        }

        $candidate = $this->bookTypes->findByNormalizedName(
            $libraryId,
            $this->normalizer->normalize($seed->defaultName())
        );
        $reservedId = $this->bookTypeId($seed);
        $reserved = $this->bookTypes->find($libraryId, $reservedId);

        if ($candidate !== null) {
            if (
                $candidate->seedKey() !== null
                || ($reserved !== null
                    && !$reserved->id()->equals($candidate->id()))
            ) {
                return;
            }

            try {
                $this->bookTypes->adoptSeedKey(
                    $libraryId,
                    $candidate->id(),
                    $seed->key()
                );
            } catch (ClassificationTermConflict) {
            }

            return;
        }

        if ($reserved !== null) {
            return;
        }

        try {
            $this->bookTypes->add(new LibraryBookType(
                $libraryId,
                $reservedId,
                $seed->defaultName(),
                $this->normalizer->normalize($seed->defaultName()),
                ClassificationTermStatus::Active,
                $seed->key()
            ));

            return;
        } catch (ClassificationTermConflict) {
            return;
        }
    }

    private function evolveGenre(
        LibraryId $libraryId,
        ClassificationSeed $seed
    ): void {
        if ($this->genres->findBySeedKey($libraryId, $seed->key()) !== null) {
            return;
        }

        $candidate = $this->genres->findByNormalizedName(
            $libraryId,
            $this->normalizer->normalize($seed->defaultName())
        );
        $reservedId = $this->genreId($seed);
        $reserved = $this->genres->find($libraryId, $reservedId);

        if ($candidate !== null) {
            if (
                $candidate->seedKey() !== null
                || ($reserved !== null
                    && !$reserved->id()->equals($candidate->id()))
            ) {
                return;
            }

            try {
                $this->genres->adoptSeedKey(
                    $libraryId,
                    $candidate->id(),
                    $seed->key()
                );
            } catch (ClassificationTermConflict) {
            }

            return;
        }

        if ($reserved !== null) {
            return;
        }

        try {
            $this->genres->add(new LibraryGenre(
                $libraryId,
                $reservedId,
                $seed->defaultName(),
                $this->normalizer->normalize($seed->defaultName()),
                ClassificationTermStatus::Active,
                $seed->key()
            ));

            return;
        } catch (ClassificationTermConflict) {
            return;
        }
    }

    private function bookTypeResolved(
        LibraryId $libraryId,
        ClassificationSeed $seed
    ): bool {
        if ($this->bookTypes->findBySeedKey($libraryId, $seed->key()) !== null) {
            return true;
        }

        return $this->unsafeBookTypeCandidateIds($libraryId, $seed) !== [];
    }

    private function genreResolved(
        LibraryId $libraryId,
        ClassificationSeed $seed
    ): bool {
        if ($this->genres->findBySeedKey($libraryId, $seed->key()) !== null) {
            return true;
        }

        return $this->unsafeGenreCandidateIds($libraryId, $seed) !== [];
    }

    /** @return list<string> */
    private function unsafeBookTypeCandidateIds(
        LibraryId $libraryId,
        ClassificationSeed $seed
    ): array {
        if ($this->bookTypes->findBySeedKey($libraryId, $seed->key()) !== null) {
            return [];
        }

        $normalizedCandidate = $this->bookTypes->findByNormalizedName(
            $libraryId,
            $this->normalizer->normalize($seed->defaultName())
        );
        $reserved = $this->bookTypes->find($libraryId, $this->bookTypeId($seed));

        return $this->unsafeCandidateIds(
            $normalizedCandidate?->id()->value(),
            $normalizedCandidate?->seedKey()?->value(),
            $reserved?->id()->value(),
            $reserved?->normalizedName()->value(),
            $this->normalizer->normalize($seed->defaultName())->value()
        );
    }

    /** @return list<string> */
    private function unsafeGenreCandidateIds(
        LibraryId $libraryId,
        ClassificationSeed $seed
    ): array {
        if ($this->genres->findBySeedKey($libraryId, $seed->key()) !== null) {
            return [];
        }

        $normalizedCandidate = $this->genres->findByNormalizedName(
            $libraryId,
            $this->normalizer->normalize($seed->defaultName())
        );
        $reserved = $this->genres->find($libraryId, $this->genreId($seed));

        return $this->unsafeCandidateIds(
            $normalizedCandidate?->id()->value(),
            $normalizedCandidate?->seedKey()?->value(),
            $reserved?->id()->value(),
            $reserved?->normalizedName()->value(),
            $this->normalizer->normalize($seed->defaultName())->value()
        );
    }

    /** @return list<string> */
    private function unsafeCandidateIds(
        ?string $normalizedCandidateId,
        ?string $normalizedCandidateSeedKey,
        ?string $reservedId,
        ?string $reservedNormalizedName,
        string $expectedNormalizedName
    ): array {
        $unsafe = [];

        if (
            $normalizedCandidateId !== null
            && $normalizedCandidateSeedKey !== null
        ) {
            $unsafe[] = $normalizedCandidateId;
        }

        if (
            $reservedId !== null
            && ($reservedId !== $normalizedCandidateId
                || $reservedNormalizedName !== $expectedNormalizedName)
        ) {
            $unsafe[] = $reservedId;
        }

        $unsafe = array_values(array_unique($unsafe));
        sort($unsafe, SORT_STRING);

        return $unsafe;
    }

    private function bookTypeId(ClassificationSeed $seed): LibraryBookTypeId
    {
        return new LibraryBookTypeId(
            "seed-book-type-" . substr(hash("sha256", $seed->key()->value()), 0, 32)
        );
    }

    private function genreId(ClassificationSeed $seed): LibraryGenreId
    {
        return new LibraryGenreId(
            "seed-genre-" . substr(hash("sha256", $seed->key()->value()), 0, 32)
        );
    }
}
