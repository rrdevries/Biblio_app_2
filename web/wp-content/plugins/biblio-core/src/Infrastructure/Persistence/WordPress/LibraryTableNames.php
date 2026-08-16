<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use InvalidArgumentException;

final readonly class LibraryTableNames
{
    private string $libraries;
    private string $memberships;
    private string $personalLibraryDesignations;

    public function __construct(string $prefix)
    {
        $this->libraries = $prefix . "biblio_libraries";
        $this->memberships = $prefix . "biblio_library_memberships";
        $this->personalLibraryDesignations = $prefix
            . "biblio_personal_library_designations";

        $this->assertSafe($this->libraries);
        $this->assertSafe($this->memberships);
        $this->assertSafe($this->personalLibraryDesignations);
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

    private function assertSafe(string $tableName): void
    {
        if (preg_match('/^[a-zA-Z0-9_]+$/', $tableName) !== 1) {
            throw new InvalidArgumentException("Unsafe database table name.");
        }
    }
}
