<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Exception\ValidationException;

/**
 * Immutable implementation of D-MIG-CLASS-MAP-01 for the designated CURRENT
 * source snapshot. This is intentionally not a generic mapping subsystem.
 */
final readonly class CurrentV1ReviewedClassificationContract
{
    public const VERSION = "d-mig-class-map-01.2026-09-16";
    public const MANIFEST_SHA256 =
        "35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67";

    /** @var array<string,string> */
    private const BOOK_TYPES = [
        "Leesboek" => "book_type.reading_book",
        "Kennisboek" => "book_type.knowledge_book",
        "Kookboek" => "book_type.cookbook",
        "Stripboek" => "book_type.comic_book",
        "Studieboek" => "book_type.study_book",
        "Jeugdboek" => "book_type.reading_book",
        "Kinderboek" => "book_type.reading_book",
    ];

    /** @var array<string,string> */
    private const GENRES = [
        "Fantasy" => "genre.fantasy",
        "Sciencefiction" => "genre.science_fiction",
        "Thriller" => "genre.thriller",
    ];

    private string $manifestSha256;

    /**
     * @var array<string,array{
     *   payload_hash:string,
     *   source_book_type_value:string,
     *   source_book_type_review_state:string,
     *   assignment_decision_status:string,
     *   book_type_seed_key:string,
     *   genre_seed_keys:list<string>,
     *   decision_provenance:string
     * }>
     */
    private array $perBookApprovals;

    private string $identity;

    /**
     * No per-Book approvals exist in docs/118, so production passes the empty
     * default. Any reviewed row must bind stable ID and exact payload hash.
     *
     * @param array<array-key,mixed> $perBookApprovals
     */
    public function __construct(
        string $manifestSha256 = self::MANIFEST_SHA256,
        array $perBookApprovals = []
    ) {
        if (preg_match('/^[a-f0-9]{64}$/', $manifestSha256) !== 1) {
            throw new ValidationException(
                "Reviewed classification manifest digest is invalid."
            );
        }
        $normalized = [];
        $expectedKeys = [
            "assignment_decision_status",
            "book_type_seed_key",
            "decision_provenance",
            "genre_seed_keys",
            "payload_hash",
            "source_book_type_review_state",
            "source_book_type_value",
        ];
        foreach ($perBookApprovals as $sourceBookKey => $approval) {
            $sourceBookId = (string) $sourceBookKey;
            if (!is_array($approval) || array_is_list($approval)) {
                throw new ValidationException(
                    "Reviewed per-Book classification approval is invalid."
                );
            }
            $keys = array_keys($approval);
            sort($keys, SORT_STRING);
            if (
                trim($sourceBookId) === ""
                || mb_strlen($sourceBookId) > 191
                || $keys !== $expectedKeys
            ) {
                throw new ValidationException(
                    "Reviewed per-Book classification approval is invalid."
                );
            }
            $payloadHash = $approval["payload_hash"];
            $sourceValue = $approval["source_book_type_value"];
            $reviewState = $approval["source_book_type_review_state"];
            $decisionStatus = $approval["assignment_decision_status"];
            $bookTypeSeed = $approval["book_type_seed_key"];
            $genreSeedValues = $approval["genre_seed_keys"];
            $provenance = $approval["decision_provenance"];
            if (
                !is_string($payloadHash)
                || preg_match('/^[a-f0-9]{64}$/', $payloadHash) !== 1
                || !is_string($sourceValue)
                || $this->bookTypeSeedKey($sourceValue) === null
                || !in_array(
                    $reviewState,
                    ["review", "no_signal"],
                    true
                )
                || !in_array(
                    $decisionStatus,
                    ["EXACT_TARGET", "REVIEW_MAPPING"],
                    true
                )
                || !is_string($bookTypeSeed)
                || !in_array(
                    $bookTypeSeed,
                    array_values(self::BOOK_TYPES),
                    true
                )
                || !is_array($genreSeedValues)
                || !array_is_list($genreSeedValues)
                || !is_string($provenance)
                || trim($provenance) === ""
                || mb_strlen($provenance) > 191
            ) {
                throw new ValidationException(
                    "Reviewed per-Book classification approval is invalid."
                );
            }
            $genreSeeds = [];
            foreach ($genreSeedValues as $seed) {
                if (
                    !is_string($seed)
                    || !in_array($seed, array_values(self::GENRES), true)
                ) {
                    throw new ValidationException(
                        "Reviewed per-Book Genre approval is invalid."
                    );
                }
                $genreSeeds[] = $seed;
            }
            if (count(array_unique($genreSeeds)) !== count($genreSeeds)) {
                throw new ValidationException(
                    "Reviewed per-Book Genre approval contains duplicates."
                );
            }
            sort($genreSeeds, SORT_STRING);
            $normalized[$sourceBookId] = [
                "payload_hash" => $payloadHash,
                "source_book_type_value" => $sourceValue,
                "source_book_type_review_state" => $reviewState,
                "assignment_decision_status" => $decisionStatus,
                "book_type_seed_key" => $bookTypeSeed,
                "genre_seed_keys" => $genreSeeds,
                "decision_provenance" => $provenance,
            ];
        }
        ksort($normalized, SORT_STRING);
        $encoded = json_encode(
            $normalized,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $this->manifestSha256 = $manifestSha256;
        $this->perBookApprovals = $normalized;
        $this->identity = self::VERSION . ":approvals:"
            . substr(hash("sha256", $encoded), 0, 24);
    }

    public function version(): string { return self::VERSION; }
    public function identity(): string { return $this->identity; }
    public function manifestSha256(): string { return $this->manifestSha256; }

    public function bookTypeSeedKey(string $rawValue): ?string
    {
        return self::BOOK_TYPES[$rawValue] ?? null;
    }

    public function genreSeedKey(string $rawValue): ?string
    {
        return self::GENRES[$rawValue] ?? null;
    }

    /** @return array<string,string> */
    public function bookTypes(): array { return self::BOOK_TYPES; }

    /** @return array<string,string> */
    public function genres(): array { return self::GENRES; }

    /**
     * @return null|array{
     *   payload_hash:string,
     *   source_book_type_value:string,
     *   source_book_type_review_state:string,
     *   assignment_decision_status:string,
     *   book_type_seed_key:string,
     *   genre_seed_keys:list<string>,
     *   decision_provenance:string
     * }
     */
    public function perBookApproval(
        string $sourceBookId,
        string $payloadHash,
        string $sourceBookTypeValue,
        ?string $sourceReviewState
    ): ?array {
        $approval = $this->perBookApprovals[$sourceBookId] ?? null;
        if (
            $approval === null
            || !hash_equals($approval["payload_hash"], $payloadHash)
            || $approval["source_book_type_value"] !== $sourceBookTypeValue
            || $approval["source_book_type_review_state"] !== $sourceReviewState
        ) {
            return null;
        }

        return $approval;
    }
}
