<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class Author
{
    public const MAX_NAME_LENGTH = 512;

    private AuthorId $id;
    private string $displayName;
    private AuthorIdentityStatus $identityStatus;
    private AuthorDisplayNameStatus $displayNameStatus;
    private AuthorVersion $version;

    public function __construct(
        AuthorId $id,
        string $displayName,
        AuthorIdentityStatus $identityStatus = AuthorIdentityStatus::Provisional,
        AuthorDisplayNameStatus $displayNameStatus = AuthorDisplayNameStatus::Observed,
        ?AuthorVersion $version = null
    ) {
        $length = preg_match_all('/./us', $displayName);

        if ($length === false) {
            throw new ValidationException("Author name must be valid UTF-8.");
        }
        if (trim($displayName) === "") {
            throw new ValidationException("Author name must not be empty.");
        }
        if ($length > self::MAX_NAME_LENGTH) {
            throw new ValidationException(
                "Author name must not exceed " . self::MAX_NAME_LENGTH . " characters."
            );
        }

        $this->id = $id;
        $this->displayName = $displayName;
        $this->identityStatus = $identityStatus;
        $this->displayNameStatus = $displayNameStatus;
        $this->version = $version ?? AuthorVersion::initial();
    }

    public function id(): AuthorId { return $this->id; }
    public function displayName(): string { return $this->displayName; }
    public function identityStatus(): AuthorIdentityStatus
    {
        return $this->identityStatus;
    }
    public function displayNameStatus(): AuthorDisplayNameStatus
    {
        return $this->displayNameStatus;
    }
    public function version(): AuthorVersion { return $this->version; }
}
