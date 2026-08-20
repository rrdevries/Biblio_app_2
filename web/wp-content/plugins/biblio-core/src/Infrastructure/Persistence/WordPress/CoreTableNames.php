<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Exception\ValidationException;

final readonly class CoreTableNames
{
    private const MAX_TABLE_NAME_LENGTH = 64;

    private string $libraries;
    private string $memberships;
    private string $personalLibraryDesignations;
    private string $works;
    private string $editions;
    private string $items;
    private string $externalLoans;
    private string $readingRounds;
    private string $libraryBookTypes;
    private string $libraryGenres;
    private string $librarySubjects;
    private string $libraryCatalogContexts;
    private string $libraryCatalogContextGenres;
    private string $libraryCatalogContextSubjects;
    private string $libraryActivityEvents;

    public function __construct(string $prefix)
    {
        $this->libraries = $prefix . "biblio_libraries";
        $this->memberships = $prefix . "biblio_library_memberships";
        $this->personalLibraryDesignations = $prefix
            . "biblio_personal_library_designations";
        $this->works = $prefix . "biblio_works";
        $this->editions = $prefix . "biblio_editions";
        $this->items = $prefix . "biblio_items";
        $this->externalLoans = $prefix . "biblio_external_loans";
        $this->readingRounds = $prefix . "biblio_reading_rounds";
        $this->libraryBookTypes = $prefix . "biblio_library_book_types";
        $this->libraryGenres = $prefix . "biblio_library_genres";
        $this->librarySubjects = $prefix . "biblio_library_subjects";
        $this->libraryCatalogContexts = $prefix
            . "biblio_library_catalog_contexts";
        $this->libraryCatalogContextGenres = $prefix
            . "biblio_library_catalog_context_genres";
        $this->libraryCatalogContextSubjects = $prefix
            . "biblio_library_catalog_context_subjects";
        $this->libraryActivityEvents = $prefix
            . "biblio_library_activity_events";

        foreach ($this->schema1001() as $tableName) {
            $this->assertSafe($tableName);
        }
    }

    public function libraries(): string
    {
        return $this->libraries;
    }

    public function memberships(): string
    {
        return $this->memberships;
    }

    public function personalLibraryDesignations(): string
    {
        return $this->personalLibraryDesignations;
    }

    public function works(): string
    {
        return $this->works;
    }

    public function editions(): string
    {
        return $this->editions;
    }

    public function items(): string
    {
        return $this->items;
    }

    public function externalLoans(): string
    {
        return $this->externalLoans;
    }

    public function readingRounds(): string
    {
        return $this->readingRounds;
    }

    public function libraryBookTypes(): string
    {
        return $this->libraryBookTypes;
    }

    public function libraryGenres(): string
    {
        return $this->libraryGenres;
    }

    public function librarySubjects(): string
    {
        return $this->librarySubjects;
    }

    public function libraryCatalogContexts(): string
    {
        return $this->libraryCatalogContexts;
    }

    public function libraryCatalogContextGenres(): string
    {
        return $this->libraryCatalogContextGenres;
    }

    public function libraryCatalogContextSubjects(): string
    {
        return $this->libraryCatalogContextSubjects;
    }

    public function libraryActivityEvents(): string
    {
        return $this->libraryActivityEvents;
    }

    /** @return list<string> */
    public function all(): array
    {
        return [
            $this->libraries,
            $this->memberships,
            $this->personalLibraryDesignations,
            $this->works,
            $this->editions,
            $this->items,
            $this->externalLoans,
            $this->readingRounds,
        ];
    }

    /** @return list<string> */
    public function schema1001Additions(): array
    {
        return [
            $this->libraryBookTypes,
            $this->libraryGenres,
            $this->librarySubjects,
            $this->libraryCatalogContexts,
            $this->libraryCatalogContextGenres,
            $this->libraryCatalogContextSubjects,
            $this->libraryActivityEvents,
        ];
    }

    /** @return list<string> */
    public function schema1001(): array
    {
        return array_merge($this->all(), $this->schema1001Additions());
    }

    private function assertSafe(string $tableName): void
    {
        if (
            preg_match('/^[a-zA-Z0-9_]+$/', $tableName) !== 1
            || strlen($tableName) > self::MAX_TABLE_NAME_LENGTH
        ) {
            throw new ValidationException("Unsafe database table name.");
        }
    }
}
