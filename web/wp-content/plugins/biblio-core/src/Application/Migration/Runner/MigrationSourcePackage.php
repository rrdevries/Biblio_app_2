<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

final readonly class MigrationSourcePackage
{
    /** @var array<string, MigrationSourceFile> */
    private array $filesByPath;

    /** @param list<MigrationSourceFile> $files */
    public function __construct(
        private string $root,
        array $files,
        private string $manifestDigest
    ) {
        $indexed = [];
        foreach ($files as $file) {
            $path = $file->relativePath();
            if (isset($indexed[$path])) {
                throw new MigrationRunnerFailure(
                    MigrationRunnerReason::SourceDuplicate,
                    "Duplicate logical source path: {$path}."
                );
            }
            $indexed[$path] = $file;
        }
        ksort($indexed, SORT_STRING);
        $this->filesByPath = $indexed;
    }

    public function root(): string { return $this->root; }
    public function manifestDigest(): string { return $this->manifestDigest; }

    /** @return list<MigrationSourceFile> */
    public function files(): array { return array_values($this->filesByPath); }

    public function totalBytes(): int
    {
        return array_sum(array_map(
            static fn (MigrationSourceFile $file): int => $file->byteSize(),
            $this->files()
        ));
    }

    public function read(string $relativePath): string
    {
        $file = $this->filesByPath[$relativePath] ?? null;
        if (!$file instanceof MigrationSourceFile) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::SourceUnsafe,
                "Adapter requested a file outside the source manifest."
            );
        }

        $path = $this->root . DIRECTORY_SEPARATOR
            . str_replace("/", DIRECTORY_SEPARATOR, $relativePath);
        if (is_link($path)) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::SourceUnsafe,
                "A source file became a symlink after profiling."
            );
        }
        $bytes = @file_get_contents($path);

        if (!is_string($bytes)) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::SourceUnreadable,
                "A source file is no longer readable: {$relativePath}."
            );
        }
        if (
            strlen($bytes) !== $file->byteSize()
            || !hash_equals($file->sha256(), hash("sha256", $bytes))
        ) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::SourceChanged,
                "Source bytes changed after profiling: {$relativePath}."
            );
        }

        return $bytes;
    }

    /** @return array{manifest_digest:string,file_count:int,total_bytes:int,files:list<array{relative_path:string,byte_size:int,sha256:string}>} */
    public function toArray(): array
    {
        return [
            "manifest_digest" => $this->manifestDigest,
            "file_count" => count($this->filesByPath),
            "total_bytes" => $this->totalBytes(),
            "files" => array_map(
                static fn (MigrationSourceFile $file): array => $file->toArray(),
                $this->files()
            ),
        ];
    }
}
