<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use InvalidArgumentException;

final readonly class LibraryTableNames
{
    private string $libraries;
    private string $memberships;
    private string $personalLibraryDesignations;
    private string $works;
    private string $editions;
    private string $items;
    private string $externalLoans;
    private string $readingRounds;

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

        $this->assertSafe($this->libraries);
        $this->assertSafe($this->memberships);
        $this->assertSafe($this->personalLibraryDesignations);
        $this->assertSafe($this->works);
        $this->assertSafe($this->editions);
        $this->assertSafe($this->items);
        $this->assertSafe($this->externalLoans);
        $this->assertSafe($this->readingRounds);
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

    private function assertSafe(string $tableName): void
    {
        if (preg_match('/^[a-zA-Z0-9_]+$/', $tableName) !== 1) {
            throw new InvalidArgumentException("Unsafe database table name.");
        }
    }
}
