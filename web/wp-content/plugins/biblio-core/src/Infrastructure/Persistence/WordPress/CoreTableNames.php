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

        foreach ($this->all() as $tableName) {
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
