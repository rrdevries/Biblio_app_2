<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Application\Migration\Notes\{
    PrivateNoteMigrationParticipant,
    PrivateNoteMigrationFailure,
    PrivateNotePlan
};
use Biblio\Core\Application\Migration\Runner\{
    MigrationPlanningTarget,
    MigrationRunnerFailure,
    MigrationRunnerReason,
    MigrationSourceInspection,
    MigrationSourceMappingFinding,
    MigrationSourceMappingResult,
    MigrationSourceRecord
};
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Notes\{
    PrivateNoteContent,
    PrivateNoteContentPolicy,
    StrictPrivateNoteContentPolicy
};
use DateTimeImmutable;
use DateTimeZone;

/** Implements only the reviewed CURRENT Note semantics from docs/126. */
final readonly class CurrentV1NoteMapper
{
    public function __construct(
        private CurrentV1ReviewedNoteContract $contract =
            new CurrentV1ReviewedNoteContract(),
        private PrivateNoteContentPolicy $contentPolicy =
            new StrictPrivateNoteContentPolicy()
    ) {
    }

    /**
     * @param array<string, MigrationSourceRecord> $books
     * @param array<string, MigrationSourceRecord> $notes
     * @param array<string, string> $workRepresentatives book source ID => representative Book source ID
     */
    public function map(
        MigrationSourceInspection $inspection,
        MigrationPlanningTarget $target,
        array $books,
        array $notes,
        array $workRepresentatives
    ): MigrationSourceMappingResult {
        if (!hash_equals(
            $this->contract->manifestSha256(),
            $inspection->package()->manifestDigest()
        )) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::SourceChanged,
                "The reviewed CURRENT Note contract does not match the source manifest."
            );
        }

        $records = [];
        $findings = [];
        $targetUserId = new UserId($target->userId());

        foreach ($notes as $note) {
            $payload = $note->payload();
            $bookId = $payload["book_id"] ?? null;
            $raw = $payload["record"] ?? null;

            if (
                preg_match('/^[0-9]{13}_[a-z0-9]{6}$/D', $note->sourceId()) !== 1
            ) {
                $findings[] = $this->finding(
                    $note,
                    MigrationDisposition::Quarantined,
                    CurrentV1NoteMappingReason::InvalidSourceIdentity
                );
                continue;
            }
            $rawKeys = is_array($raw) ? array_keys($raw) : [];
            sort($rawKeys, SORT_STRING);
            if (
                !is_string($bookId)
                || $bookId === ""
                || !is_array($raw)
                || ($raw["id"] ?? null) !== $note->sourceId()
                || $rawKeys !== ["createdAt", "id", "text", "updatedAt"]
            ) {
                $findings[] = $this->finding(
                    $note,
                    MigrationDisposition::Quarantined,
                    CurrentV1NoteMappingReason::InvalidSourceStructure
                );
                continue;
            }

            $book = $books[$this->sourceKey($bookId)] ?? null;
            if (
                !$book instanceof MigrationSourceRecord
                || !$this->belongsToBook($note, $book)
            ) {
                $findings[] = $this->finding(
                    $note,
                    MigrationDisposition::Quarantined,
                    CurrentV1NoteMappingReason::UnmatchedParentBook
                );
                continue;
            }
            if (!array_key_exists($bookId, $workRepresentatives)) {
                $findings[] = $this->finding(
                    $note,
                    MigrationDisposition::Quarantined,
                    CurrentV1NoteMappingReason::UnresolvedWorkIdentity
                );
                continue;
            }

            $content = $this->content($raw["text"] ?? null);
            if ($content["status"] !== "ready") {
                $findings[] = $this->finding(
                    $note,
                    $content["status"] === "preserved"
                        ? MigrationDisposition::PreservedDeferred
                        : MigrationDisposition::Quarantined,
                    $content["status"] === "preserved"
                        ? CurrentV1NoteMappingReason::UnsupportedContent
                        : CurrentV1NoteMappingReason::InvalidContent
                );
                continue;
            }

            $createdAt = $this->timestamp($raw["createdAt"] ?? null);
            $updatedAt = $this->timestamp($raw["updatedAt"] ?? null);
            if (
                !$createdAt instanceof DateTimeImmutable
                || !$updatedAt instanceof DateTimeImmutable
                || $updatedAt < $createdAt
            ) {
                $findings[] = $this->finding(
                    $note,
                    MigrationDisposition::Quarantined,
                    CurrentV1NoteMappingReason::InvalidTimestamp
                );
                continue;
            }

            try {
                $plan = new PrivateNotePlan(
                    $targetUserId,
                    CurrentV1CatalogSourceIds::work($bookId),
                    $content["content"],
                    $createdAt,
                    $updatedAt
                );
            } catch (PrivateNoteMigrationFailure | ValidationException) {
                $findings[] = $this->finding(
                    $note,
                    MigrationDisposition::Quarantined,
                    CurrentV1NoteMappingReason::InvalidTimestamp
                );
                continue;
            }

            $workSourceId = CurrentV1CatalogSourceIds::work($bookId);
            $record = MigrationSourceRecord::typed(
                PrivateNoteMigrationParticipant::SOURCE_TYPE,
                $note->sourceId(),
                $plan,
                [CatalogWorkMigrationParticipant::SOURCE_TYPE . ":" . $workSourceId]
            );
            $records[] = $record;
            $findings[] = $this->finding(
                $note,
                MigrationDisposition::Mapped,
                CurrentV1NoteMappingReason::PrivateNotePlanned,
                [$this->identity($record)]
            );
        }

        $findings[] = new MigrationSourceMappingFinding(
            "v1.note_auxiliary",
            "mapping_contract:" . $this->contract->identity(),
            MigrationDisposition::Mapped,
            CurrentV1NoteMappingReason::MappingContractApplied->value
        );

        return new MigrationSourceMappingResult($records, $findings);
    }

    private function belongsToBook(
        MigrationSourceRecord $note,
        MigrationSourceRecord $book
    ): bool {
        $embedded = $book->payload()["notes"] ?? null;
        if (!is_array($embedded) || !array_is_list($embedded)) {
            return false;
        }

        $matches = array_values(array_filter(
            $embedded,
            static fn (mixed $candidate): bool =>
                is_array($candidate)
                && ($candidate["id"] ?? null) === $note->sourceId()
                && $candidate === ($note->payload()["record"] ?? null)
        ));

        return count($matches) === 1;
    }

    /** @return array{status:string,content?:PrivateNoteContent} */
    private function content(mixed $raw): array
    {
        if (
            !is_string($raw)
            || !mb_check_encoding($raw, "UTF-8")
            || str_contains($raw, "\0")
        ) {
            return ["status" => "invalid"];
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $raw);
        $visible = preg_replace('/[\s\x{00A0}]+/u', '', $normalized);
        if ($visible === null || $visible === "") {
            return ["status" => "invalid"];
        }
        if (str_contains($normalized, "\n")) {
            return ["status" => "preserved"];
        }

        $canonical = "<p>" . htmlspecialchars(
            $normalized,
            ENT_QUOTES | ENT_HTML5,
            "UTF-8",
            true
        ) . "</p>";
        if (strlen($canonical) > StrictPrivateNoteContentPolicy::MAX_BYTES) {
            return ["status" => "preserved"];
        }

        try {
            $content = $this->contentPolicy->sanitize($canonical);
        } catch (ValidationException) {
            return ["status" => "invalid"];
        }
        if ($content->value() !== $canonical) {
            return ["status" => "invalid"];
        }

        return ["status" => "ready", "content" => $content];
    }

    private function timestamp(mixed $raw): ?DateTimeImmutable
    {
        if (
            !is_string($raw)
            || preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{3}Z$/D',
                $raw
            ) !== 1
        ) {
            return null;
        }

        $instant = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.v\Z',
            $raw,
            new DateTimeZone("UTC")
        );
        $errors = DateTimeImmutable::getLastErrors();
        if (
            !$instant instanceof DateTimeImmutable
            || ($errors !== false
                && ($errors["warning_count"] > 0 || $errors["error_count"] > 0))
            || $instant->format('Y-m-d\TH:i:s.v\Z') !== $raw
        ) {
            return null;
        }

        return $instant;
    }

    /** @param list<array{source_type:string,source_id:string}> $plannedIdentities */
    private function finding(
        MigrationSourceRecord $record,
        MigrationDisposition $disposition,
        CurrentV1NoteMappingReason $reason,
        array $plannedIdentities = []
    ): MigrationSourceMappingFinding {
        return new MigrationSourceMappingFinding(
            $record->sourceType(),
            $record->sourceId(),
            $disposition,
            $reason->value,
            $plannedIdentities
        );
    }

    /** @return array{source_type:string,source_id:string} */
    private function identity(MigrationSourceRecord $record): array
    {
        return [
            "source_type" => $record->sourceType(),
            "source_id" => $record->sourceId(),
        ];
    }

    private function sourceKey(string $sourceId): string
    {
        return "source:" . $sourceId;
    }
}
