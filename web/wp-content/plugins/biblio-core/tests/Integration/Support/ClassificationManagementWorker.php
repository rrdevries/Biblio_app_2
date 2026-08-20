<?php

declare(strict_types=1);

use Biblio\Core\Catalog\Classification\ClassificationTermConflict;
use Biblio\Core\Catalog\Classification\ClassificationTermName;
use Biblio\Core\Catalog\Classification\LibraryBookTypeId;
use Biblio\Core\Catalog\Classification\LibraryCatalogContextAlreadyExists;
use Biblio\Core\Catalog\Classification\LibraryCatalogContextStale;
use Biblio\Core\Catalog\Classification\LibraryCatalogContextVersion;
use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Catalog\Classification\LibraryGenreId;
use Biblio\Core\Catalog\Classification\LibrarySubjectId;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Library\LibraryId;

if ($argc !== 4) {
    fwrite(STDERR, "Expected payload, ready path and release path.\n");
    exit(2);
}

[, $encodedPayload, $readyPath, $releasePath] = $argv;
$payload = json_decode(
    base64_decode($encodedPayload, true),
    true,
    512,
    JSON_THROW_ON_ERROR
);

require dirname(__DIR__) . "/bootstrap.php";

wp_set_current_user((int) $payload["user_id"]);

if (file_put_contents($readyPath, "ready") === false) {
    fwrite(STDERR, "Could not signal classification worker readiness.");
    exit(1);
}

$deadline = microtime(true) + 15;

while (!is_file($releasePath)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Classification race barrier timed out.");
        exit(1);
    }

    usleep(10_000);
}

try {
    $application = (new ProductionComposition($wpdb))->application();
    $libraryId = new LibraryId($payload["library_id"]);
    $result = match ($payload["operation"]) {
        "context_create" => $application->catalogContextCreation()
            ->createForRepresentedWork(
                $libraryId,
                new WorkId($payload["work_id"]),
                selection($payload)
            ),
        "context_save" => $application->catalogContextManagement()->save(
            $libraryId,
            new WorkId($payload["work_id"]),
            new LibraryCatalogContextVersion($payload["expected_version"]),
            selection($payload),
            (bool) ($payload["confirm_book_type"] ?? false)
        ),
        "genre_create" => $application->genreManagement()->create(
            $libraryId,
            new LibraryGenreId($payload["term_id"]),
            new ClassificationTermName($payload["name"])
        ),
        "genre_rename" => $application->genreManagement()->rename(
            $libraryId,
            new LibraryGenreId($payload["term_id"]),
            new ClassificationTermName($payload["name"])
        ),
        "genre_deactivate" => $application->genreManagement()->deactivate(
            $libraryId,
            new LibraryGenreId($payload["term_id"])
        ),
        "book_deactivate" => $application->bookTypeManagement()->deactivate(
            $libraryId,
            new LibraryBookTypeId($payload["term_id"]),
            (bool) $payload["confirm_last_active"]
        ),
        default => throw new RuntimeException("Unknown worker operation."),
    };
    $version = method_exists($result, "version")
        ? $result->version()->value()
        : null;
    fwrite(STDOUT, json_encode([
        "status" => "success",
        "version" => $version,
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (LibraryCatalogContextStale $exception) {
    fwrite(STDOUT, json_encode([
        "status" => "stale",
        "reason" => $exception->reason()->value,
        "version" => $exception->currentContext()->version()->value(),
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (LibraryCatalogContextAlreadyExists $exception) {
    fwrite(STDOUT, json_encode([
        "status" => "context_conflict",
        "reason" => $exception->reason()->value,
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (ClassificationTermConflict $exception) {
    fwrite(STDOUT, json_encode([
        "status" => "term_conflict",
        "reason" => $exception->reason()->value,
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (ValidationException $exception) {
    fwrite(STDOUT, json_encode([
        "status" => "validation",
        "reason" => $exception->reason()->value,
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ": " . $exception->getMessage());
    exit(1);
}

/** @param array<string, mixed> $payload */
function selection(array $payload): LibraryCatalogSelection
{
    return new LibraryCatalogSelection(
        new LibraryBookTypeId($payload["book_type_id"]),
        array_map(
            static fn (string $id): LibraryGenreId => new LibraryGenreId($id),
            $payload["genre_ids"] ?? []
        ),
        array_map(
            static fn (string $id): LibrarySubjectId =>
                new LibrarySubjectId($id),
            $payload["subject_ids"] ?? []
        )
    );
}
