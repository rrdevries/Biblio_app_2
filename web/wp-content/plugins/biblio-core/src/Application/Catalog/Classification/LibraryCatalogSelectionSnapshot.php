<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Classification;

use Biblio\Core\Catalog\Classification\ClassificationTermStatus;
use Biblio\Core\Exception\ValidationException;

final readonly class LibraryCatalogSelectionSnapshot
{
    /**
     * @param array{id: string, label: string, status: ClassificationTermStatus} $bookType
     * @param list<array{id: string, label: string, status: ClassificationTermStatus}> $genres
     * @param list<array{id: string, label: string, status: ClassificationTermStatus}> $subjects
     */
    public function __construct(
        private array $bookType,
        private array $genres,
        private array $subjects
    ) {
    }

    public function assertNewSelectionsAreActive(?self $current): void
    {
        if (
            ($current === null
                || $current->bookType["id"] !== $this->bookType["id"])
            && $this->bookType["status"] !== ClassificationTermStatus::Active
        ) {
            throw new ValidationException(
                "A newly selected Library Book Type must be active."
            );
        }

        $currentGenres = $current === null ? [] : $current->genres;
        $currentSubjects = $current === null ? [] : $current->subjects;
        $this->assertNewSetValuesAreActive(
            $currentGenres,
            $this->genres,
            "Library Genres"
        );
        $this->assertNewSetValuesAreActive(
            $currentSubjects,
            $this->subjects,
            "Library Subjects"
        );
    }

    /** @return array<string, mixed> */
    public function bookTypePayload(): array
    {
        return [
            "id" => $this->bookType["id"],
            "label" => $this->bookType["label"],
        ];
    }

    /** @return array<string, mixed> */
    public function genresPayload(): array
    {
        return ["terms" => $this->withoutStatuses($this->genres)];
    }

    /** @return array<string, mixed> */
    public function subjectsPayload(): array
    {
        return ["terms" => $this->withoutStatuses($this->subjects)];
    }

    /**
     * @param list<array{id: string, label: string, status: ClassificationTermStatus}> $current
     * @param list<array{id: string, label: string, status: ClassificationTermStatus}> $desired
     */
    private function assertNewSetValuesAreActive(
        array $current,
        array $desired,
        string $label
    ): void {
        $currentIds = array_column($current, "id");

        foreach ($desired as $term) {
            if (
                !in_array($term["id"], $currentIds, true)
                && $term["status"] !== ClassificationTermStatus::Active
            ) {
                throw new ValidationException(
                    "Newly selected {$label} must be active."
                );
            }
        }
    }

    /**
     * @param list<array{id: string, label: string, status: ClassificationTermStatus}> $terms
     * @return list<array{id: string, label: string}>
     */
    private function withoutStatuses(array $terms): array
    {
        return array_map(
            static fn (array $term): array => [
                "id" => $term["id"],
                "label" => $term["label"],
            ],
            $terms
        );
    }
}
