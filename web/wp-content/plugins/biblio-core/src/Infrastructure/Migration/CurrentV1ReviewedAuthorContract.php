<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Exception\ValidationException;

/** Exact reviewed semantic exceptions for docs/122 and its pinned manifest. */
final readonly class CurrentV1ReviewedAuthorContract
{
    public const VERSION = "current-v1-author-map-v1";
    public const MANIFEST_SHA256 =
        "35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67";

    /** @var array<string,CurrentV1AuthorMappingReason> */
    private array $authorExceptions;
    /** @var array<string,CurrentV1AuthorMappingReason> */
    private array $occurrenceExceptions;

    /**
     * @param array<string,CurrentV1AuthorMappingReason> $authorExceptions
     * @param array<string,CurrentV1AuthorMappingReason> $occurrenceExceptions
     */
    public function __construct(
        private string $manifestSha256 = self::MANIFEST_SHA256,
        array $authorExceptions = [
            "a_51b1da8e9d87" =>
                CurrentV1AuthorMappingReason::UnsupportedAuthorEntityKind,
        ],
        array $occurrenceExceptions = [
            "v1.book/1770132189369/author-occurrence/1" =>
                CurrentV1AuthorMappingReason::UnsupportedAuthorEntityKind,
            "v1.book/1771264674311/author-occurrence/1" =>
                CurrentV1AuthorMappingReason::CompoundAuthorScalar,
            "v1.book/1771265328050/author-occurrence/1" =>
                CurrentV1AuthorMappingReason::InvalidAuthorPlaceholder,
            "v1.book/1771266036446/author-occurrence/1" =>
                CurrentV1AuthorMappingReason::MalformedAuthorScalar,
            "v1.book/1787492463136/author-occurrence/1" =>
                CurrentV1AuthorMappingReason::UnsupportedAuthorEntityKind,
        ]
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $this->manifestSha256) !== 1) {
            throw new ValidationException("Author mapping manifest digest is invalid.");
        }
        foreach ($authorExceptions as $sourceId => $reason) {
            if (
                trim($sourceId) === ""
                || $reason->disposition()
                    !== MigrationDisposition::PreservedDeferred
            ) {
                throw new ValidationException(
                    "Reviewed Author entity exception is invalid."
                );
            }
        }
        foreach ($occurrenceExceptions as $sourceId => $reason) {
            if (
                trim($sourceId) === ""
                || !in_array(
                    $reason->disposition(),
                    [
                        MigrationDisposition::PreservedDeferred,
                        MigrationDisposition::Quarantined,
                    ],
                    true
                )
            ) {
                throw new ValidationException(
                    "Reviewed Author occurrence exception is invalid."
                );
            }
        }
        $this->authorExceptions = $authorExceptions;
        $this->occurrenceExceptions = $occurrenceExceptions;
    }

    public function manifestSha256(): string { return $this->manifestSha256; }
    public function identity(): string
    {
        return self::VERSION . ":" . substr($this->manifestSha256, 0, 24);
    }
    public function authorException(string $sourceId): ?CurrentV1AuthorMappingReason
    {
        return $this->authorExceptions[$sourceId] ?? null;
    }
    public function occurrenceException(
        string $sourceId
    ): ?CurrentV1AuthorMappingReason {
        return $this->occurrenceExceptions[$sourceId] ?? null;
    }
}
